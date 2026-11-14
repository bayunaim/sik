<?php

namespace App\Jobs;

use App\Contracts\SiaProvider;
use App\Models\ImportLog;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * FR-144: simpan hasil import GoFeeder lewat antrean, supaya file besar tidak menahan request web.
 *
 * Status log: pratinjau -> antre (dikirim dari UI) -> berjalan (worker mulai) -> selesai | gagal.
 * Penyimpanan data sendiri tetap satu transaksi di ImportMahasiswaService::simpan(),
 * jadi bila gagal di tengah jalan semua perubahan dibatalkan.
 */
class ProsesImportMahasiswa implements ShouldQueue
{
    use Queueable;

    /** Tidak diulang otomatis: kegagalan harus terlihat oleh admin, lalu import diulang dari awal. */
    public int $tries = 1;

    /** Detik. DB_QUEUE_RETRY_AFTER harus lebih besar dari ini (lihat config/queue.php). */
    public int $timeout = 600;

    public function __construct(
        public int $logId,
        public array $mapping,
        public bool $tandaiHilang,
        public int $userId,
    ) {
    }

    public function handle(SiaProvider $sia): void
    {
        // Hanya satu worker yang boleh memulai; job ganda atau job lama yang sudah selesai diabaikan.
        $mulai = ImportLog::where('id', $this->logId)->where('status', 'antre')->update(['status' => 'berjalan']);
        if ($mulai === 0) {
            return;
        }

        $log = ImportLog::findOrFail($this->logId);
        $user = User::findOrFail($this->userId);

        $sia->simpan($log->id, $sia->baca($log->file_path), $this->mapping, $this->tandaiHilang, $user);

        $this->hapusBerkas($log->fresh());
    }

    public function failed(Throwable $e): void
    {
        $log = ImportLog::find($this->logId);
        if (! $log || $log->status === 'selesai') {
            return;
        }

        $log->update([
            'status' => 'gagal',
            // Pesan DomainException aman ditampilkan; error lain cukup di log aplikasi dan tabel failed_jobs.
            'catatan' => $e instanceof DomainException
                ? mb_substr($e->getMessage(), 0, 500)
                : 'Terjadi kesalahan sistem. Semua perubahan dibatalkan; detail ada di log aplikasi.',
        ]);

        $this->hapusBerkas($log);
    }

    /** Data pribadi mahasiswa: file sumber tidak disimpan setelah import selesai atau gagal. */
    private function hapusBerkas(?ImportLog $log): void
    {
        if ($log?->file_path) {
            Storage::disk('local')->delete($log->file_path);
            $log->update(['file_path' => null]);
        }
    }
}
