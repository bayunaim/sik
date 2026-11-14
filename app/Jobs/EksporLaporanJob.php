<?php

namespace App\Jobs;

use App\Exports\LaporanCsv;
use App\Exports\LaporanExport;
use App\Models\User;
use App\Notifications\NotifikasiAplikasi;
use App\Services\LaporanService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelTipe;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Ekspor laporan besar di latar belakang. Hasil disimpan di storage/app/private/ekspor-laporan/{user_id}/
 * dan pemiliknya diberi notifikasi (lonceng) berisi tautan unduh.
 */
class EksporLaporanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** $filter sudah dinormalisasi oleh LaporanService::normalisasi. $nama tanpa ekstensi. */
    public function __construct(
        public string $jenis,
        public string $format,
        public array $filter,
        public int $userId,
        public string $nama,
    ) {
    }

    public function handle(LaporanService $service): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $lap = $service->susun($this->jenis, $this->filter);
        $berkas = "{$this->nama}.{$this->format}";
        $path = "ekspor-laporan/{$this->userId}/{$berkas}";
        $disk = Storage::disk('local');
        $disk->makeDirectory("ekspor-laporan/{$this->userId}");

        match ($this->format) {
            'xlsx' => Excel::store(new LaporanExport($lap), $path, 'local'),
            'csv' => Excel::store(new LaporanCsv($lap), $path, 'local', ExcelTipe::CSV),
            'pdf' => $disk->put($path, Pdf::loadView('pdf.laporan', ['lap' => $lap, 'oleh' => $user->name])
                ->setPaper('a4', $lap['orientasi'])->output()),
        };

        $hari = (int) config('laporan.simpan_hari', 2);
        $user->notify(new NotifikasiAplikasi(
            'ekspor',
            'Ekspor laporan siap',
            "Laporan {$this->jenis} (.{$this->format}) siap diunduh. Tersedia {$hari} hari.",
            route('laporan.unduh', ['berkas' => $berkas]),
            'ekspor:'.$this->nama,
        ));
    }

    public function failed(Throwable $e): void
    {
        report($e);

        User::find($this->userId)?->notify(new NotifikasiAplikasi(
            'ekspor',
            'Ekspor laporan gagal',
            "Laporan {$this->jenis} (.{$this->format}) gagal dibuat. Coba lagi atau persempit filter.",
            route('laporan.'.$this->jenis),
            'ekspor-gagal:'.$this->nama,
        ));
    }
}
