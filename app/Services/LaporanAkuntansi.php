<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Laporan akuntansi yang dihitung langsung dari jurnal (FR-093, FR-094, FR-095):
 * Laba Rugi, Arus Kas, Buku Besar, Neraca Saldo. Semua angka berasal dari jurnal_detail,
 * jadi otomatis ikut jurnal pembalik (pembatalan transaksi).
 */
class LaporanAkuntansi
{
    public const KODE_KAS = [JurnalService::AKUN_KAS, JurnalService::AKUN_BANK];

    // ---------------------------------------------------------------- Laba Rugi (FR-093)

    public function labaRugi(array $f): array
    {
        $rows = DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'd.chart_of_account_id')
            ->whereBetween('j.tanggal', [$f['dari'], $f['sampai']])
            ->whereIn('a.tipe', ['pendapatan', 'beban'])
            ->groupBy('a.id', 'a.kode', 'a.nama', 'a.tipe')
            ->orderBy('a.kode')
            ->selectRaw('a.kode, a.nama, a.tipe, SUM(d.debit) as debit, SUM(d.kredit) as kredit')
            ->get();

        $baris = collect();
        $total = ['pendapatan' => BigDecimal::zero(), 'beban' => BigDecimal::zero()];

        foreach (['pendapatan' => 'Pendapatan', 'beban' => 'Beban'] as $tipe => $label) {
            foreach ($rows->where('tipe', $tipe) as $r) {
                $jumlah = $tipe === 'pendapatan'
                    ? BigDecimal::of($r->kredit)->minus($r->debit)
                    : BigDecimal::of($r->debit)->minus($r->kredit);
                $total[$tipe] = $total[$tipe]->plus($jumlah);

                $baris->push([
                    'kelompok' => $label,
                    'kode' => $r->kode,
                    'nama' => $r->nama,
                    'jumlah' => (string) $jumlah->toScale(2),
                ]);
            }
        }

        $selisih = $total['pendapatan']->minus($total['beban']);

