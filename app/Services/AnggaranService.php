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
        $tercapai = DB::table('penerimaan_dana')
            ->whereNull('deleted_at')
            ->whereIn('anggaran_id', $anggaran->pluck('id'))
            ->selectRaw('anggaran_id, SUM(nominal) as total')
            ->groupBy('anggaran_id')
            ->pluck('total', 'anggaran_id');

        return $anggaran->mapWithKeys(fn ($a) => [
            $a->id => $a->tipe === 'D'
                ? (string) BigDecimal::of((string) ($tercapai[$a->id] ?? '0'))->toScale(2)
                : '0.00',
        ])->all();
    }
}
