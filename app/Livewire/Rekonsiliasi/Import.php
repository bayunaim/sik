<?php

namespace App\Livewire\Rekonsiliasi;

use App\Models\ImportTemplate;
use App\Models\MutasiImport;
use App\Models\RekeningBank;
use App\Services\ImportMutasiBankService;
use App\Services\RekonsiliasiService;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class Import extends Component
{
    use WithFileUploads;

    public int $langkah = 1;

    public string $pilihRekening = '';

    #[Locked]
    public ?int $logId = null;

    #[Locked]
    public ?int $rekeningId = null;

    public $berkas = null;
    public array $headers = [];
    public array $mapping = [];
    public bool $simpanTemplate = true;
    public bool $cocokSetelah = true;
    public ?array $pratinjau = null;
    public ?array $hasil = null;
    public ?array $hasilOtomatis = null;

    // batalkan import yang sudah selesai
    public ?int $batalId = null;
    public string $alasanBatal = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);
    }

    public function updatedMapping(): void
    {
        $this->pratinjau = null;
    }

    protected function namaTemplate(): string
    {
        return 'mutasi-bank-'.$this->rekeningId;
    }

    public function unggah(ImportMutasiBankService $svc): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        $this->validate(
            [
                'pilihRekening' => ['required', Rule::exists('rekening_bank', 'id')->where('is_active', true)],
                'berkas' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
            ],
            [
                'pilihRekening.required' => 'Pilih rekening bank terlebih dahulu.',
                'pilihRekening.exists' => 'Rekening tidak ditemukan atau sudah dinonaktifkan.',
                'berkas.required' => 'Pilih file terlebih dahulu.',
                'berkas.mimes' => 'File harus berformat XLSX, XLS, atau CSV.',
                'berkas.max' => 'Ukuran file maksimal 10 MB.',
            ]
        );

        if (! app()->runningInConsole()) {
            set_time_limit(300);
        }

        $ext = strtolower($this->berkas->getClientOriginalExtension());
        $path = $this->berkas->storeAs('import-mutasi', Str::uuid().'.'.$ext, 'local');

        try {
            $baca = $svc->baca($path);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            $this->addError('berkas', $e instanceof DomainException ? $e->getMessage() : 'File tidak bisa dibaca. Pastikan formatnya benar.');
            return;
        }

        $this->rekeningId = (int) $this->pilihRekening;

        $log = MutasiImport::create([
            'rekening_bank_id' => $this->rekeningId,
            'user_id' => auth()->id(),
            'nama_file' => $this->berkas->getClientOriginalName(),
            'file_path' => $path,
        ]);

        $this->logId = $log->id;
        $this->headers = array_values($baca['header']);
        $this->mapping = $svc->tebakPemetaan($this->headers, ImportTemplate::where('nama', $this->namaTemplate())->value('mapping'));
        $this->pratinjau = null;
        $this->hasil = null;
        $this->berkas = null;
        $this->langkah = 2;
    }

    public function buatPratinjau(ImportMutasiBankService $svc): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);
        if (! app()->runningInConsole()) {
            set_time_limit(300);
        }

        $log = $this->logAktif();
        $this->bersihkanPemetaan();

        try {
            $a = $svc->analisis($svc->baca($log->file_path), $this->mapping, $log->rekening_bank_id);
        } catch (DomainException $e) {
            $this->addError('mapping', $e->getMessage());
            return;
        }

        if ($this->simpanTemplate) {
            ImportTemplate::updateOrCreate(['nama' => $this->namaTemplate()], ['mapping' => $this->mapping, 'user_id' => auth()->id()]);
        }

        $this->pratinjau = [
            'baru' => $a['baru'], 'duplikat' => $a['duplikat'],
            'jumlah_error' => count($a['error']),
            'error' => array_slice($a['error'], 0, 100),
            'tanggal_awal' => $a['tanggal_awal'], 'tanggal_akhir' => $a['tanggal_akhir'],
            'total_debit' => $a['total_debit'], 'total_kredit' => $a['total_kredit'],
            'contoh' => array_slice($a['rows'], 0, 10),
        ];
        $this->langkah = 3;
    }

    public function proses(ImportMutasiBankService $svc, RekonsiliasiService $rekon): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        if ($this->pratinjau === null) {
            $this->addError('proses', 'Buat pratinjau terlebih dahulu.');
            return;
        }

        if (! app()->runningInConsole()) {
            set_time_limit(300);
        }
        $log = $this->logAktif();
        $this->bersihkanPemetaan();

        try {
            $hasil = $svc->simpan($log->id, $svc->baca($log->file_path), $this->mapping, auth()->user());
        } catch (DomainException $e) {
            $this->addError('proses', $e->getMessage());
            return;
        } catch (\Throwable $e) {
            report($e);
            $log->update(['status' => 'gagal', 'catatan' => mb_substr($e->getMessage(), 0, 500)]);
            $this->addError('proses', 'Import gagal dan semua perubahan dibatalkan. Detail ada di log aplikasi.');
            return;
        }

        Storage::disk('local')->delete($log->file_path);
        $log->update(['file_path' => null]);

        $this->hasilOtomatis = null;
        if ($this->cocokSetelah && $hasil->jumlah_baru > 0) {
            try {
                $this->hasilOtomatis = $rekon->cocokOtomatis(
                    auth()->user(), $hasil->rekening_bank_id,
                    $hasil->tanggal_awal?->toDateString(), $hasil->tanggal_akhir?->toDateString()
                );
            } catch (\Throwable $e) {
                report($e); // import sudah tersimpan; pencocokan bisa diulang dari halaman Pencocokan
            }
        }

        $this->hasil = $hasil->only(['id', 'nama_file', 'jumlah_baru', 'jumlah_duplikat', 'jumlah_gagal', 'error_path']);
        $this->pratinjau = null;
        $this->langkah = 4;
    }

    public function ulang(): void
    {
        if ($this->logId) {
            $log = MutasiImport::find($this->logId);
            if ($log && $log->status === 'pratinjau' && $log->file_path) {
                Storage::disk('local')->delete($log->file_path);
                $log->update(['status' => 'gagal', 'file_path' => null, 'catatan' => 'Dibatalkan sebelum disimpan']);
            }
        }

        $this->reset(['langkah', 'logId', 'rekeningId', 'pilihRekening', 'berkas', 'headers', 'mapping', 'pratinjau', 'hasil', 'hasilOtomatis']);
        $this->langkah = 1;
    }

    public function kembali(): void
    {
        $this->langkah = 2;
    }

    public function konfirmasiBatal(int $id): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        $this->batalId = $id;
        $this->alasanBatal = '';
        $this->resetValidation();
    }

    public function simpanBatal(ImportMutasiBankService $svc): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        $this->validate(
            ['alasanBatal' => ['required', 'string', 'min:5', 'max:500']],
            ['alasanBatal.required' => 'Alasan pembatalan wajib diisi.', 'alasanBatal.min' => 'Alasan minimal 5 karakter.']
        );

        try {
            $svc->batalkan((int) $this->batalId, $this->alasanBatal, auth()->user());
        } catch (DomainException $e) {
            $this->addError('alasanBatal', $e->getMessage());
            return;
        }

        session()->flash('sukses', 'Import dibatalkan. Mutasinya dihapus dan file yang sama bisa diimpor ulang.');
        $this->reset(['batalId', 'alasanBatal']);
    }

    public function tutupBatal(): void
    {
        $this->reset(['batalId', 'alasanBatal']);
        $this->resetValidation();
    }

    protected function logAktif(): MutasiImport
    {
        $log = MutasiImport::findOrFail($this->logId);
        abort_unless(
            $log->status === 'pratinjau' && $log->file_path && $log->user_id === auth()->id()
                && $log->rekening_bank_id === $this->rekeningId,
            403
        );

        return $log;
    }

    protected function bersihkanPemetaan(): void
    {
        foreach (ImportMutasiBankService::FIELD as $k => $_) {
            $v = $this->mapping[$k] ?? '';
            $this->mapping[$k] = in_array($v, $this->headers, true) ? $v : '';
        }
    }

    public function render()
    {
        return view('livewire.rekonsiliasi.import', [
            'field' => ImportMutasiBankService::FIELD,
            'daftarRekening' => RekeningBank::where('is_active', true)->orderBy('nama_bank')->get(),
            'rekeningDipakai' => $this->rekeningId ? RekeningBank::find($this->rekeningId) : null,
            'riwayat' => MutasiImport::with(['user', 'rekening'])->latest()->limit(10)->get(),
        ]);
    }
}