        return [
            'judul' => 'Laporan Laba Rugi',
            'periode' => 'Periode: '.$this->tgl($f['dari']).' s.d. '.$this->tgl($f['sampai']),
            'orientasi' => 'portrait',
            'kolom' => [
                $this->kol('kelompok', 'Kelompok'),
                $this->kol('kode', 'Kode'),
                $this->kol('nama', 'Nama Akun'),
                $this->kol('jumlah', 'Jumlah (Rp)', 'uang'),
            ],
            'baris' => $baris->values(),
            'total' => [
                ['label' => 'TOTAL PENDAPATAN', 'nilai' => ['jumlah' => (string) $total['pendapatan']->toScale(2)]],
                ['label' => 'TOTAL BEBAN', 'nilai' => ['jumlah' => (string) $total['beban']->toScale(2)]],
                ['label' => $selisih->isNegative() ? 'RUGI BERSIH' : 'LABA BERSIH', 'nilai' => ['jumlah' => (string) $selisih->toScale(2)]],
            ],
            'info' => 'Dihitung dari jurnal pada rentang tanggal terpilih. Pendapatan diakui saat pembayaran diverifikasi atau penerimaan dicatat, beban saat pengeluaran disetujui. Transaksi yang dibatalkan sudah dikoreksi oleh jurnal pembalik.',
        ];
    }

    // ---------------------------------------------------------------- Arus Kas (FR-094)

    /** Kelompok aktivitas berdasarkan akun lawan dari kas/bank. */
    public static function kelompokKas(string $tipe, string $kode): string
    {
        if (in_array($tipe, ['pendapatan', 'beban'], true)
            || str_starts_with($kode, '12')      // piutang
            || str_starts_with($kode, '21')) {   // pendapatan diterima dimuka
            return 'Aktivitas Operasional';
        }

        return $tipe === 'aset' ? 'Aktivitas Investasi' : 'Aktivitas Pendanaan';
    }

    public function arusKas(array $f): array
    {
        $kasIds = ChartOfAccount::whereIn('kode', self::KODE_KAS)->pluck('id')->all();

        // Dampak ke kas dicatat pada akun lawannya: kredit pada akun lawan = kas masuk, debit = kas keluar.
        // Jurnal yang hanya memindahkan antar kas/bank tidak punya akun lawan, jadi tidak berpengaruh.
        $rows = DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'd.chart_of_account_id')
            ->whereBetween('j.tanggal', [$f['dari'], $f['sampai']])
            ->whereNotIn('d.chart_of_account_id', $kasIds)
            ->whereIn('d.jurnal_id', fn ($q) => $q->select('jurnal_id')->from('jurnal_detail')->whereIn('chart_of_account_id', $kasIds))
            ->groupBy('a.id', 'a.kode', 'a.nama', 'a.tipe')
            ->orderBy('a.kode')
            ->selectRaw('a.kode, a.nama, a.tipe, SUM(d.kredit - d.debit) as efek')
            ->get();

        $urutan = ['Aktivitas Operasional', 'Aktivitas Investasi', 'Aktivitas Pendanaan'];
        $perKelompok = array_fill_keys($urutan, BigDecimal::zero());
        $masukTotal = BigDecimal::zero();
        $keluarTotal = BigDecimal::zero();
        $susun = [];

        foreach ($rows as $r) {
            $efek = BigDecimal::of($r->efek);
            if ($efek->isZero()) {
                continue;
            }
            $kel = self::kelompokKas($r->tipe, $r->kode);
            $perKelompok[$kel] = $perKelompok[$kel]->plus($efek);
            $masuk = $efek->isPositive() ? $efek : BigDecimal::zero();
            $keluar = $efek->isNegative() ? $efek->negated() : BigDecimal::zero();
            $masukTotal = $masukTotal->plus($masuk);
            $keluarTotal = $keluarTotal->plus($keluar);

            $susun[$kel][] = [
                'kelompok' => $kel,
                'kode' => $r->kode,
                'nama' => $r->nama,
                'masuk' => (string) $masuk->toScale(2),
                'keluar' => (string) $keluar->toScale(2),
                'neto' => (string) $efek->toScale(2),
            ];
        }

        $baris = collect();
        foreach ($urutan as $kel) {
            foreach ($susun[$kel] ?? [] as $b) {
                $baris->push($b);
            }
        }

        $awal = $this->saldoKas($kasIds, '<', $f['dari']);
        $naik = $masukTotal->minus($keluarTotal);
        $akhir = $awal->plus($naik);
        $akhirSebenarnya = $this->saldoKas($kasIds, '<=', $f['sampai']);

        $total = [];
        foreach ($urutan as $kel) {
            $total[] = ['label' => 'Kas bersih dari '.mb_strtolower($kel), 'nilai' => ['neto' => (string) $perKelompok[$kel]->toScale(2)]];
        }
        $total[] = ['label' => 'KENAIKAN (PENURUNAN) KAS BERSIH', 'nilai' => [
            'masuk' => (string) $masukTotal->toScale(2), 'keluar' => (string) $keluarTotal->toScale(2), 'neto' => (string) $naik->toScale(2),
        ]];
        $total[] = ['label' => 'SALDO KAS AWAL PERIODE', 'nilai' => ['neto' => (string) $awal->toScale(2)]];
        $total[] = ['label' => 'SALDO KAS AKHIR PERIODE', 'nilai' => ['neto' => (string) $akhir->toScale(2)]];

        return [
            'judul' => 'Laporan Arus Kas',
            'periode' => 'Periode: '.$this->tgl($f['dari']).' s.d. '.$this->tgl($f['sampai']),
            'orientasi' => 'portrait',
            'kolom' => [
                $this->kol('kelompok', 'Aktivitas'),
                $this->kol('kode', 'Kode'),
                $this->kol('nama', 'Akun Lawan'),
                $this->kol('masuk', 'Kas Masuk (Rp)', 'uang'),
                $this->kol('keluar', 'Kas Keluar (Rp)', 'uang'),
                $this->kol('neto', 'Neto (Rp)', 'uang'),
            ],
            'baris' => $baris->values(),
            'total' => $total,
            'info' => $akhir->isEqualTo($akhirSebenarnya)
                ? 'Metode langsung, dari jurnal yang melibatkan Kas (1110) dan Bank (1120). Saldo akhir cocok dengan saldo buku besar kas dan bank. Aktivitas operasional mencakup pendapatan, beban, piutang, dan pendapatan diterima dimuka.'
                : 'PERINGATAN: saldo kas akhir hasil laporan tidak sama dengan saldo buku besar kas dan bank. Segera periksa.',
        ];
    }

    private function saldoKas(array $kasIds, string $op, string $tanggal): BigDecimal
    {
        $r = DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->whereIn('d.chart_of_account_id', $kasIds)
            ->where('j.tanggal', $op, $tanggal)
            ->selectRaw('COALESCE(SUM(d.debit), 0) as debit, COALESCE(SUM(d.kredit), 0) as kredit')
            ->first();

        return BigDecimal::of($r->debit)->minus($r->kredit);
    }

    // ---------------------------------------------------------------- Buku Besar (FR-095)

    public function bukuBesar(array $f): array
    {
        $akun = $f['akun'] !== '' ? ChartOfAccount::withTrashed()->find($f['akun']) : null;

        $dasar = [
            'judul' => 'Buku Besar',
            'orientasi' => 'portrait',
            'kolom' => [
                $this->kol('tanggal', 'Tanggal'),
                $this->kol('nomor', 'Nomor Jurnal'),
                $this->kol('keterangan', 'Keterangan'),
                $this->kol('debit', 'Debit (Rp)', 'uang'),
                $this->kol('kredit', 'Kredit (Rp)', 'uang'),
                $this->kol('saldo', 'Saldo (Rp)', 'uang'),
            ],
        ];

        if (! $akun) {
            return $dasar + [
                'periode' => 'Pilih akun terlebih dahulu',
                'baris' => collect(),
                'total' => [],
                'info' => 'Pilih satu akun pada filter untuk melihat buku besarnya.',
            ];
        }

        // akun bersaldo normal debit (aset, beban) bertambah di debit; lainnya bertambah di kredit
        $tanda = in_array($akun->tipe, ['aset', 'beban'], true) ? 1 : -1;

        $awal = DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->where('d.chart_of_account_id', $akun->id)
            ->where('j.tanggal', '<', $f['dari'])
            ->selectRaw('COALESCE(SUM(d.debit), 0) as debit, COALESCE(SUM(d.kredit), 0) as kredit')
            ->first();
        $saldo = BigDecimal::of($awal->debit)->minus($awal->kredit)->multipliedBy($tanda);
        $saldoAwal = $saldo;

        $rows = DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->where('d.chart_of_account_id', $akun->id)
            ->whereBetween('j.tanggal', [$f['dari'], $f['sampai']])
            ->orderBy('j.tanggal')->orderBy('j.id')->orderBy('d.id')
            ->get(['j.tanggal', 'j.nomor', 'j.keterangan', 'd.keterangan as ket_baris', 'd.debit', 'd.kredit']);

        $baris = collect([[
            'tanggal' => $this->tgl($f['dari']),
            'nomor' => '',
            'keterangan' => 'Saldo awal',
            'debit' => null,
            'kredit' => null,
            'saldo' => (string) $saldo->toScale(2),
        ]]);

        $td = BigDecimal::zero();
        $tk = BigDecimal::zero();

        foreach ($rows as $r) {
            $debit = BigDecimal::of($r->debit);
            $kredit = BigDecimal::of($r->kredit);
            $td = $td->plus($debit);
            $tk = $tk->plus($kredit);
            $saldo = $saldo->plus($debit->minus($kredit)->multipliedBy($tanda));

            $baris->push([
                'tanggal' => $this->tgl($r->tanggal),
                'nomor' => $r->nomor,
                'keterangan' => $r->ket_baris ?: $r->keterangan,
                'debit' => $debit->isZero() ? null : (string) $debit->toScale(2),
                'kredit' => $kredit->isZero() ? null : (string) $kredit->toScale(2),
                'saldo' => (string) $saldo->toScale(2),
            ]);
        }

        return $dasar + [
            'periode' => "Akun: {$akun->kode} - {$akun->nama} | ".$this->tgl($f['dari']).' s.d. '.$this->tgl($f['sampai']),
            'baris' => $baris->values(),
            'total' => [
                ['label' => 'TOTAL MUTASI', 'nilai' => ['debit' => (string) $td->toScale(2), 'kredit' => (string) $tk->toScale(2)]],
                ['label' => 'SALDO AKHIR', 'nilai' => ['saldo' => (string) $saldo->toScale(2)]],
            ],
            'info' => ($tanda === 1 ? 'Saldo normal debit.' : 'Saldo normal kredit.')
                .' Saldo awal = seluruh mutasi sebelum '.$this->tgl($f['dari']).'.',
        ];
    }

    // ---------------------------------------------------------------- Neraca Saldo (FR-095)

    public function neracaSaldo(array $f): array
    {
        $rows = DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'd.chart_of_account_id')
            ->where('j.tanggal', '<=', $f['sampai'])
            ->groupBy('a.id', 'a.kode', 'a.nama', 'a.tipe')
            ->orderBy('a.kode')
            ->selectRaw('a.kode, a.nama, a.tipe, SUM(d.debit) as debit, SUM(d.kredit) as kredit')
            ->get();

        $td = BigDecimal::zero();
        $tk = BigDecimal::zero();

        $baris = $rows->map(function ($r) use (&$td, &$tk) {
            $net = BigDecimal::of($r->debit)->minus($r->kredit);
            $debit = $net->isPositive() ? $net : BigDecimal::zero();
            $kredit = $net->isNegative() ? $net->negated() : BigDecimal::zero();
            $td = $td->plus($debit);
            $tk = $tk->plus($kredit);

            return [
                'kode' => $r->kode,
                'nama' => $r->nama,
                'tipe' => ucfirst($r->tipe),
                'debit' => $debit->isZero() ? null : (string) $debit->toScale(2),
                'kredit' => $kredit->isZero() ? null : (string) $kredit->toScale(2),
            ];
        })->values();

        return [
            'judul' => 'Neraca Saldo',
            'periode' => 'Per '.$this->tgl($f['sampai']),
            'orientasi' => 'portrait',
            'kolom' => [
                $this->kol('kode', 'Kode'),
                $this->kol('nama', 'Nama Akun'),
                $this->kol('tipe', 'Tipe'),
                $this->kol('debit', 'Debit (Rp)', 'uang'),
                $this->kol('kredit', 'Kredit (Rp)', 'uang'),
            ],
            'baris' => $baris,
            'total' => [[
                'label' => 'TOTAL',
                'nilai' => ['debit' => (string) $td->toScale(2), 'kredit' => (string) $tk->toScale(2)],
            ]],
            'info' => $td->isEqualTo($tk)
                ? 'Saldo kumulatif seluruh jurnal sampai tanggal ini. Total debit sama dengan total kredit (seimbang).'
                : 'PERINGATAN: total debit dan kredit tidak sama. Segera periksa.',
        ];
    }

    // ---------------------------------------------------------------- util

    private function kol(string $key, string $label, string $tipe = 'teks'): array
    {
        return ['key' => $key, 'label' => $label, 'tipe' => $tipe];
    }

    private function tgl(?string $v): string
    {
        return $v ? Carbon::parse($v)->format('d/m/Y') : '';
    }
}
