<?php

use App\Models\Tagihan;
use App\Services\PembayaranService;
use App\Services\TagihanService;

beforeEach(function () {
    $this->svc = app(TagihanService::class);
    $this->admin = userDengan('Admin Keuangan');
});

it('membuat tagihan dengan nomor otomatis dan status belum bayar', function () {
    $t = buatTagihan();

    expect($t->nomor)->toMatch('/^TGH\/2026\/\d{6}$/')
        ->and($t->status)->toBe('belum_bayar')
        ->and($t->nominal)->toBe('3000000.00')
        ->and($t->terbayar)->toBe('0.00');
});

it('menolak tagihan ganda untuk mahasiswa, komponen, semester, dan tahun ajaran yang sama', function () {
    $m = buatMahasiswa();
    buatTagihan($m);

    expect(fn () => buatTagihan($m))->toThrow(DomainException::class, 'sudah punya tagihan');
    expect(Tagihan::count())->toBe(1);
});

it('mengizinkan semester atau tahun ajaran berbeda', function () {
    $m = buatMahasiswa();
    buatTagihan($m);
    buatTagihan($m, '3000000', null, null, ['semester' => 'Genap']);

    expect(Tagihan::count())->toBe(2);
});

it('mengizinkan tagihan dibuat ulang setelah dibatalkan', function () {
    $m = buatMahasiswa();
    $t = buatTagihan($m);

    $this->svc->batalkan($t, 'Salah sasaran', $this->admin);

    expect(Tagihan::count())->toBe(0)->and(Tagihan::onlyTrashed()->count())->toBe(1);
    expect(buatTagihan($m)->exists)->toBeTrue();
});

it('menolak pembatalan tagihan yang sudah ada pembayaran', function () {
    $t = buatTagihan();
    catatBayar($t, '1000000');

    expect(fn () => $this->svc->batalkan($t, 'Mau dibatalkan', $this->admin))
        ->toThrow(DomainException::class, 'sudah ada pembayaran');
});

it('menolak kode anggaran Draft', function () {
    $draft = buatAnggaran('D1.2', '1000000', 'draft');

    expect(fn () => buatTagihan(null, '3000000', $draft))->toThrow(DomainException::class, 'belum disetujui');
});

it('menolak kode anggaran pengeluaran (K)', function () {
    $k = buatAnggaran('K1.1');

    expect(fn () => buatTagihan(null, '3000000', $k))->toThrow(DomainException::class, 'penerimaan (D)');
});

it('menolak tagihan pada tahun anggaran yang sudah ditutup', function () {
    $a = buatAnggaran();
    tahunAktif()->update(['status' => 'ditutup']);

    expect(fn () => buatTagihan(null, '3000000', $a))->toThrow(DomainException::class, 'sudah ditutup');
});

it('operator memakai tarif dan nominal manualnya diabaikan', function () {
    buatTarif('3000000');
    $operator = userDengan('Operator');

    $t = buatTagihan(null, '999', null, $operator);

    expect($t->nominal)->toBe('3000000.00');
});

it('operator ditolak jika tarif belum diatur', function () {
    $operator = userDengan('Operator');

    expect(fn () => buatTagihan(null, '', null, $operator))->toThrow(DomainException::class, 'belum diatur');
});

it('admin keuangan boleh mengisi nominal manual, tetapi harus lebih dari nol', function () {
    expect(buatTagihan(null, '1250000')->nominal)->toBe('1250000.00');
    expect(fn () => buatTagihan(null, '0'))->toThrow(DomainException::class, 'lebih dari nol');
});

it('menghitung sisa tagihan tanpa float', function () {
    $t = buatTagihan(null, '3000000');
    catatBayar($t, '1000000.50');
    $p = Tagihan::first();
    app(PembayaranService::class)->verifikasi(
        \App\Models\Pembayaran::first()->id,
        $this->admin
    );

    expect($p->refresh()->sisa)->toBe('1999999.50');
});

it('massal: melewati yang sudah punya tagihan dan menerbitkan sisanya', function () {
    buatTarif('3000000');
    [$a, $b, $c] = [buatMahasiswa(), buatMahasiswa(), buatMahasiswa()];
    buatTagihan($a);

    $hasil = $this->svc->buatMassal(paramMassal(), [$a->id, $b->id, $c->id], $this->admin);

    expect($hasil['dibuat'])->toBe(2)
        ->and($hasil['jumlah_lewati'])->toBe(1)
        ->and($hasil['total'])->toBe('6000000.00')
        ->and(Tagihan::count())->toBe(3)
        ->and(Tagihan::whereNull('nomor')->count())->toBe(0);
});

it('massal: pratinjau menandai mahasiswa tanpa tarif dan tidak menyimpan apa pun', function () {
    $mhs = [buatMahasiswa(), buatMahasiswa()];

    $r = $this->svc->pratinjauMassal(paramMassal(), collect($mhs)->pluck('id')->all(), $this->admin);

    expect($r['siap'])->toBe(0)
        ->and($r['jumlah_lewati'])->toBe(2)
        ->and($r['ringkasan_alasan'])->toHaveKey('Tarif belum diatur');
    expect(Tagihan::count())->toBe(0);
});

it('massal: menolak jika semua mahasiswa terpilih dilewati', function () {
    buatTarif();
    $m = buatMahasiswa();
    buatTagihan($m);

    expect(fn () => $this->svc->buatMassal(paramMassal(), [$m->id], $this->admin))
        ->toThrow(DomainException::class, 'Tidak ada tagihan');
});

it('massal: nominal manual berlaku untuk semua mahasiswa terpilih', function () {
    $ids = [buatMahasiswa()->id, buatMahasiswa()->id];

    $hasil = $this->svc->buatMassal(paramMassal(['nominal' => '500000']), $ids, $this->admin);

    expect($hasil['dibuat'])->toBe(2)->and($hasil['total'])->toBe('1000000.00');
});

it('massal: menolak batch kosong', function () {
    expect(fn () => $this->svc->buatMassal(paramMassal(), [], $this->admin))
        ->toThrow(DomainException::class, 'minimal satu');
});
