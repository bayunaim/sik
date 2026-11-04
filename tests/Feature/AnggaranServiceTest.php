<?php

use App\Models\PenerimaanDana;
use App\Models\Pengeluaran;
use App\Services\AnggaranService;
use App\Services\PembayaranService;
use App\Services\PenerimaanService;
use App\Services\PengeluaranService;

beforeEach(function () {
    $this->svc = app(AnggaranService::class);
    $this->admin = userDengan('Admin Keuangan');
    $this->bendahara = userDengan('Admin Keuangan', ['batas_persetujuan' => '5000000']);
});

it('realisasi kode tanpa transaksi bernilai 0.00', function () {
    $d = buatAnggaran('D1.1');
    $k = buatAnggaran('K1.1', '10000000');

    expect($this->svc->realisasi(collect([$d, $k])))->toBe([
        $d->id => '0.00',
        $k->id => '0.00',
    ]);
});

it('realisasi kode D menjumlah penerimaan mahasiswa dan non-mahasiswa', function () {
    $d = buatAnggaran('D1.1');
    $t = buatTagihan(null, '3000000', $d);
    $p = catatBayar($t, '3000000');
    app(PembayaranService::class)->verifikasi($p->id, $this->admin);

    app(PenerimaanService::class)->catatManual([
        'sumber' => 'hibah', 'anggaran_id' => $d->id, 'tanggal' => '2026-03-01',
        'nominal' => '500000', 'metode' => 'Tunai',
    ], $this->admin);

    expect($this->svc->realisasi(collect([$d]))[$d->id])->toBe('3500000.00');
});

it('pembayaran yang masih Pending belum dihitung sebagai realisasi', function () {
    $d = buatAnggaran('D1.1');
    catatBayar(buatTagihan(null, '3000000', $d), '1000000');

    expect($this->svc->realisasi(collect([$d]))[$d->id])->toBe('0.00');
});

it('realisasi kode K hanya menghitung pengeluaran yang disetujui', function () {
    $k = buatAnggaran('K1.1', '10000000');
    $op = userDengan('Operator');
    $pengeluaran = app(PengeluaranService::class);

    $ok = ajukanPengeluaran($op, '2000000', '5130', $k);
    ajukanPengeluaran($op, '1500000', '5130', $k);                       // Pending
    $tolak = ajukanPengeluaran($op, '700000', '5130', $k);
    $batal = ajukanPengeluaran($op, '900000', '5130', $k);

    $pengeluaran->setujui($ok->id, $this->bendahara);
    $pengeluaran->tolak($tolak->id, 'Tidak sesuai', $this->bendahara);
    $pengeluaran->batalkan($batal->id, $op);

    expect($this->svc->realisasi(collect([$k]))[$k->id])->toBe('2000000.00');
});

it('realisasi sama dengan nilai terpakai di PengeluaranService', function () {
    $k = buatAnggaran('K1.1', '10000000');
    $p = ajukanPengeluaran(userDengan('Operator'), '2500000', '5130', $k);
    app(PengeluaranService::class)->setujui($p->id, $this->bendahara);

    expect($this->svc->realisasi(collect([$k]))[$k->id])
        ->toBe((string) app(PengeluaranService::class)->terpakai($k->id));
});

it('penerimaan yang dihapus (soft delete) tidak dihitung', function () {
    $d = buatAnggaran('D2.1', '50000000');
    $p = app(PenerimaanService::class)->catatManual([
        'sumber' => 'hibah', 'anggaran_id' => $d->id, 'tanggal' => '2026-03-01',
        'nominal' => '800000', 'metode' => 'Tunai',
    ], $this->admin);

    PenerimaanDana::find($p->id)->delete();

    expect($this->svc->realisasi(collect([$d]))[$d->id])->toBe('0.00');
});
