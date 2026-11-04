<?php

use App\Models\Pengeluaran;
use App\Models\ProgramStudi;
use App\Services\AnggaranService;
use App\Services\JurnalService;
use App\Services\LaporanService;
use App\Services\PembayaranService;
use App\Services\PengeluaranService;

beforeEach(function () {
    $this->svc = app(LaporanService::class);
    $this->admin = userDengan('Admin Keuangan');
    $this->bendahara = userDengan('Admin Keuangan', ['batas_persetujuan' => '5000000']);
});

/** Susun laporan lewat jalur yang sama dengan layar/ekspor: normalisasi dulu, baru susun. */
function laporan(string $jenis, array $filter = []): array
{
    $svc = app(LaporanService::class);

    return $svc->susun($jenis, $svc->normalisasi($jenis, $filter));
}

function bayarDanVerifikasi($tagihan, string $nominal)
{
    $p = catatBayar($tagihan, $nominal);

    return app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));
}

function jurnalPadaTanggal(string $tanggal, string $nominal)
{
    return app(JurnalService::class)->buat(
        ['tanggal' => $tanggal, 'tahun_anggaran_id' => tahunAktif()->id, 'keterangan' => 'Jurnal '.$tanggal],
        [
            ['akun_id' => akunId('1110'), 'debit' => $nominal, 'kredit' => 0],
            ['akun_id' => akunId('4120'), 'debit' => 0, 'kredit' => $nominal],
        ],
        userDengan('Admin Keuangan')
    );
}

// ------------------------------------------------------------------ normalisasi

it('normalisasi membuang input tidak valid dan memakai default', function () {
    $this->travelTo('2026-11-15');

    $f = $this->svc->normalisasi('jurnal', [
        'dari' => '2026-02-30',          // tanggal tidak ada
        'sampai' => 'bukan-tanggal',
        'prodi' => '1; DROP TABLE',
        'angkatan' => 'abc',
        'semester' => 'Kuartal',
        'tahun_ajaran' => '2026-2027',
        'tipe' => 'X',
    ]);

    expect($f['dari'])->toBe('2026-11-01')
        ->and($f['sampai'])->toBe('2026-11-15')
        ->and($f['prodi'])->toBe('')
        ->and($f['angkatan'])->toBe('')
        ->and($f['semester'])->toBe('')
        ->and($f['tahun_ajaran'])->toBe('')
        ->and($f['tipe'])->toBe('');
});

it('normalisasi menukar rentang tanggal yang terbalik', function () {
    $f = $this->svc->normalisasi('tunggakan', ['dari' => '2026-12-01', 'sampai' => '2026-01-01']);

    expect($f['dari'])->toBe('2026-01-01')->and($f['sampai'])->toBe('2026-12-01');
});

it('normalisasi capaian memakai tahun aktif beserta rentang tanggalnya', function () {
    $tahun = tahunAktif();
    $f = $this->svc->normalisasi('capaian', []);

    expect($f['tahun_id'])->toBe((string) $tahun->id)
        ->and($f['dari'])->toBe('2026-01-01')
        ->and($f['sampai'])->toBe('2026-12-31');
});

it('format angka dan persen mengikuti gaya Indonesia', function () {
    expect(LaporanService::format('uang', '1234567.00'))->toBe('1.234.567')
        ->and(LaporanService::format('persen', 20.0))->toBe('20,0%')
        ->and(LaporanService::format('angka', 12))->toBe('12')
        ->and(LaporanService::format('uang', null))->toBe('')
        ->and(LaporanService::format('teks', 'abc'))->toBe('abc');
});

// ------------------------------------------------------------------ Jurnal Umum

it('laporan jurnal hanya memuat rentang tanggal terpilih dan totalnya seimbang', function () {
    jurnalPadaTanggal('2026-03-01', '100000');
    jurnalPadaTanggal('2026-03-15', '250000');
    jurnalPadaTanggal('2026-05-01', '999999');   // di luar rentang

    $r = laporan('jurnal', ['dari' => '2026-03-01', 'sampai' => '2026-03-31']);

    expect($r['baris'])->toHaveCount(4)
        ->and($r['total'][0]['nilai']['debit'])->toBe('350000.00')
        ->and($r['total'][0]['nilai']['kredit'])->toBe('350000.00')
        ->and($r['info'])->toContain('seimbang');
});

it('laporan jurnal mengisi debit atau kredit saja pada tiap baris', function () {
    jurnalPadaTanggal('2026-03-01', '100000');

    $baris = laporan('jurnal', ['dari' => '2026-03-01', 'sampai' => '2026-03-01'])['baris'];

    expect($baris[0]['akun'])->toStartWith('1110')
        ->and($baris[0]['debit'])->toBe('100000.00')->and($baris[0]['kredit'])->toBeNull()
        ->and($baris[1]['akun'])->toStartWith('4120')
        ->and($baris[1]['kredit'])->toBe('100000.00')->and($baris[1]['debit'])->toBeNull();
});

