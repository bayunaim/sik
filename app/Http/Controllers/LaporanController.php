<?php

namespace App\Http\Controllers;

use App\Exports\LaporanExport;
use App\Services\LaporanService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class LaporanController extends Controller
{
    private const BATAS_PDF = 3000;

    public function ekspor(Request $request, LaporanService $service, string $jenis, string $format)
    {
        $user = $request->user();
        abort_unless($user->can('laporan.ekspor'), 403);
        abort_unless(in_array($jenis, LaporanService::JENIS, true) && in_array($format, ['pdf', 'xlsx'], true), 404);

        $filter = $service->normalisasi($jenis, $request->query());
        $lap = $service->susun($jenis, $filter);
        $nama = "laporan-{$jenis}-".now()->format('Ymd-His');

        // laporan memuat data sensitif: catat siapa mengekspor apa
        activity('laporan')
            ->causedBy($user)
            ->withProperties(['jenis' => $jenis, 'format' => $format, 'filter' => $filter, 'baris' => $lap['baris']->count()])
            ->log("ekspor laporan {$jenis} ({$format})");

        if ($format === 'xlsx') {
            return Excel::download(new LaporanExport($lap), "{$nama}.xlsx");
        }

        abort_if(
            $lap['baris']->count() > self::BATAS_PDF,
            422,
            'Data terlalu banyak untuk PDF (maks. '.self::BATAS_PDF.' baris). Persempit filter atau ekspor ke Excel.'
        );

        set_time_limit(120);

        return Pdf::loadView('pdf.laporan', ['lap' => $lap, 'oleh' => $user->name])
            ->setPaper('a4', $lap['orientasi'])
            ->stream("{$nama}.pdf");
    }
}
