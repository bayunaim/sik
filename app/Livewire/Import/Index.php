<?php

namespace App\Livewire\Import;

use App\Jobs\ProsesImportMahasiswa;
use App\Models\ImportLog;
use App\Models\ImportTemplate;
use App\Contracts\SiaProvider;
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
    public ?string $statusProses = null;   // antre | berjalan | selesai | gagal
    public ?string $catatanGagal = null;

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

    public function unggah(SiaProvider $svc): void
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

        if (! app()->runningInConsole()) {
            set_time_limit(300);
        }

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

    public function buatPratinjau(SiaProvider $svc): void
    {
        abort_unless(auth()->user()->can('import.sia'), 403);
        if (! app()->runningInConsole()) {
            set_time_limit(300);
        }

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

    /** FR-144: tidak menyimpan di request ini, hanya mengantrekan job lalu memantau statusnya. */
    public function proses(): void
    {
        abort_unless(auth()->user()->can('import.sia'), 403);

        if ($this->pratinjau === null) {
            $this->addError('proses', 'Buat pratinjau terlebih dahulu.');
            return;
        }

        $log = $this->logAktif();
        $this->bersihkanPemetaan();

        // Ambil giliran secara atomik: klik ganda atau dua tab tidak bisa mengantrekan import yang sama dua kali.
        $diambil = ImportLog::where('id', $log->id)->where('status', 'pratinjau')->update(['status' => 'antre']);
        if ($diambil === 0) {
            $this->addError('proses', 'Import ini sudah diproses.');
            return;
        }
        $log->update(['mapping' => $this->mapping]);

        try {
            ProsesImportMahasiswa::dispatch($log->id, $this->mapping, $this->tandaiHilang, auth()->id());
        } catch (\Throwable $e) {
            report($e);

            if (ImportLog::whereKey($log->id)->value('status') === 'antre') {
                // Job tidak pernah masuk antrean: kembalikan supaya admin bisa mencoba lagi.
                ImportLog::whereKey($log->id)->update(['status' => 'pratinjau']);
                $this->addError('proses', 'Import tidak bisa diantrekan. Coba lagi atau hubungi SuperAdmin.');
                return;
            }
            // Status lain (mis. QUEUE_CONNECTION=sync): job sudah mencatat hasilnya sendiri.
        }

        $this->pratinjau = null;
        $this->langkah = 4;
        $this->periksaStatus();
    }

    /** Dipanggil wire:poll selama import masih antre atau berjalan. */
    public function periksaStatus(): void
    {
        if (! $this->logId) {
            return;
        }

        $log = ImportLog::where('user_id', auth()->id())->find($this->logId);
        if (! $log) {
            return;
        }

        $this->statusProses = $log->status;

        if ($log->status === 'selesai') {
            $this->hasil = $log->only(['id', 'nama_file', 'jumlah_baru', 'jumlah_berubah', 'jumlah_sama', 'jumlah_gagal', 'jumlah_prodi_baru', 'jumlah_hilang', 'error_path']);
        } elseif ($log->status === 'gagal') {
            $this->catatanGagal = $log->catatan;
        }
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

        $this->reset(['langkah', 'logId', 'berkas', 'headers', 'mapping', 'pratinjau', 'hasil', 'tandaiHilang', 'statusProses', 'catatanGagal']);
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
        foreach (app(SiaProvider::class)->field() as $k => $_) {
            $v = $this->mapping[$k] ?? '';
            $this->mapping[$k] = in_array($v, $this->headers, true) ? $v : '';
        }
    }

    public function render()
    {
        return view('livewire.import.index', [
            'field' => app(SiaProvider::class)->field(),
            'riwayat' => ImportLog::with('user')->latest()->limit(10)->get(),
        ]);
    }
}

function Arr_only(array $r): array
{
    return ['nim' => $r['nim'], 'nama' => $r['nama'], 'kode_prodi' => $r['kode_prodi'], 'angkatan' => $r['angkatan'], 'jenis' => $r['jenis']];
}
