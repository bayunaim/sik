<?php

namespace App\Livewire\Import;

use App\Models\ImportLog;
use App\Models\ImportTemplate;
use App\Services\ImportMahasiswaService;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithFileUploads;

    public int $langkah = 1;

    #[Locked]
    public ?int $logId = null;

    public $berkas = null;
    public array $headers = [];
    public array $mapping = [];
    public bool $tandaiHilang = false;
    public bool $simpanTemplate = true;
    public ?array $pratinjau = null;
    public ?array $hasil = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('import.sia'), 403);
    }

    public function updatedMapping(): void
    {
        $this->pratinjau = null;
    }

    public function updatedTandaiHilang(): void
    {
        $this->pratinjau = null;
    }

    public function unggah(ImportMahasiswaService $svc): void
    {
        abort_unless(auth()->user()->can('import.sia'), 403);

        $this->validate(
            ['berkas' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240']],
            [
                'berkas.required' => 'Pilih file terlebih dahulu.',
                'berkas.mimes' => 'File harus berformat XLSX, XLS, atau CSV.',
                'berkas.max' => 'Ukuran file maksimal 10 MB.',
            ]
        );

        set_time_limit(300);

        $ext = strtolower($this->berkas->getClientOriginalExtension());
        $path = $this->berkas->storeAs('import-gofeeder', Str::uuid().'.'.$ext, 'local');

        try {
            $baca = $svc->baca($path);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            $this->addError('berkas', $e instanceof DomainException ? $e->getMessage() : 'File tidak bisa dibaca. Pastikan formatnya benar.');
            return;
        }

        $log = ImportLog::create([
            'user_id' => auth()->id(),
            'nama_file' => $this->berkas->getClientOriginalName(),
            'file_path' => $path,
        ]);

        $this->logId = $log->id;
        $this->headers = array_values($baca['header']);
        $this->mapping = $svc->tebakPemetaan($this->headers, ImportTemplate::where('nama', 'gofeeder')->value('mapping'));
        $this->pratinjau = null;
        $this->hasil = null;
        $this->berkas = null;
        $this->langkah = 2;
    }

    public function buatPratinjau(ImportMahasiswaService $svc): void
    {
        abort_unless(auth()->user()->can('import.sia'), 403);
        set_time_limit(300);

        $log = $this->logAktif();
        $this->bersihkanPemetaan();

        try {
            $a = $svc->analisis($svc->baca($log->file_path), $this->mapping);
        } catch (DomainException $e) {
            $this->addError('mapping', $e->getMessage());
            return;
        }

        if ($this->simpanTemplate) {
            ImportTemplate::updateOrCreate(['nama' => 'gofeeder'], ['mapping' => $this->mapping, 'user_id' => auth()->id()]);
        }

        $hilang = 0;
        if ($this->tandaiHilang) {
            $diFile = array_flip($a['nim_file']);
            $hilang = \App\Models\Mahasiswa::whereIn('status_akademik', ['Aktif', 'Cuti'])->pluck('nim')
                ->reject(fn ($n) => isset($diFile[$n]))->count();
        }

        $this->pratinjau = [
            'baru' => $a['baru'], 'berubah' => $a['berubah'], 'sama' => $a['sama'],
            'jumlah_error' => count($a['error']),
            'error' => array_slice($a['error'], 0, 100),
            'prodi_baru' => $a['prodi_baru'],
            'hilang' => $hilang,
            'contoh' => array_slice(array_map(fn ($r) => Arr_only($r), $a['rows']), 0, 10),
        ];
        $this->langkah = 3;
    }

    public function proses(ImportMahasiswaService $svc): void
    {
        abort_unless(auth()->user()->can('import.sia'), 403);

        if ($this->pratinjau === null) {
            $this->addError('proses', 'Buat pratinjau terlebih dahulu.');
            return;
        }

        set_time_limit(300);
        $log = $this->logAktif();
        $this->bersihkanPemetaan();

        try {
            $hasil = $svc->simpan($log->id, $svc->baca($log->file_path), $this->mapping, $this->tandaiHilang, auth()->user());
        } catch (DomainException $e) {
            $this->addError('proses', $e->getMessage());
            return;
        } catch (\Throwable $e) {
            report($e);
            $log->update(['status' => 'gagal', 'catatan' => mb_substr($e->getMessage(), 0, 500)]);
            $this->addError('proses', 'Import gagal dan semua perubahan dibatalkan. Detail ada di log aplikasi.');
            return;
        }

        // data pribadi: hapus file sumber setelah selesai
        Storage::disk('local')->delete($log->file_path);
        $log->update(['file_path' => null]);

        $this->hasil = $hasil->only(['id', 'nama_file', 'jumlah_baru', 'jumlah_berubah', 'jumlah_sama', 'jumlah_gagal', 'jumlah_prodi_baru', 'jumlah_hilang', 'error_path']);
        $this->pratinjau = null;
        $this->langkah = 4;
    }

    public function ulang(): void
    {
        if ($this->logId) {
            $log = ImportLog::find($this->logId);
            if ($log && $log->status === 'pratinjau' && $log->file_path) {
                Storage::disk('local')->delete($log->file_path);
                $log->update(['status' => 'gagal', 'file_path' => null, 'catatan' => 'Dibatalkan sebelum disimpan']);
            }
        }

        $this->reset(['langkah', 'logId', 'berkas', 'headers', 'mapping', 'pratinjau', 'hasil', 'tandaiHilang']);
        $this->langkah = 1;
    }

    public function kembali(): void
    {
        $this->langkah = 2;
    }

    protected function logAktif(): ImportLog
    {
        $log = ImportLog::findOrFail($this->logId);
        abort_unless($log->status === 'pratinjau' && $log->file_path && $log->user_id === auth()->id(), 403);

        return $log;
    }

    protected function bersihkanPemetaan(): void
    {
        foreach (ImportMahasiswaService::FIELD as $k => $_) {
            $v = $this->mapping[$k] ?? '';
            $this->mapping[$k] = in_array($v, $this->headers, true) ? $v : '';
        }
    }

    public function render()
    {
        return view('livewire.import.index', [
            'field' => ImportMahasiswaService::FIELD,
            'riwayat' => ImportLog::with('user')->latest()->limit(10)->get(),
        ]);
    }
}

function Arr_only(array $r): array
{
    return ['nim' => $r['nim'], 'nama' => $r['nama'], 'kode_prodi' => $r['kode_prodi'], 'angkatan' => $r['angkatan'], 'jenis' => $r['jenis']];
}
