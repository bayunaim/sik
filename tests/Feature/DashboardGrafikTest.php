<?php

use App\Livewire\Dashboard;
use App\Models\PenerimaanDana;
use App\Models\ProgramStudi;
use App\Models\Tagihan;
use App\Models\User;
use App\Services\DashboardGrafikService;
use App\Services\PembayaranService;
use App\Services\TagihanService;
use Livewire\Livewire;

/* FR-102: grafik dashboard. Waktu dikunci supaya jendela 12 bulan (Des 2025 - Nov 2026) tetap. */

beforeEach(function () {
    $this->travelTo('2026-11-12');
    $this->grafik = app(DashboardGrafikService::class);
});

function terimaGrafik(string $tanggal, string $nominal): PenerimaanDana
{
    return PenerimaanDana::create([
        'sumber' => 'hibah',
        'anggaran_id' => buatAnggaran()->id,
        'tahun_anggaran_id' => tahunAktif()->id,
        'tanggal' => $tanggal,
        'nominal' => $nominal,
    ]);
}

function lunasiGrafik(Tagihan $t, string $nominal): void
{
    $p = app(PembayaranService::class)->catat([
        'tagihan_id' => $t->id, 'tanggal_bayar' => '2026-08-10', 'nominal' => $nominal, 'metode' => 'Transfer Bank',
    ], userDengan('Operator'));
    app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));
}

function prodiGrafik(string $kode): ProgramStudi
{
    return ProgramStudi::create(['kode' => $kode, 'nama' => "Prodi {$kode}", 'jenjang' => 'S1', 'is_active' => true]);
}

// ------------------------------------------------------------------ tren penerimaan

it('tren penerimaan membuat 12 bulan berurutan dengan bulan berjalan di ujung', function () {
    $r = $this->grafik->trenPenerimaan();
    $bulan = collect($r['bulan']);

    expect($bulan)->toHaveCount(12)
        ->and($bulan->first()['kunci'])->toBe('2025-12')
        ->and($bulan->last()['kunci'])->toBe('2026-11')
        ->and($bulan->last()['berjalan'])->toBeTrue()
        ->and($bulan->where('berjalan', true))->toHaveCount(1)
        ->and($bulan->pluck('label')->first())->toBe('Des 25')
        ->and($bulan->pluck('label')[1])->toBe('Jan 26')      // tahun ditulis di bulan pertama dan Januari
        ->and($bulan->pluck('label')[2])->toBe('Feb');
});

it('tren penerimaan tanpa data aman dan ditandai kosong', function () {
    $r = $this->grafik->trenPenerimaan();

    expect($r['kosong'])->toBeTrue()
        ->and($r['total'])->toBe('0.00')
        ->and(collect($r['bulan'])->pluck('nilai')->unique()->all())->toBe(['0.00'])
        ->and(collect($r['bulan'])->pluck('persen')->unique()->all())->toBe([0.0]);
});

it('tren penerimaan menjumlah per bulan dan mengabaikan data di luar jendela atau dibatalkan', function () {
    terimaGrafik('2026-11-05', '1000000');
    terimaGrafik('2026-11-20', '500000');
    terimaGrafik('2026-09-10', '2000000');
    terimaGrafik('2025-12-01', '100000');            // batas awal jendela
    terimaGrafik('2025-11-30', '7000000');           // sebulan sebelum jendela
    terimaGrafik('2026-10-15', '9000000')->delete(); // dibatalkan (soft delete)

    $r = $this->grafik->trenPenerimaan();
    $per = collect($r['bulan'])->keyBy('kunci');

    expect($per['2026-11']['nilai'])->toBe('1500000.00')
        ->and($per['2026-09']['nilai'])->toBe('2000000.00')
        ->and($per['2025-12']['nilai'])->toBe('100000.00')
        ->and($per['2026-10']['nilai'])->toBe('0.00')
        ->and($r['total'])->toBe('3600000.00')
        ->and($r['kosong'])->toBeFalse();
});

it('tinggi batang proporsional terhadap bulan tertinggi dan bulan kecil tetap terlihat', function () {
    terimaGrafik('2026-09-10', '2000000');
    terimaGrafik('2026-11-05', '1500000');
    terimaGrafik('2026-07-01', '1000');              // sangat kecil

    $per = collect($this->grafik->trenPenerimaan()['bulan'])->keyBy('kunci');

    expect($per['2026-09']['persen'])->toBe(100.0)
        ->and($per['2026-11']['persen'])->toBe(75.0)
        ->and($per['2026-07']['persen'])->toBe(2.0)  // dasar minimum
        ->and($per['2026-08']['persen'])->toBe(0.0);
});

it('tren penerimaan memuat pembayaran mahasiswa yang sudah diverifikasi pada bulan tanggal bayarnya', function () {
    $t = buatTagihan(null, '3000000');
    lunasiGrafik($t, '1250000');                     // 2026-08-10

    $per = collect($this->grafik->trenPenerimaan()['bulan'])->keyBy('kunci');

    expect($per['2026-08']['nilai'])->toBe('1250000.00');
});

it('angka ringkas memakai satuan rb, jt, M, dan T', function (string $in, string $out) {
    expect(DashboardGrafikService::singkat($in))->toBe($out);
})->with([
    ['500.00', '500'],
    ['950000.00', '950 rb'],
    ['1500000.00', '1,5 jt'],
    ['2000000000.00', '2 M'],
    ['1250000000000.00', '1,3 T'],
]);

