<?php

namespace App\Services;

use App\Models\Anggaran;
use App\Models\ProgramStudi;
use App\Models\Tagihan;
use App\Models\TahunAnggaran;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LaporanService
{
    public const JENIS = ['jurnal', 'tunggakan', 'capaian'];

    /** Bersihkan filter dari input (URL/form) dan isi default. Dipakai layar dan ekspor. */
    public function normalisasi(string $jenis, array $in): array
    {
        $s = fn (string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $tgl = function (string $v): ?string {
            try {
                $c = Carbon::createFromFormat('Y-m-d', $v);

                return $c && $c->format('Y-m-d') === $v ? $v : null;
            } catch (\Throwable) {
                return null;
            }
        };

        $f = [
            'dari' => $tgl($s('dari')),
            'sampai' => $tgl($s('sampai')),
            'tahun_id' => ctype_digit($s('tahun_id')) ? $s('tahun_id') : '',
            'tipe' => in_array($s('tipe'), ['D', 'K'], true) ? $s('tipe') : '',
            'prodi' => ctype_digit($s('prodi')) ? $s('prodi') : '',
            'angkatan' => ctype_digit($s('angkatan')) ? $s('angkatan') : '',
            'semester' => in_array($s('semester'), Tagihan::SEMESTER, true) ? $s('semester') : '',
            'tahun_ajaran' => preg_match('/^\d{4}\/\d{4}$/', $s('tahun_ajaran')) ? $s('tahun_ajaran') : '',
        ];

        if ($jenis === 'jurnal') {
            $f['dari'] ??= now()->startOfMonth()->format('Y-m-d');
            $f['sampai'] ??= now()->format('Y-m-d');
        }

        if ($jenis === 'capaian') {
            $t = ($f['tahun_id'] !== '' ? TahunAnggaran::find($f['tahun_id']) : null)
                ?? TahunAnggaran::aktifSekarang()
                ?? TahunAnggaran::orderByDesc('tanggal_mulai')->first();

            $f['tahun_id'] = $t ? (string) $t->id : '';
            if ($t) {
                $f['dari'] ??= $t->tanggal_mulai->format('Y-m-d');
                $f['sampai'] ??= $t->tanggal_selesai->format('Y-m-d');
            }
        }

        if ($f['dari'] && $f['sampai'] && $f['dari'] > $f['sampai']) {
            [$f['dari'], $f['sampai']] = [$f['sampai'], $f['dari']];
        }

        return $f;
    }

    public function susun(string $jenis, array $f): array
    {
        return match ($jenis) {
            'jurnal' => $this->jurnal($f),
            'tunggakan' => $this->tunggakan($f),
            'capaian' => $this->capaian($f),
        };
    }

    /** Format tampilan: dipakai layar dan PDF, supaya angkanya sama persis. */
    public static function format(string $tipe, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($tipe) {
            'uang', 'angka' => number_format((float) $v, 0, ',', '.'),
            'persen' => number_format((float) $v, 1, ',', '.').'%',
            default => (string) $v,
        };
    }

    // ---------------------------------------------------------------- Jurnal Umum (FR-090)

    protected function jurnal(array $f): array
    {
        $rows = DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'd.chart_of_account_id')
            ->whereBetween('j.tanggal', [$f['dari'], $f['sampai']])
            ->orderBy('j.tanggal')->orderBy('j.id')->orderBy('d.id')
            ->get(['j.tanggal', 'j.nomor', 'j.jenis', 'j.keterangan', 'a.kode', 'a.nama', 'd.debit', 'd.kredit']);

        $td = BigDecimal::zero();
        $tk = BigDecimal::zero();

        $baris = $rows->map(function ($r) use (&$td, &$tk) {
            $debit = BigDecimal::of($r->debit);
            $kredit = BigDecimal::of($r->kredit);
            $td = $td->plus($debit);
            $tk = $tk->plus($kredit);

            return [
                'tanggal' => $this->tgl($r->tanggal),
                'nomor' => $r->nomor,
                'jenis' => ucfirst($r->jenis),
                'akun' => "{$r->kode} - {$r->nama}",
                'keterangan' => $r->keterangan,
                'debit' => $debit->isZero() ? null : (string) $debit->toScale(2),
                'kredit' => $kredit->isZero() ? null : (string) $kredit->toScale(2),
            ];
        })->values();

        return [
            'judul' => 'Laporan Jurnal Umum',
            'periode' => 'Periode: '.$this->tgl($f['dari']).' s.d. '.$this->tgl($f['sampai']),
            'orientasi' => 'landscape',
            'kolom' => [
                $this->kol('tanggal', 'Tanggal'),
                $this->kol('nomor', 'Nomor'),
                $this->kol('jenis', 'Jenis'),
                $this->kol('akun', 'Akun'),
                $this->kol('keterangan', 'Keterangan'),
                $this->kol('debit', 'Debit (Rp)', 'uang'),
                $this->kol('kredit', 'Kredit (Rp)', 'uang'),
            ],
            'baris' => $baris,
            'total' => [[
                'label' => 'TOTAL',
                'nilai' => ['debit' => (string) $td->toScale(2), 'kredit' => (string) $tk->toScale(2)],
            ]],
            'info' => $td->isEqualTo($tk)
                ? 'Total debit sama dengan total kredit (seimbang).'
                : 'PERINGATAN: total debit dan kredit tidak sama. Segera periksa.',
        ];
    }

    // ---------------------------------------------------------------- Tunggakan (FR-091)

    protected function tunggakan(array $f): array
    {
        $rows = DB::table('tagihan as t')
            ->join('mahasiswa as m', 'm.id', '=', 't.mahasiswa_id')
            ->join('program_studi as p', 'p.id', '=', 'm.program_studi_id')
            ->join('komponen_biaya as k', 'k.id', '=', 't.komponen_biaya_id')
            ->whereNull('t.deleted_at')
            ->where('t.status', '!=', Tagihan::LUNAS)
            ->whereRaw('t.nominal > t.terbayar')
            ->when($f['prodi'] !== '', fn ($q) => $q->where('m.program_studi_id', $f['prodi']))
            ->when($f['angkatan'] !== '', fn ($q) => $q->where('m.angkatan', $f['angkatan']))
            ->when($f['semester'] !== '', fn ($q) => $q->where('t.semester', $f['semester']))
            ->when($f['tahun_ajaran'] !== '', fn ($q) => $q->where('t.tahun_ajaran', $f['tahun_ajaran']))
            ->when($f['dari'], fn ($q, $v) => $q->whereDate('t.jatuh_tempo', '>=', $v))
            ->when($f['sampai'], fn ($q, $v) => $q->whereDate('t.jatuh_tempo', '<=', $v))
            ->orderBy('p.kode')->orderBy('m.angkatan')->orderBy('m.nim')->orderBy('t.jatuh_tempo')
            ->get(['m.nim', 'm.nama', 'p.kode as prodi', 'm.angkatan', 'k.nama as komponen',
                't.semester', 't.tahun_ajaran', 't.nomor', 't.nominal', 't.terbayar', 't.jatuh_tempo']);

        $tNominal = BigDecimal::zero();
        $tBayar = BigDecimal::zero();
        $tSisa = BigDecimal::zero();
        $hariIni = Carbon::today();

        $baris = $rows->map(function ($r) use (&$tNominal, &$tBayar, &$tSisa, $hariIni) {
            $nominal = BigDecimal::of($r->nominal);
            $bayar = BigDecimal::of($r->terbayar);
            $sisa = $nominal->minus($bayar);
            $tNominal = $tNominal->plus($nominal);
            $tBayar = $tBayar->plus($bayar);
            $tSisa = $tSisa->plus($sisa);

            $jt = Carbon::parse($r->jatuh_tempo);

            return [
                'nim' => $r->nim,
                'nama' => $r->nama,
                'prodi' => $r->prodi,
                'angkatan' => (string) $r->angkatan,
                'komponen' => $r->komponen,
                'periode' => "{$r->semester} {$r->tahun_ajaran}",
                'nomor' => $r->nomor,
                'nominal' => (string) $nominal->toScale(2),
                'terbayar' => (string) $bayar->toScale(2),
                'sisa' => (string) $sisa->toScale(2),
                'jatuh_tempo' => $jt->format('d/m/Y'),
                'terlambat' => $jt->lt($hariIni) ? (int) $jt->diffInDays($hariIni, true) : 0,
            ];
        })->values();

        $bagian = ['Per '.now()->format('d/m/Y')];
        $bagian[] = ($f['dari'] || $f['sampai'])
            ? 'Jatuh tempo: '.($f['dari'] ? $this->tgl($f['dari']) : 'awal').' s.d. '.($f['sampai'] ? $this->tgl($f['sampai']) : 'sekarang')
            : 'Semua periode jatuh tempo';
        if ($f['prodi'] !== '') {
            $bagian[] = 'Prodi: '.(ProgramStudi::find($f['prodi'])?->kode ?? '-');
        }
        if ($f['angkatan'] !== '') {
            $bagian[] = 'Angkatan: '.$f['angkatan'];
        }
        if ($f['semester'] !== '') {
            $bagian[] = 'Semester: '.$f['semester'];
        }
        if ($f['tahun_ajaran'] !== '') {
            $bagian[] = 'Tahun ajaran: '.$f['tahun_ajaran'];
        }

        return [
            'judul' => 'Laporan Tunggakan Mahasiswa',
            'periode' => implode(' | ', $bagian),
            'orientasi' => 'landscape',
            'kolom' => [
                $this->kol('nim', 'NIM'),
                $this->kol('nama', 'Nama'),
                $this->kol('prodi', 'Prodi'),
                $this->kol('angkatan', 'Angkatan'),
                $this->kol('komponen', 'Komponen'),
                $this->kol('periode', 'Semester / TA'),
                $this->kol('nomor', 'No. Tagihan'),
                $this->kol('nominal', 'Nominal (Rp)', 'uang'),
                $this->kol('terbayar', 'Terbayar (Rp)', 'uang'),
                $this->kol('sisa', 'Sisa (Rp)', 'uang'),
                $this->kol('jatuh_tempo', 'Jatuh Tempo'),
                $this->kol('terlambat', 'Terlambat (hari)', 'angka'),
            ],
            'baris' => $baris,
            'total' => [[
                'label' => 'TOTAL ('.$baris->count().' tagihan)',
                'nilai' => [
                    'nominal' => (string) $tNominal->toScale(2),
                    'terbayar' => (string) $tBayar->toScale(2),
                    'sisa' => (string) $tSisa->toScale(2),
                ],
            ]],
            'info' => 'Hanya tagihan yang belum lunas dan tidak dibatalkan.',
        ];
    }

    // ---------------------------------------------------------------- Capaian Anggaran (FR-092)

    protected function capaian(array $f): array
    {
        $kolom = [
            $this->kol('kode', 'Kode'),
            $this->kol('nama', 'Nama Anggaran'),
            $this->kol('tipe', 'Jenis'),
            $this->kol('rencana', 'Rencana (Rp)', 'uang'),
            $this->kol('realisasi', 'Realisasi (Rp)', 'uang'),
            $this->kol('sisa', 'Sisa (Rp)', 'uang'),
            $this->kol('persen', 'Capaian', 'persen'),
        ];

        $tahun = $f['tahun_id'] !== '' ? TahunAnggaran::find($f['tahun_id']) : null;

        if (! $tahun) {
            return [
                'judul' => 'Laporan Capaian Anggaran', 'periode' => 'Belum ada tahun anggaran.',
                'orientasi' => 'landscape', 'kolom' => $kolom, 'baris' => collect(), 'total' => [], 'info' => '',
            ];
        }

        $anggaran = Anggaran::where('tahun_anggaran_id', $tahun->id)
            ->where('status', Anggaran::APPROVED)
            ->when($f['tipe'] !== '', fn ($q) => $q->where('tipe', $f['tipe']))
            ->get()
            ->sortBy(fn ($a) => $a->tipe.' '.$a->kode, SORT_NATURAL)
            ->values();

        $ids = $anggaran->pluck('id');

        $tercapai = DB::table('penerimaan_dana')->whereNull('deleted_at')
            ->whereBetween('tanggal', [$f['dari'], $f['sampai']])->whereIn('anggaran_id', $ids)
            ->selectRaw('anggaran_id, SUM(nominal) as total')->groupBy('anggaran_id')->pluck('total', 'anggaran_id');

        $terpakai = DB::table('pengeluaran')->whereNull('deleted_at')->where('status', 'disetujui')
            ->whereBetween('tanggal', [$f['dari'], $f['sampai']])->whereIn('anggaran_id', $ids)
            ->selectRaw('anggaran_id, SUM(nominal) as total')->groupBy('anggaran_id')->pluck('total', 'anggaran_id');

        $sum = ['D' => [BigDecimal::zero(), BigDecimal::zero()], 'K' => [BigDecimal::zero(), BigDecimal::zero()]];

        $baris = $anggaran->map(function ($a) use ($tercapai, $terpakai, &$sum) {
            $rencana = BigDecimal::of($a->jumlah);
            $sumber = $a->tipe === 'D' ? $tercapai : $terpakai;
            $real = BigDecimal::of((string) ($sumber[$a->id] ?? '0'));

            $sum[$a->tipe][0] = $sum[$a->tipe][0]->plus($rencana);
            $sum[$a->tipe][1] = $sum[$a->tipe][1]->plus($real);

            return [
                'kode' => $a->kode,
                'nama' => $a->nama.($a->sub_anggaran ? " - {$a->sub_anggaran}" : ''),
                'tipe' => $a->tipe === 'D' ? 'Penerimaan' : 'Pengeluaran',
                'rencana' => (string) $rencana->toScale(2),
                'realisasi' => (string) $real->toScale(2),
                'sisa' => (string) $rencana->minus($real)->toScale(2),
                'persen' => $rencana->isZero() ? null : round((float) (string) $real / (float) (string) $rencana * 100, 1),
            ];
        })->values();

        $total = [];
        foreach (['D' => 'Total Penerimaan (D)', 'K' => 'Total Pengeluaran (K)'] as $t => $label) {
            if ($f['tipe'] !== '' && $f['tipe'] !== $t) {
                continue;
            }
            [$r, $x] = $sum[$t];
            $total[] = ['label' => $label, 'nilai' => [
                'rencana' => (string) $r->toScale(2),
                'realisasi' => (string) $x->toScale(2),
                'sisa' => (string) $r->minus($x)->toScale(2),
                'persen' => $r->isZero() ? null : round((float) (string) $x / (float) (string) $r * 100, 1),
            ]];
        }

        return [
            'judul' => 'Laporan Capaian Anggaran',
            'periode' => "Tahun anggaran {$tahun->nama} | Realisasi: ".$this->tgl($f['dari']).' s.d. '.$this->tgl($f['sampai']),
            'orientasi' => 'landscape',
            'kolom' => $kolom,
            'baris' => $baris,
            'total' => $total,
            'info' => 'Rencana adalah total anggaran setahun (Approved). Realisasi hanya dihitung pada rentang tanggal yang dipilih. Penerimaan = pembayaran terverifikasi dan penerimaan non-mahasiswa; pengeluaran = yang sudah disetujui.',
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
