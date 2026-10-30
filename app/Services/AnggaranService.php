<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnggaranService
{
    /**
     * Nilai realisasi per anggaran: id => nominal (string, 2 desimal).
     *  - Kode D (tercapai): SUM penerimaan dana per kode
     *  - Kode K (terpakai): SUM pengeluaran disetujui per kode (diisi di Fase 3)
     */
    public function realisasi(Collection $anggaran): array
    {
        $ids = $anggaran->pluck('id');

        $tercapai = DB::table('penerimaan_dana')
            ->whereNull('deleted_at')
            ->whereIn('anggaran_id', $ids)
            ->selectRaw('anggaran_id, SUM(nominal) as total')
            ->groupBy('anggaran_id')
            ->pluck('total', 'anggaran_id');

        $terpakai = DB::table('pengeluaran')
            ->whereNull('deleted_at')
            ->where('status', 'disetujui')
            ->whereIn('anggaran_id', $ids)
            ->selectRaw('anggaran_id, SUM(nominal) as total')
            ->groupBy('anggaran_id')
            ->pluck('total', 'anggaran_id');

        return $anggaran->mapWithKeys(function ($a) use ($tercapai, $terpakai) {
            $sumber = $a->tipe === 'D' ? $tercapai : $terpakai;

            return [$a->id => (string) BigDecimal::of((string) ($sumber[$a->id] ?? '0'))->toScale(2)];
        })->all();
    }
}