// ------------------------------------------------------------------ tunggakan per prodi

it('tunggakan per prodi menjumlah sisa, mengurutkan terbesar dulu, dan menghitung persen', function () {
    $si = prodiGrafik('SI');
    $ti1 = buatTagihan(buatMahasiswa(), '3000000');
    lunasiGrafik($ti1, '1000000');                                        // TI: sisa 2.000.000
    buatTagihan(buatMahasiswa(['program_studi_id' => $si->id]), '6000000'); // SI: 6.000.000
    buatTagihan(buatMahasiswa(), '2000000', null, null, ['semester' => 'Genap']); // TI +2.000.000 => 4.000.000

    $r = $this->grafik->tunggakanPerProdi();

    expect(collect($r['item'])->pluck('kode')->all())->toBe(['SI', 'TI'])
        ->and(collect($r['item'])->pluck('nilai')->all())->toBe(['6000000.00', '4000000.00'])
        ->and(collect($r['item'])->pluck('jumlah')->all())->toBe([1, 2])
        ->and(collect($r['item'])->pluck('persen')->all())->toBe([60.0, 40.0])
        ->and($r['total'])->toBe('10000000.00')
        ->and($r['jumlah_tagihan'])->toBe(3)
        ->and($r['kosong'])->toBeFalse();
});

it('tunggakan per prodi tidak memuat tagihan lunas atau dibatalkan', function () {
    $lunas = buatTagihan(null, '3000000');
    lunasiGrafik($lunas, '3000000');
    $batal = buatTagihan(null, '3000000');
    app(TagihanService::class)->batalkan($batal, 'salah terbit', userDengan('Admin Keuangan'));
    buatTagihan(null, '1000000');

    $r = $this->grafik->tunggakanPerProdi();

    expect($r['total'])->toBe('1000000.00')->and($r['item'])->toHaveCount(1);
});

it('tunggakan per prodi sama dengan total tunggakan pada kartu dan laporan Tunggakan', function () {
    $si = prodiGrafik('SI');
    lunasiGrafik(buatTagihan(null, '3000000'), '1250000');
    buatTagihan(buatMahasiswa(['program_studi_id' => $si->id]), '2000000');

    $kartu = (string) Tagihan::where('status', '!=', Tagihan::LUNAS)->selectRaw('COALESCE(SUM(nominal - terbayar),0) as t')->value('t');
    $svc = app(\App\Services\LaporanService::class);
    $laporan = $svc->susun('tunggakan', $svc->normalisasi('tunggakan', []))['total'][0]['nilai']['sisa'];

    expect((float) $this->grafik->tunggakanPerProdi()['total'])->toBe((float) $kartu)
        ->and($this->grafik->tunggakanPerProdi()['total'])->toBe($laporan);
});

it('tunggakan per prodi tanpa tunggakan ditandai kosong tanpa pembagian nol', function () {
    $r = $this->grafik->tunggakanPerProdi();

    expect($r['kosong'])->toBeTrue()->and($r['item'])->toBe([])->and($r['total'])->toBe('0.00');
});

it('prodi di luar batas digabung menjadi Lainnya dan total tetap utuh', function () {
    foreach (range(1, 9) as $i) {
        $p = prodiGrafik("P{$i}");
        buatTagihan(buatMahasiswa(['program_studi_id' => $p->id]), (string) ($i * 1000000));
    }

    $r = $this->grafik->tunggakanPerProdi(8);
    $item = collect($r['item']);

    expect($item)->toHaveCount(8)
        ->and($item->first()['kode'])->toBe('P9')
        ->and($item->last()['kode'])->toBe('Lainnya')
        ->and($item->last()['nama'])->toBe('2 prodi lain')
        ->and($item->last()['nilai'])->toBe('3000000.00')     // P2 + P1
        ->and($item->last()['jumlah'])->toBe(2)
        ->and($item->sum(fn ($i) => (float) $i['nilai']))->toBe(45000000.0)
        ->and($r['total'])->toBe('45000000.00');
});

// ------------------------------------------------------------------ tampilan dan hak akses

it('dashboard menampilkan kedua grafik untuk peran yang berhak', function (string $peran) {
    terimaGrafik('2026-11-05', '1500000');
    buatTagihan(null, '3000000');
    $this->actingAs(userDengan($peran));

    Livewire::test(Dashboard::class)
        ->assertSee('Tren penerimaan 12 bulan terakhir')
        ->assertSee('1,5 jt')
        ->assertSee('Komposisi tunggakan per prodi')
        ->assertSee('TI');
})->with(['SuperAdmin', 'Admin Keuangan', 'Pimpinan']);

it('dashboard tanpa data tetap tampil dengan pesan kosong', function () {
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(Dashboard::class)
        ->assertSee('Belum ada penerimaan pada periode ini.')
        ->assertSee('Tidak ada tunggakan.');
});

it('grafik mengikuti hak akses: tanpa penerimaan.lihat tren tidak tampil', function () {
    buatTagihan(null, '3000000');
    $user = User::create([
        'name' => 'Hanya Tagihan', 'email' => 'hanya-tagihan@tes.test', 'password' => 'rahasia123', 'is_active' => true,
    ]);
    $user->givePermissionTo('tagihan.lihat');
    $this->actingAs($user);

    Livewire::test(Dashboard::class)
        ->assertSee('Komposisi tunggakan per prodi')
        ->assertDontSee('Tren penerimaan');
});
