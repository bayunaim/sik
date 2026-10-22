<?php

namespace App\Services;

use Illuminate\Support\Collection;

class AnggaranService
{
    /**
     * Nilai realisasi per anggaran: id => nominal (string, 2 desimal).
     *  - Kode D (tercapai): SUM pembayaran terverifikasi per kode  -> diisi saat modul pembayaran jadi
     *  - Kode K (terpakai): SUM pengeluaran disetujui per kode     -> diisi di Fase 3
     */
    public function realisasi(Collection $anggaran): array
    {
        return $anggaran->mapWithKeys(fn ($a) => [$a->id => '0.00'])->all();
    }
}
