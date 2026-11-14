<?php

namespace App\Http\Controllers;

use App\Exports\LaporanExport;
use App\Jobs\EksporLaporanJob;
use App\Services\LaporanService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class LaporanController extends Controller
{
    private const BATAS_PDF = 3000;

    public function ekspor(Request $request, LaporanService $service, string $jenis, string $format)
    {
        $user = $request->user();
        abort_unless($user->can('laporan.ekspor'), 403);
        abort_unless(in_array($jenis, LaporanService::JENIS, true) && in_array($format, ['pdf', 'xlsx', 'csv'], true), 404);

        $filter = $service->normalisasi($jenis, $request->query());
        $lap = $service->susun($jenis, $filter);
        $nama = "laporan-{$jenis}-".now()->format('Ymd-His');

        // laporan memuat data sensitif: catat siapa mengekspor apa
        activity('laporan')
            ->causedBy($user)
            ->withProperties(['jenis' => $jenis, 'format' => $format, 'filter' => $filter, 'baris' => $lap['baris']->count()])
            ->log("ekspor laporan {$jenis} ({$format})");

        $jumlah = $lap['baris']->count();

        if ($format === 'pdf') {
            abort_if(
                $jumlah > self::BATAS_PDF,
                422,
                'Data terlalu banyak untuk PDF (maks. '.self::BATAS_PDF.' baris). Persempit filter atau ekspor ke Excel.'
            );
        }

        // ekspor besar diproses di latar belakang; hasilnya dikabarkan lewat notifikasi
        if ($jumlah > (int) config('laporan.batas_antrean', 1000)) {
            EksporLaporanJob::dispatch($jenis, $format, $filter, $user->id, $nama.'-'.Str::random(6));

            return back()->with('sukses', 'Laporan besar sedang diproses. Tautan unduh akan muncul di notifikasi (ikon lonceng).');
        }

        if ($format === 'xlsx') {
            return Excel::download(new LaporanExport($lap), "{$nama}.xlsx");
        }

        if ($format === 'csv') {
            return Excel::download(new \App\Exports\LaporanCsv($lap), "{$nama}.csv", \Maatwebsite\Excel\Excel::CSV);
        }

        if (! app()->runningInConsole()) {
            set_time_limit(120);
        }

        return Pdf::loadView('pdf.laporan', ['lap' => $lap, 'oleh' => $user->name])
            ->setPaper('a4', $lap['orientasi'])
            ->stream("{$nama}.pdf");
    }

    /** Unduh hasil ekspor antrean. Hanya pemiliknya yang bisa membuka berkas. */
    public function unduh(Request $request, string $berkas)
    {
        abort_unless($request->user()->can('laporan.ekspor'), 403);
        abort_unless(preg_match('/^laporan-[a-z-]+-\d{8}-\d{6}-[A-Za-z0-9]{6}\.(pdf|xlsx|csv)$/', $berkas) === 1, 404);

        $path = 'ekspor-laporan/'.$request->user()->id.'/'.$berkas;
        abort_unless(Storage::disk('local')->exists($path), 404, 'Berkas sudah dihapus atau tidak ditemukan. Ekspor ulang laporannya.');

        return Storage::disk('local')->download($path);
    }
}
