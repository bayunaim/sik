<?php

namespace App\Services;

use App\Models\Anggaran;
use App\Models\Mahasiswa;
use App\Models\Pembayaran;
use App\Models\ProgramStudi;
use App\Models\Tagihan;
use App\Models\TahunAnggaran;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LaporanService
{
    public const JENIS = ['jurnal', 'tunggakan', 'capaian', 'status-keuangan', 'histori-mahasiswa', 'laba-rugi', 'arus-kas', 'buku-besar', 'neraca-saldo'];

    /** Laporan yang dihitung dari jurnal: rentang tanggal terisi default awal tahun sampai hari ini. */
    private const AKUNTANSI = ['laba-rugi', 'arus-kas', 'buku-besar', 'neraca-saldo'];

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
            'akun' => ctype_digit($s('akun')) ? $s('akun') : '',
            'tahun_ajaran' => preg_match('/^\d{4}\/\d{4}$/', $s('tahun_ajaran')) ? $s('tahun_ajaran') : '',
            'nim' => preg_match('/^[A-Za-z0-9._-]{1,30}$/', $s('nim')) ? $s('nim') : '',
        ];

        if ($jenis === 'jurnal') {
            $f['dari'] ??= now()->startOfMonth()->format('Y-m-d');
            $f['sampai'] ??= now()->format('Y-m-d');
        }

        if (in_array($jenis, self::AKUNTANSI, true)) {
            $f['dari'] ??= now()->startOfYear()->format('Y-m-d');
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
            'status-keuangan' => $this->statusKeuangan($f),
            'histori-mahasiswa' => $this->historiMahasiswa($f),
            'laba-rugi' => app(LaporanAkuntansi::class)->labaRugi($f),
            'arus-kas' => app(LaporanAkuntansi::class)->arusKas($f),
            'buku-besar' => app(LaporanAkuntansi::class)->bukuBesar($f),
            'neraca-saldo' => app(LaporanAkuntansi::class)->neracaSaldo($f),
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

    // ---------------------------------------------------------------- Status Keuangan Mahasiswa (FR-150)

    /**
     * Satu baris per mahasiswa untuk bagian akademik (validasi KRS dan ujian).
     * Periode berjalan = semester dan tahun ajaran pada tagihan terbaru, kecuali dipilih lewat filter.
     */
    protected function statusKeuangan(array $f): array
    {
        $semester = $f['semester'];
        $tahunAjaran = $f['tahun_ajaran'];

        if ($semester === '' || $tahunAjaran === '') {
            $terbaru = DB::table('tagihan')->whereNull('deleted_at')
                ->orderByDesc('tanggal_tagihan')->orderByDesc('id')->first(['semester', 'tahun_ajaran']);
            if ($terbaru) {
                $semester = $semester !== '' ? $semester : $terbaru->semester;
                $tahunAjaran = $tahunAjaran !== '' ? $tahunAjaran : $terbaru->tahun_ajaran;
            }
        }

        $dalam = "t.semester = ? AND t.tahun_ajaran = ?";
        $rows = DB::table('mahasiswa as m')
            ->join('program_studi as p', 'p.id', '=', 'm.program_studi_id')
            ->leftJoin('tagihan as t', fn ($j) => $j->on('t.mahasiswa_id', '=', 'm.id')->whereNull('t.deleted_at'))
            ->whereNull('m.deleted_at')
            ->when($f['prodi'] !== '', fn ($q) => $q->where('m.program_studi_id', $f['prodi']))
            ->when($f['angkatan'] !== '', fn ($q) => $q->where('m.angkatan', $f['angkatan']))
            ->groupBy('m.id', 'm.nim', 'm.nama', 'm.angkatan', 'm.status_akademik', 'p.kode')
            ->orderBy('p.kode')->orderBy('m.angkatan')->orderBy('m.nim')
            ->selectRaw("m.nim, m.nama, m.angkatan, m.status_akademik, p.kode as prodi,
                COALESCE(SUM(CASE WHEN {$dalam} THEN t.nominal END), 0) as total,
                COALESCE(SUM(CASE WHEN {$dalam} THEN t.terbayar END), 0) as terbayar,
                COALESCE(SUM(CASE WHEN {$dalam} AND t.nominal > t.terbayar THEN t.nominal - t.terbayar END), 0) as tunggakan,
                COUNT(CASE WHEN {$dalam} THEN 1 END) as n_periode,
                COUNT(CASE WHEN {$dalam} AND t.nominal > t.terbayar THEN 1 END) as n_belum,
                COALESCE(SUM(CASE WHEN t.nominal > t.terbayar THEN t.nominal - t.terbayar END), 0) as tunggakan_semua",
                [$semester, $tahunAjaran, $semester, $tahunAjaran, $semester, $tahunAjaran,
                    $semester, $tahunAjaran, $semester, $tahunAjaran])
            ->get();

        $tTotal = BigDecimal::zero();
        $tBayar = BigDecimal::zero();
        $tTunggak = BigDecimal::zero();
        $tSemua = BigDecimal::zero();

        $baris = $rows->map(function ($r) use (&$tTotal, &$tBayar, &$tTunggak, &$tSemua) {
            $tTotal = $tTotal->plus($r->total);
            $tBayar = $tBayar->plus($r->terbayar);
            $tTunggak = $tTunggak->plus($r->tunggakan);
            $tSemua = $tSemua->plus($r->tunggakan_semua);

            return [
                'nim' => $r->nim,
                'nama' => $r->nama,
                'prodi' => $r->prodi,
                'angkatan' => (string) $r->angkatan,
                'status_akademik' => $r->status_akademik,
                'total' => (string) BigDecimal::of($r->total)->toScale(2),
                'terbayar' => (string) BigDecimal::of($r->terbayar)->toScale(2),
                'tunggakan' => (string) BigDecimal::of($r->tunggakan)->toScale(2),
                'status_lunas' => (int) $r->n_periode === 0 ? 'Tidak ada tagihan' : ((int) $r->n_belum === 0 ? 'Lunas' : 'Belum lunas'),
                'tunggakan_semua' => (string) BigDecimal::of($r->tunggakan_semua)->toScale(2),
            ];
        })->values();

        $bagian = ['Per '.now()->format('d/m/Y')];
        $bagian[] = $semester !== '' ? "Semester {$semester} {$tahunAjaran}" : 'Belum ada tagihan';
        if ($f['prodi'] !== '') {
            $bagian[] = 'Prodi: '.(ProgramStudi::find($f['prodi'])?->kode ?? '-');
        }
        if ($f['angkatan'] !== '') {
            $bagian[] = 'Angkatan: '.$f['angkatan'];
        }

        return [
            'judul' => 'Status Keuangan Mahasiswa',
            'periode' => implode(' | ', $bagian),
            'orientasi' => 'landscape',
            'kolom' => [
                $this->kol('nim', 'NIM'),
                $this->kol('nama', 'Nama'),
                $this->kol('prodi', 'Prodi'),
                $this->kol('angkatan', 'Angkatan'),
                $this->kol('status_akademik', 'Status Akademik'),
                $this->kol('total', 'Total Tagihan (Rp)', 'uang'),
                $this->kol('terbayar', 'Terbayar (Rp)', 'uang'),
                $this->kol('tunggakan', 'Tunggakan (Rp)', 'uang'),
                $this->kol('status_lunas', 'Status Semester Ini'),
                $this->kol('tunggakan_semua', 'Tunggakan Semua Periode (Rp)', 'uang'),
            ],
            'baris' => $baris,
            'total' => [[
                'label' => 'TOTAL ('.$baris->count().' mahasiswa)',
                'nilai' => [
                    'total' => (string) $tTotal->toScale(2),
                    'terbayar' => (string) $tBayar->toScale(2),
                    'tunggakan' => (string) $tTunggak->toScale(2),
                    'tunggakan_semua' => (string) $tSemua->toScale(2),
                ],
            ]],
            'info' => 'Total, terbayar, tunggakan, dan status lunas dihitung untuk semester dan tahun ajaran di atas. Tunggakan semua periode mencakup seluruh tagihan yang belum lunas. Tagihan yang dibatalkan tidak dihitung; tagihan yang masih menunggu verifikasi belum dianggap terbayar.',
        ];
    }

    // ---------------------------------------------------------------- Histori per Mahasiswa (FR-096)

    /**
     * Buku besar tagihan satu mahasiswa: tagihan terbit, pembayaran, dan pembatalannya,
     * berurutan menurut tanggal dengan saldo berjalan (sisa yang harus dibayar).
     *
     * Aturan saldo (sama dengan laporan Tunggakan):
     *  - tagihan terbit menambah saldo; pembatalan tagihan menguranginya lagi;
     *  - hanya pembayaran Terverifikasi yang mengurangi saldo; pembatalan pembayaran menambahnya lagi;
     *  - pembayaran Pending dan Ditolak tampil sebagai catatan tanpa memengaruhi saldo.
     * Jika rentang tanggal diisi, transaksi sebelum "dari" dilipat menjadi baris Saldo awal.
     */
    protected function historiMahasiswa(array $f): array
    {
        $kolom = [
            $this->kol('tanggal', 'Tanggal'),
            $this->kol('jenis', 'Jenis'),
            $this->kol('nomor', 'Nomor'),
            $this->kol('uraian', 'Uraian'),
            $this->kol('status', 'Status'),
            $this->kol('bertambah', 'Bertambah (Rp)', 'uang'),
            $this->kol('berkurang', 'Berkurang (Rp)', 'uang'),
            $this->kol('saldo', 'Sisa Tagihan (Rp)', 'uang'),
        ];

        $kosong = fn (string $periode) => [
            'judul' => 'Histori Transaksi Mahasiswa', 'periode' => $periode,
            'orientasi' => 'landscape', 'kolom' => $kolom, 'baris' => collect(), 'total' => [], 'info' => '',
        ];

        if ($f['nim'] === '') {
            return $kosong('Masukkan NIM mahasiswa untuk melihat histori transaksinya.');
        }

        $m = Mahasiswa::withTrashed()->with('programStudi')->where('nim', $f['nim'])->first();
        if (! $m) {
            return $kosong("Mahasiswa dengan NIM {$f['nim']} tidak ditemukan.");
        }

        $nol = BigDecimal::zero();
        $hari = fn (?string $v): string => Carbon::parse($v)->format('Y-m-d');
        $rp = fn (string $v): string => 'Rp '.self::format('uang', $v);
        $peristiwa = [];

        // 1 = tagihan terbit, 2 = pembayaran, 3 = pembatalan: urutan dalam hari yang sama
        $tagihan = DB::table('tagihan as t')
            ->join('komponen_biaya as k', 'k.id', '=', 't.komponen_biaya_id')
            ->where('t.mahasiswa_id', $m->id)   // sengaja tanpa filter deleted_at: tagihan batal tetap tampil
            ->get(['t.id', 't.nomor', 't.semester', 't.tahun_ajaran', 't.nominal', 't.tanggal_tagihan', 't.status',
                't.carry_over', 't.deleted_at', 't.dibatalkan_pada', 't.alasan_batal', 'k.nama as komponen']);

        foreach ($tagihan as $t) {
            $batal = $t->deleted_at !== null;
            $nominal = BigDecimal::of($t->nominal);

            $peristiwa[] = [
                'tgl' => $hari($t->tanggal_tagihan), 'urut' => 1, 'id' => $t->id,
                'jenis' => 'Tagihan', 'nomor' => $t->nomor,
                'uraian' => "{$t->komponen} {$t->semester} {$t->tahun_ajaran}".($t->carry_over ? ' (carry over)' : ''),
                'status' => $batal ? 'Dibatalkan' : (Tagihan::STATUS[$t->status] ?? $t->status),
                'bertambah' => $nominal, 'berkurang' => null,
            ];

            if ($batal) {
                $peristiwa[] = [
                    'tgl' => $hari($t->dibatalkan_pada ?? $t->deleted_at), 'urut' => 3, 'id' => $t->id,
                    'jenis' => 'Pembatalan Tagihan', 'nomor' => $t->nomor,
                    'uraian' => 'Dibatalkan'.($t->alasan_batal ? ": {$t->alasan_batal}" : ''),
                    'status' => 'Dibatalkan',
                    'bertambah' => null, 'berkurang' => $nominal,
                ];
            }
        }

        $bayar = DB::table('pembayaran as p')
            ->join('tagihan as t', 't.id', '=', 'p.tagihan_id')
            ->where('p.mahasiswa_id', $m->id)
            ->whereNull('p.deleted_at')
            ->get(['p.id', 'p.nomor_kwitansi', 'p.tanggal_bayar', 'p.nominal', 'p.metode', 'p.status',
                'p.alasan_tolak', 'p.alasan_batal', 'p.dibatalkan_pada', 't.nomor as nomor_tagihan']);

        foreach ($bayar as $p) {
            $nominal = BigDecimal::of($p->nominal);
            $berlaku = in_array($p->status, [Pembayaran::TERVERIFIKASI, Pembayaran::DIBATALKAN], true);

            $uraian = "{$p->metode} untuk tagihan {$p->nomor_tagihan}";
            if ($p->status === Pembayaran::PENDING) {
                $uraian .= ' ('.$rp($p->nominal).', menunggu verifikasi)';
            } elseif ($p->status === Pembayaran::DITOLAK) {
                $uraian .= ' ('.$rp($p->nominal).', ditolak'.($p->alasan_tolak ? ": {$p->alasan_tolak}" : '').')';
            }

            $peristiwa[] = [
                'tgl' => $hari($p->tanggal_bayar), 'urut' => 2, 'id' => $p->id,
                'jenis' => 'Pembayaran', 'nomor' => $p->nomor_kwitansi ?? '',
                'uraian' => $uraian, 'status' => Pembayaran::STATUS[$p->status] ?? $p->status,
                'bertambah' => null, 'berkurang' => $berlaku ? $nominal : null,
            ];

            if ($p->status === Pembayaran::DIBATALKAN) {
                $peristiwa[] = [
                    'tgl' => $hari($p->dibatalkan_pada ?? $p->tanggal_bayar), 'urut' => 3, 'id' => $p->id,
                    'jenis' => 'Pembatalan Pembayaran', 'nomor' => $p->nomor_kwitansi ?? '',
                    'uraian' => 'Dibatalkan'.($p->alasan_batal ? ": {$p->alasan_batal}" : ''),
                    'status' => 'Dibatalkan',
                    'bertambah' => $nominal, 'berkurang' => null,
                ];
            }
        }

        $urut = collect($peristiwa)->sortBy([['tgl', 'asc'], ['urut', 'asc'], ['id', 'asc']])->values();

        $saldo = $nol;
        $saldoAwal = $nol;
        $tTambah = $nol;
        $tKurang = $nol;
        $baris = collect();

        foreach ($urut as $e) {
            if ($f['sampai'] && $e['tgl'] > $f['sampai']) {
                break;   // urut menurut tanggal, sisanya di luar periode
            }

            $saldo = $saldo->plus($e['bertambah'] ?? $nol)->minus($e['berkurang'] ?? $nol);

            if ($f['dari'] && $e['tgl'] < $f['dari']) {
                $saldoAwal = $saldo;   // dilipat menjadi saldo awal

                continue;
            }

            $tTambah = $tTambah->plus($e['bertambah'] ?? $nol);
            $tKurang = $tKurang->plus($e['berkurang'] ?? $nol);

            $baris->push([
                'tanggal' => $this->tgl($e['tgl']),
                'jenis' => $e['jenis'],
                'nomor' => $e['nomor'],
                'uraian' => $e['uraian'],
                'status' => $e['status'],
                'bertambah' => $e['bertambah'] ? (string) $e['bertambah']->toScale(2) : null,
                'berkurang' => $e['berkurang'] ? (string) $e['berkurang']->toScale(2) : null,
                'saldo' => (string) $saldo->toScale(2),
            ]);
        }

        if ($f['dari']) {
            $baris->prepend([
                'tanggal' => $this->tgl($f['dari']), 'jenis' => '', 'nomor' => '', 'uraian' => 'Saldo awal',
                'status' => '', 'bertambah' => null, 'berkurang' => null, 'saldo' => (string) $saldoAwal->toScale(2),
            ]);
        }

        $bagian = ["NIM {$m->nim} - {$m->nama}"];
        $bagian[] = 'Prodi: '.($m->programStudi?->kode ?? '-');
        $bagian[] = "Angkatan: {$m->angkatan}";
        $bagian[] = "Status: {$m->status_akademik}";
        $bagian[] = ($f['dari'] || $f['sampai'])
            ? 'Periode: '.($f['dari'] ? $this->tgl($f['dari']) : 'awal').' s.d. '.($f['sampai'] ? $this->tgl($f['sampai']) : 'sekarang')
            : 'Semua periode';

        return [
            'judul' => 'Histori Transaksi Mahasiswa',
            'periode' => implode(' | ', $bagian),
            'orientasi' => 'landscape',
            'kolom' => $kolom,
            'baris' => $baris,
            'total' => [[
                'label' => 'TOTAL / SISA AKHIR',
                'nilai' => [
                    'bertambah' => (string) $tTambah->toScale(2),
                    'berkurang' => (string) $tKurang->toScale(2),
                    'saldo' => (string) $saldo->toScale(2),
                ],
            ]],
            'info' => 'Sisa tagihan = tagihan terbit dikurangi pembayaran terverifikasi, dihitung berurutan menurut tanggal. '
                .'Pembayaran Pending atau Ditolak tampil sebagai catatan dan belum memengaruhi sisa. '
                .'Tagihan atau pembayaran yang dibatalkan tetap tampil, lengkap dengan baris pembatalannya.'
                .($f['dari'] ? ' Saldo awal adalah sisa tagihan sebelum tanggal awal periode.' : ''),
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
