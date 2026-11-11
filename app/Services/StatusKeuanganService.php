<?php

namespace App\Services;

use App\Models\Mahasiswa;
use App\Models\Tagihan;
use Brick\Math\BigDecimal;

/**
 * Status keuangan satu mahasiswa. Dipakai surat keterangan lunas (FR-112, FR-151).
 * Tagihan yang dibatalkan sudah soft-delete, jadi tidak ikut terhitung.
 */
class StatusKeuanganService
{
    /** @return array{jumlah:int, total:string, terbayar:string, tunggakan:string, belum_lunas:int, lunas:bool} */
    public function ringkas(Mahasiswa $m): array
    {
        $total = BigDecimal::zero();
        $bayar = BigDecimal::zero();
        $tunggakan = BigDecimal::zero();
        $belum = 0;
        $jumlah = 0;

        foreach (Tagihan::where('mahasiswa_id', $m->id)->get(['nominal', 'terbayar']) as $t) {
            $jumlah++;
            $nominal = BigDecimal::of($t->nominal);
            $terbayar = BigDecimal::of($t->terbayar);
            $total = $total->plus($nominal);
            $bayar = $bayar->plus($terbayar);
            if ($nominal->isGreaterThan($terbayar)) {
                $belum++;
                $tunggakan = $tunggakan->plus($nominal->minus($terbayar));
            }
        }

        return [
            'jumlah' => $jumlah,
            'total' => (string) $total->toScale(2),
            'terbayar' => (string) $bayar->toScale(2),
            'tunggakan' => (string) $tunggakan->toScale(2),
            'belum_lunas' => $belum,
            // lunas = punya tagihan dan semuanya terbayar penuh (tagihan pending belum dihitung terbayar)
            'lunas' => $jumlah > 0 && $belum === 0,
        ];
    }

    /** Pesan alasan jika surat belum bisa diterbitkan, null jika boleh. */
    public function alasanTidakBisaSuratLunas(Mahasiswa $m): ?string
    {
        $r = $this->ringkas($m);
        if ($r['jumlah'] === 0) {
            return 'Belum ada tagihan atas nama mahasiswa ini.';
        }
        if ($r['belum_lunas'] > 0) {
            return "Masih ada {$r['belum_lunas']} tagihan yang belum lunas.";
        }

        return null;
    }
}
