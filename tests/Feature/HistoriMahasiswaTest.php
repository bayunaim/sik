<?php

use App\Livewire\Laporan\Tampil as LaporanTampil;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Services\LaporanService;
use App\Services\PembayaranService;
use App\Services\TagihanService;
use Livewire\Livewire;

/*
 * FR-096: histori transaksi per mahasiswa.
 * Tanggal pembayaran diberikan eksplisit supaya urutan dan saldo berjalan bisa diprediksi
 * (helper catatBayar() memakai 2026-03-01, lebih awal dari tanggal tagihan 2026-08-01).
 */

function histori(array $filter = []): array
{
    $svc = app(LaporanService::class);

    return $svc->susun('histori-mahasiswa', $svc->normalisasi('histori-mahasiswa', $filter));
}

function bayarHistori(Tagihan $tagihan, string $nominal, string $tanggal, bool $verifikasi = true): Pembayaran
{
    $p = app(PembayaranService::class)->catat([
        'tagihan_id' => $tagihan->id,
        'tanggal_bayar' => $tanggal,
        'nominal' => $nominal,
        'metode' => 'Transfer Bank',
    ], userDengan('Operator'));

    return $verifikasi
        ? app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'))
        : $p;
}

// ------------------------------------------------------------------ pemilihan mahasiswa

it('histori tanpa NIM menampilkan petunjuk dan tidak ada baris', function () {
    buatTagihan();

    $r = histori();

    expect($r['baris'])->toHaveCount(0)
        ->and($r['total'])->toBe([])
        ->and($r['periode'])->toContain('Masukkan NIM');
});

it('histori dengan NIM yang tidak ada tidak error', function () {
    $r = histori(['nim' => '999999999']);

    expect($r['baris'])->toHaveCount(0)
        ->and($r['periode'])->toContain('999999999')->toContain('tidak ditemukan');
});

it('normalisasi membuang NIM yang tidak valid', function () {
    $svc = app(LaporanService::class);

    expect($svc->normalisasi('histori-mahasiswa', ['nim' => "2401001'; DROP TABLE x"])['nim'])->toBe('')
        ->and($svc->normalisasi('histori-mahasiswa', ['nim' => '  2401001 '])['nim'])->toBe('2401001')
        ->and($svc->normalisasi('histori-mahasiswa', [])['dari'])->toBeNull();   // tanpa default periode
});

it('histori hanya memuat transaksi mahasiswa yang dipilih', function () {
    $m = buatMahasiswa();
    $lain = buatMahasiswa();
    $t = buatTagihan($m, '3000000');
    $tLain = buatTagihan($lain, '9000000');
    bayarHistori($tLain, '9000000', '2026-08-10');

    $r = histori(['nim' => $m->nim]);

    expect($r['baris'])->toHaveCount(1)
        ->and($r['baris'][0]['nomor'])->toBe($t->nomor)
        ->and($r['periode'])->toContain($m->nim)->toContain($m->nama)
        ->and($r['total'][0]['nilai']['saldo'])->toBe('3000000.00');
});

// ------------------------------------------------------------------ saldo berjalan

it('histori mengurutkan menurut tanggal dengan saldo berjalan yang benar', function () {
    $m = buatMahasiswa();
    $t = buatTagihan($m, '3000000');                 // 2026-08-01
    bayarHistori($t, '500000', '2026-08-20');        // sengaja dicatat lebih dulu dari tanggal yang lebih awal
    bayarHistori($t, '1000000', '2026-08-10');

    $r = histori(['nim' => $m->nim]);

    expect($r['baris']->pluck('jenis')->all())->toBe(['Tagihan', 'Pembayaran', 'Pembayaran'])
        ->and($r['baris']->pluck('tanggal')->all())->toBe(['01/08/2026', '10/08/2026', '20/08/2026'])
        ->and($r['baris']->pluck('saldo')->all())->toBe(['3000000.00', '2000000.00', '1500000.00'])
        ->and($r['total'][0]['nilai'])->toBe([
            'bertambah' => '3000000.00',
            'berkurang' => '1500000.00',
            'saldo' => '1500000.00',
        ]);
});

it('histori: pembayaran Pending dan Ditolak tampil tetapi tidak mengubah saldo', function () {
    $m = buatMahasiswa();
    $t = buatTagihan($m, '3000000');
    bayarHistori($t, '1000000', '2026-08-05', false);
    $ditolak = bayarHistori($t, '500000', '2026-08-06', false);
    app(PembayaranService::class)->tolak($ditolak->id, 'Bukti buram', userDengan('Admin Keuangan'));

    $r = histori(['nim' => $m->nim]);

    expect($r['baris']->pluck('jenis')->all())->toBe(['Tagihan', 'Pembayaran', 'Pembayaran'])
        ->and($r['baris']->pluck('status')->slice(1)->values()->all())->toBe(['Pending', 'Ditolak'])
        ->and($r['baris']->pluck('berkurang')->filter()->all())->toBe([])
        ->and($r['baris']->pluck('saldo')->unique()->all())->toBe(['3000000.00'])
        ->and($r['baris'][1]['uraian'])->toContain('menunggu verifikasi')
        ->and($r['baris'][2]['uraian'])->toContain('Bukti buram');
});

