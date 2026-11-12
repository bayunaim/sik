<?php

namespace App\Services;

use App\Models\Tagihan;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-102: data grafik dashboard. Hanya menghitung; tampilan ada di view dashboard.
 * Angka uang dijumlah dengan BigDecimal. Float hanya dipakai untuk rasio tinggi/lebar batang.
 */
class DashboardGrafikService
{
    private const BULAN = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /**
     * Penerimaan dana per bulan untuk $jumlahBulan bulan terakhir (bulan berjalan di ujung kanan).
     * Sumber sama dengan laporan Capaian Anggaran: penerimaan_dana yang tidak dibatalkan.
     */
    public function trenPenerimaan(int $jumlahBulan = 12): array
    {
        $jumlahBulan = max(1, min(24, $jumlahBulan));
        $akhir = now()->startOfMonth();
        $awal = $akhir->copy()->subMonths($jumlahBulan - 1);

        $bulan = [];
        for ($i = 0; $i < $jumlahBulan; $i++) {
            $m = $awal->copy()->addMonths($i);
            $bulan[$m->format('Y-m')] = [
                'kunci' => $m->format('Y-m'),
                // tahun ditulis di bulan pertama dan setiap Januari agar batas tahun terlihat
                'label' => self::BULAN[$m->month].($i === 0 || $m->month === 1 ? ' '.$m->format('y') : ''),
                'berjalan' => $i === $jumlahBulan - 1,
                'jumlah' => BigDecimal::zero(),
            ];
        }

        // dikelompokkan per tanggal di SQL (maks. ~730 baris), per bulan di PHP: portabel MySQL/PostgreSQL/SQLite
        $rows = DB::table('penerimaan_dana')
            ->whereNull('deleted_at')
            ->whereBetween('tanggal', [$awal->toDateString(), $akhir->copy()->endOfMonth()->toDateString()])
            ->selectRaw('tanggal, SUM(nominal) as total')
            ->groupBy('tanggal')
            ->get();

        foreach ($rows as $r) {
            $kunci = Carbon::parse($r->tanggal)->format('Y-m');
            if (isset($bulan[$kunci])) {
                $bulan[$kunci]['jumlah'] = $bulan[$kunci]['jumlah']->plus($r->total);
            }
        }

        $total = BigDecimal::zero();
        $maks = BigDecimal::zero();
        foreach ($bulan as $b) {
            $total = $total->plus($b['jumlah']);
            $maks = $b['jumlah']->isGreaterThan($maks) ? $b['jumlah'] : $maks;
        }

        $hasil = array_map(function (array $b) use ($maks) {
            $ada = $b['jumlah']->isGreaterThan(0);

            return [
                'kunci' => $b['kunci'],
                'label' => $b['label'],
                'berjalan' => $b['berjalan'],
                'nilai' => (string) $b['jumlah']->toScale(2),
                'singkat' => $ada ? self::singkat((string) $b['jumlah']) : '',
                // batang terendah tetap terlihat (2%) agar bulan dengan nilai kecil tidak tampak kosong
                'persen' => $ada ? max(2.0, round((float) (string) $b['jumlah'] / (float) (string) $maks * 100, 1)) : 0.0,
            ];
        }, array_values($bulan));

        return [
            'bulan' => $hasil,
            'total' => (string) $total->toScale(2),
            'kosong' => $total->isZero(),
        ];
    }

    /**
     * Komposisi tunggakan per program studi, terbesar dulu. Aturan sama dengan laporan Tunggakan:
     * tagihan belum lunas dan tidak dibatalkan. Prodi di luar $maks - 1 teratas digabung jadi "Lainnya".
     */
    public function tunggakanPerProdi(int $maks = 8): array
    {
        $maks = max(2, $maks);

        $rows = DB::table('tagihan as t')
            ->join('mahasiswa as m', 'm.id', '=', 't.mahasiswa_id')
            ->join('program_studi as p', 'p.id', '=', 'm.program_studi_id')
            ->whereNull('t.deleted_at')
            ->where('t.status', '!=', Tagihan::LUNAS)
            ->whereRaw('t.nominal > t.terbayar')
            ->groupBy('p.id', 'p.kode', 'p.nama')
            ->selectRaw('p.kode, p.nama, COUNT(*) as jumlah, SUM(t.nominal - t.terbayar) as total')
            ->orderByDesc('total')->orderBy('p.kode')
            ->get();

        $total = BigDecimal::zero();
        foreach ($rows as $r) {
            $total = $total->plus($r->total);
        }

        $item = $rows->map(fn ($r) => [
            'kode' => $r->kode, 'nama' => $r->nama, 'jumlah' => (int) $r->jumlah,
            'nilai' => (string) BigDecimal::of($r->total)->toScale(2),
        ]);

        if ($item->count() > $maks) {
            $sisa = $rows->slice($maks - 1);
            $nilaiSisa = BigDecimal::zero();
            foreach ($sisa as $r) {
                $nilaiSisa = $nilaiSisa->plus($r->total);
            }

            $item = $item->take($maks - 1)->push([
                'kode' => 'Lainnya', 'nama' => $sisa->count().' prodi lain',
                'jumlah' => (int) $sisa->sum('jumlah'),
                'nilai' => (string) $nilaiSisa->toScale(2),
            ]);
        }

        $item = $item->map(fn (array $i) => $i + [
            'persen' => $total->isZero() ? 0.0 : round((float) $i['nilai'] / (float) (string) $total * 100, 1),
        ])->values()->all();

        return [
            'item' => $item,
            'total' => (string) $total->toScale(2),
            'jumlah_tagihan' => array_sum(array_column($item, 'jumlah')),
            'kosong' => $total->isZero(),
        ];
    }

    /** Angka ringkas untuk label batang: 1,5 jt / 2 M / 1,3 T. Hanya untuk tampilan. */
    public static function singkat(string $nilai): string
    {
        $n = (float) $nilai;
        $desimal = fn (float $x): string => rtrim(rtrim(number_format($x, 1, ',', '.'), '0'), ',');

        return match (true) {
            $n >= 1e12 => $desimal($n / 1e12).' T',
            $n >= 1e9 => $desimal($n / 1e9).' M',
            $n >= 1e6 => $desimal($n / 1e6).' jt',
            $n >= 1e3 => $desimal($n / 1e3).' rb',
            default => number_format($n, 0, ',', '.'),
        };
    }
}