it('laporan jurnal kosong tetap seimbang dengan total nol', function () {
    $r = laporan('jurnal', ['dari' => '2026-03-01', 'sampai' => '2026-03-31']);

    expect($r['baris'])->toHaveCount(0)
        ->and($r['total'][0]['nilai']['debit'])->toBe('0.00')
        ->and($r['info'])->toContain('seimbang');
});

// ------------------------------------------------------------------ Tunggakan

it('tunggakan hanya memuat tagihan belum lunas dan tidak dibatalkan', function () {
    $belum = buatTagihan(null, '3000000');
    $sebagian = buatTagihan(null, '3000000');
    $lunas = buatTagihan(null, '3000000');
    $batal = buatTagihan(null, '3000000');

    bayarDanVerifikasi($sebagian, '1000000');
    bayarDanVerifikasi($lunas, '3000000');
    app(\App\Services\TagihanService::class)->batalkan($batal, 'salah terbit', $this->admin);

    $r = laporan('tunggakan');

    expect($r['baris'])->toHaveCount(2)
        ->and($r['baris']->pluck('nomor')->all())->toContain($belum->nomor, $sebagian->nomor)
        ->and($r['baris']->pluck('nomor')->all())->not->toContain($lunas->nomor, $batal->nomor)
        ->and($r['total'][0]['nilai']['nominal'])->toBe('6000000.00')
        ->and($r['total'][0]['nilai']['terbayar'])->toBe('1000000.00')
        ->and($r['total'][0]['nilai']['sisa'])->toBe('5000000.00');
});

it('tunggakan: pembayaran Pending belum mengurangi sisa', function () {
    $t = buatTagihan(null, '3000000');
    catatBayar($t, '1000000');   // belum diverifikasi

    $r = laporan('tunggakan');

    expect($r['baris'][0]['sisa'])->toBe('3000000.00')
        ->and($r['baris'][0]['terbayar'])->toBe('0.00');
});

it('tunggakan: sisa per baris dan total konsisten', function () {
    bayarDanVerifikasi(buatTagihan(null, '3000000'), '1250000');
    buatTagihan(null, '2000000');

    $r = laporan('tunggakan');
    $jumlahSisa = $r['baris']->sum(fn ($b) => (float) $b['sisa']);

    expect($jumlahSisa)->toBe(3750000.0)
        ->and($r['total'][0]['nilai']['sisa'])->toBe('3750000.00')
        ->and($r['total'][0]['label'])->toBe('TOTAL (2 tagihan)');
});

it('tunggakan menghitung hari keterlambatan dari tanggal jatuh tempo', function () {
    buatTagihan();                      // jatuh tempo 2026-08-31

    $this->travelTo('2026-09-10');
    expect(laporan('tunggakan')['baris'][0]['terlambat'])->toBe(10);

    $this->travelTo('2026-08-15');
    expect(laporan('tunggakan')['baris'][0]['terlambat'])->toBe(0);
});

it('tunggakan: filter prodi, angkatan, dan semester', function () {
    $prodiLain = ProgramStudi::create(['kode' => 'SI', 'nama' => 'Sistem Informasi', 'jenjang' => 'S1', 'is_active' => true]);

    buatTagihan(buatMahasiswa());                                              // TI 2026
    buatTagihan(buatMahasiswa(['angkatan' => 2024]));                          // TI 2024
    buatTagihan(buatMahasiswa(['program_studi_id' => $prodiLain->id]));        // SI 2026
    buatTagihan(buatMahasiswa(), '3000000', null, null, ['semester' => 'Genap']);

    expect(laporan('tunggakan', ['prodi' => (string) $prodiLain->id])['baris'])->toHaveCount(1)
        ->and(laporan('tunggakan', ['angkatan' => '2024'])['baris'])->toHaveCount(1)
        ->and(laporan('tunggakan', ['semester' => 'Genap'])['baris'])->toHaveCount(1)
        ->and(laporan('tunggakan')['baris'])->toHaveCount(4);
});

it('tunggakan: filter rentang jatuh tempo', function () {
    buatTagihan(buatMahasiswa(), '3000000', null, null, ['jatuh_tempo' => '2026-08-31']);
    buatTagihan(buatMahasiswa(), '3000000', null, null, ['jatuh_tempo' => '2026-10-31']);

    $r = laporan('tunggakan', ['dari' => '2026-10-01', 'sampai' => '2026-10-31']);

    expect($r['baris'])->toHaveCount(1)->and($r['baris'][0]['jatuh_tempo'])->toBe('31/10/2026');
});