it('histori: tagihan dibatalkan tetap tampil bersama baris pembatalannya', function () {
    $m = buatMahasiswa();
    $this->travelTo('2026-08-05');
    $t = buatTagihan($m, '3000000');
    app(TagihanService::class)->batalkan($t, 'Salah terbit', userDengan('Admin Keuangan'));

    $r = histori(['nim' => $m->nim]);

    expect($r['baris']->pluck('jenis')->all())->toBe(['Tagihan', 'Pembatalan Tagihan'])
        ->and($r['baris']->pluck('saldo')->all())->toBe(['3000000.00', '0.00'])
        ->and($r['baris'][0]['status'])->toBe('Dibatalkan')
        ->and($r['baris'][1]['uraian'])->toContain('Salah terbit')
        ->and($r['total'][0]['nilai']['saldo'])->toBe('0.00');
});

it('histori: pembayaran dibatalkan mengembalikan saldo lewat baris pembatalan', function () {
    $m = buatMahasiswa();
    $t = buatTagihan($m, '3000000');
    $p = bayarHistori($t, '3000000', '2026-08-10');
    $this->travelTo('2026-08-15');
    app(PembayaranService::class)->batalkan($p->id, 'Salah transfer', userDengan('Admin Keuangan'));

    $r = histori(['nim' => $m->nim]);

    expect($r['baris']->pluck('jenis')->all())->toBe(['Tagihan', 'Pembayaran', 'Pembatalan Pembayaran'])
        ->and($r['baris']->pluck('saldo')->all())->toBe(['3000000.00', '0.00', '3000000.00'])
        ->and($r['baris'][1]['status'])->toBe('Dibatalkan')
        ->and($r['baris'][2]['uraian'])->toContain('Salah transfer');
});

it('histori: saldo akhir sama dengan sisa pada laporan Tunggakan', function () {
    $m = buatMahasiswa();
    $t1 = buatTagihan($m, '3000000');
    bayarHistori($t1, '1250000', '2026-08-10');
    buatTagihan($m, '2000000', null, null, ['semester' => 'Genap']);
    buatTagihan(buatMahasiswa(), '9000000');          // mahasiswa lain, tidak boleh ikut

    $svc = app(LaporanService::class);
    $tunggakan = $svc->susun('tunggakan', $svc->normalisasi('tunggakan', []));
    $sisaTunggakan = $tunggakan['baris']->where('nim', $m->nim)->sum(fn ($b) => (float) $b['sisa']);

    $r = histori(['nim' => $m->nim]);

    expect($r['baris'])->toHaveCount(3)
        ->and($r['total'][0]['nilai']['saldo'])->toBe('3750000.00')
        ->and($sisaTunggakan)->toBe(3750000.0);
});

// ------------------------------------------------------------------ filter periode

it('histori dengan periode melipat transaksi sebelumnya menjadi saldo awal', function () {
    $m = buatMahasiswa();
    $t = buatTagihan($m, '3000000');
    bayarHistori($t, '1000000', '2026-08-10');
    bayarHistori($t, '500000', '2026-08-20');

    $r = histori(['nim' => $m->nim, 'dari' => '2026-08-15', 'sampai' => '2026-08-31']);

    expect($r['baris']->pluck('uraian')->first())->toBe('Saldo awal')
        ->and($r['baris'][0]['saldo'])->toBe('2000000.00')
        ->and($r['baris'])->toHaveCount(2)
        ->and($r['baris'][1]['saldo'])->toBe('1500000.00')
        ->and($r['total'][0]['nilai'])->toBe([
            'bertambah' => '0.00',
            'berkurang' => '500000.00',
            'saldo' => '1500000.00',
        ]);
});

it('histori dengan batas akhir tidak memuat transaksi sesudahnya', function () {
    $m = buatMahasiswa();
    $t = buatTagihan($m, '3000000');
    bayarHistori($t, '1000000', '2026-08-10');
    bayarHistori($t, '500000', '2026-08-20');

    $r = histori(['nim' => $m->nim, 'sampai' => '2026-08-15']);

    expect($r['baris'])->toHaveCount(2)
        ->and($r['baris']->pluck('uraian')->contains('Saldo awal'))->toBeFalse()
        ->and($r['total'][0]['nilai']['saldo'])->toBe('2000000.00');
});

// ------------------------------------------------------------------ layar, akses, dan ekspor

it('layar histori menampilkan transaksi berdasarkan NIM', function () {
    $m = buatMahasiswa(['nama' => 'Budi Santoso']);
    buatTagihan($m, '3000000');
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(LaporanTampil::class, ['jenis' => 'histori-mahasiswa'])
        ->set('nim', $m->nim)
        ->assertSee('Budi Santoso')
        ->assertSee('3.000.000');
});

it('ekspor histori mahasiswa berjalan untuk yang berhak dan ditolak untuk operator', function () {
    $m = buatMahasiswa();
    buatTagihan($m, '3000000');

    $this->actingAs(userDengan('Admin Keuangan'))
        ->get(route('laporan.ekspor', ['jenis' => 'histori-mahasiswa', 'format' => 'csv', 'nim' => $m->nim]))
        ->assertOk();

    $this->actingAs(userDengan('Admin Keuangan'))
        ->get(route('laporan.ekspor', ['jenis' => 'histori-mahasiswa', 'format' => 'pdf', 'nim' => $m->nim]))
        ->assertOk();

    $this->actingAs(userDengan('Operator'))
        ->get(route('laporan.ekspor', ['jenis' => 'histori-mahasiswa', 'format' => 'csv', 'nim' => $m->nim]))
        ->assertForbidden();
});
