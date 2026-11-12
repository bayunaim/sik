<?php

namespace App\Services;

use App\Models\Mahasiswa;
use App\Models\Tagihan;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

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

    /**
     * Status keuangan satu mahasiswa untuk SIA (FR-152). Periode berjalan sama dengan laporan status keuangan:
     * semester dan tahun ajaran pada tagihan terbaru. null jika NIM tidak dikenal.
     *
     * @return array<string,mixed>|null
     */
    public function untukNim(string $nim): ?array
    {
        $m = Mahasiswa::where('nim', $nim)->first();
        if (! $m) {
            return null;
        }

        $terbaru = DB::table('tagihan')->whereNull('deleted_at')
            ->orderByDesc('tanggal_tagihan')->orderByDesc('id')->first(['semester', 'tahun_ajaran']);

        $total = BigDecimal::zero();
        $bayar = BigDecimal::zero();
        $tunggakan = BigDecimal::zero();
        $tunggakanSemua = BigDecimal::zero();
        $nPeriode = 0;
        $nBelum = 0;

        foreach (Tagihan::where('mahasiswa_id', $m->id)->get(['nominal', 'terbayar', 'semester', 'tahun_ajaran']) as $t) {
            $nominal = BigDecimal::of($t->nominal);
            $terbayar = BigDecimal::of($t->terbayar);
            $sisa = $nominal->isGreaterThan($terbayar) ? $nominal->minus($terbayar) : BigDecimal::zero();
            $tunggakanSemua = $tunggakanSemua->plus($sisa);

            if ($terbaru && (string) $t->semester === (string) $terbaru->semester && $t->tahun_ajaran === $terbaru->tahun_ajaran) {
                $nPeriode++;
                $total = $total->plus($nominal);
                $bayar = $bayar->plus($terbayar);
                $tunggakan = $tunggakan->plus($sisa);
                if (! $sisa->isZero()) {
                    $nBelum++;
                }
            }
        }

        return [
            'nim' => $m->nim,
            'nama' => $m->nama,
            'status_akademik' => $m->status_akademik,
            'periode' => $terbaru ? ['semester' => (string) $terbaru->semester, 'tahun_ajaran' => $terbaru->tahun_ajaran] : null,
            'total_tagihan' => (string) $total->toScale(2),
            'terbayar' => (string) $bayar->toScale(2),
            'tunggakan' => (string) $tunggakan->toScale(2),
            'tunggakan_semua_periode' => (string) $tunggakanSemua->toScale(2),
            'status_lunas' => $nPeriode === 0 ? 'Tidak ada tagihan' : ($nBelum === 0 ? 'Lunas' : 'Belum lunas'),
            // Kebijakan blokir KRS/ujian masih pertanyaan terbuka (PRD 17 no. 6): sementara, boleh bila tidak ada tunggakan periode berjalan.
            'boleh' => $nBelum === 0,
        ];
    }
}