// ------------------------------------------------------------------ Capaian Anggaran

it('capaian tanpa tahun anggaran menghasilkan laporan kosong tanpa error', function () {
    $r = laporan('capaian');

    expect($r['baris'])->toHaveCount(0)->and($r['periode'])->toContain('Belum ada tahun anggaran');
});

it('capaian menghitung realisasi penerimaan (D) dan pengeluaran (K) beserta persennya', function () {
    $d = buatAnggaran('D1.1', '100000000');
    $k = buatAnggaran('K1.1', '10000000');

    bayarDanVerifikasi(buatTagihan(null, '3000000', $d), '3000000');
    $p = ajukanPengeluaran(userDengan('Operator'), '2000000', '5130', $k);
    app(PengeluaranService::class)->setujui($p->id, $this->bendahara);

    $r = laporan('capaian');
    $baris = $r['baris']->keyBy('kode');

    expect($baris['D1.1']['realisasi'])->toBe('3000000.00')
        ->and($baris['D1.1']['sisa'])->toBe('97000000.00')
        ->and($baris['D1.1']['persen'])->toEqual(3.0)
        ->and($baris['K1.1']['realisasi'])->toBe('2000000.00')
        ->and($baris['K1.1']['sisa'])->toBe('8000000.00')
        ->and($baris['K1.1']['persen'])->toEqual(20.0);

    expect($r['total'][0]['label'])->toBe('Total Penerimaan (D)')
        ->and($r['total'][0]['nilai']['realisasi'])->toBe('3000000.00')
        ->and($r['total'][1]['label'])->toBe('Total Pengeluaran (K)')
        ->and($r['total'][1]['nilai']['realisasi'])->toBe('2000000.00');
});

it('capaian tidak menghitung pengeluaran Pending atau Ditolak', function () {
    $k = buatAnggaran('K1.1', '10000000');
    $op = userDengan('Operator');
    ajukanPengeluaran($op, '1500000', '5130', $k);                              // Pending
    $tolak = ajukanPengeluaran($op, '700000', '5130', $k);
    app(PengeluaranService::class)->tolak($tolak->id, 'Tidak sesuai', $this->bendahara);

    expect(laporan('capaian')['baris'][0]['realisasi'])->toBe('0.00');
});

it('capaian hanya menghitung realisasi pada rentang tanggal terpilih', function () {
    $d = buatAnggaran('D1.1', '100000000');
    bayarDanVerifikasi(buatTagihan(null, '3000000', $d), '3000000');            // dibayar 2026-03-01

    $dalam = laporan('capaian', ['dari' => '2026-01-01', 'sampai' => '2026-03-31']);
    $luar = laporan('capaian', ['dari' => '2026-04-01', 'sampai' => '2026-12-31']);

    expect($dalam['baris'][0]['realisasi'])->toBe('3000000.00')
        ->and($luar['baris'][0]['realisasi'])->toBe('0.00');
});

it('capaian tidak memuat anggaran Draft dan bisa difilter per tipe', function () {
    buatAnggaran('D1.1', '100000000');
    buatAnggaran('K1.1', '10000000');
    buatAnggaran('D9.9', '5000000', 'draft');

    $semua = laporan('capaian');
    $hanyaK = laporan('capaian', ['tipe' => 'K']);

    expect($semua['baris']->pluck('kode')->all())->toBe(['D1.1', 'K1.1'])
        ->and($hanyaK['baris']->pluck('kode')->all())->toBe(['K1.1'])
        ->and($hanyaK['total'])->toHaveCount(1);
});

it('capaian dengan rencana nol tidak membagi dengan nol', function () {
    buatAnggaran('D1.1', '0');

    expect(laporan('capaian')['baris'][0]['persen'])->toBeNull();
});

// ------------------------------------------------------------------ Rekonsiliasi antar laporan

it('angka capaian penerimaan sama dengan AnggaranService dan jurnal kas/bank', function () {
    $d = buatAnggaran('D1.1', '100000000');
    bayarDanVerifikasi(buatTagihan(null, '3000000', $d), '3000000');
    bayarDanVerifikasi(buatTagihan(null, '3000000', $d), '1000000');

    $capaian = laporan('capaian')['baris']->firstWhere('kode', 'D1.1')['realisasi'];
    $anggaranSvc = app(AnggaranService::class)->realisasi(collect([$d]))[$d->id];
    $jurnal = laporan('jurnal', ['dari' => '2026-01-01', 'sampai' => '2026-12-31']);

    expect($capaian)->toBe('4000000.00')
        ->and($anggaranSvc)->toBe($capaian)
        ->and($jurnal['total'][0]['nilai']['debit'])->toBe($capaian)
        ->and($jurnal['total'][0]['nilai']['kredit'])->toBe($capaian);
});
