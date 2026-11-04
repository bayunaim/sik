<?php

use App\Models\ChartOfAccount;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Pembayaran;
use App\Models\PenerimaanDana;
use App\Models\TahunAnggaran;
use App\Services\PembayaranService;

beforeEach(function () {
    $this->svc = app(PembayaranService::class);
    $this->admin = userDengan('Admin Keuangan');
    $this->tagihan = buatTagihan(null, '3000000');
});

it('mencatat pembayaran sebagai Pending dan menandai tagihan Pending', function () {
    $p = catatBayar($this->tagihan, '1000000');

    expect($p->status)->toBe('pending')->and($p->nomor_kwitansi)->toBeNull();
    expect($this->tagihan->refresh()->status)->toBe('pending')
        ->and($this->tagihan->terbayar)->toBe('0.00');
});

it('menolak nominal nol dan melebihi sisa tagihan', function () {
    expect(fn () => catatBayar($this->tagihan, '0'))->toThrow(DomainException::class, 'lebih dari nol');
    expect(fn () => catatBayar($this->tagihan, '3500000'))->toThrow(DomainException::class, 'melebihi sisa');
});

it('menghitung pembayaran Pending sebagai bagian yang sudah terpakai', function () {
    catatBayar($this->tagihan, '2000000');

    expect(fn () => catatBayar($this->tagihan, '2000000'))->toThrow(DomainException::class, 'melebihi sisa');
    expect(catatBayar($this->tagihan, '1000000')->exists)->toBeTrue();
});

it('menolak pembayaran pada tahun anggaran yang sudah ditutup', function () {
    TahunAnggaran::find($this->tagihan->tahun_anggaran_id)->update(['status' => 'ditutup']);

    expect(fn () => catatBayar($this->tagihan, '1000000'))->toThrow(DomainException::class, 'sudah ditutup');
});

it('verifikasi memperbarui pembayaran, tagihan, penerimaan dana, dan jurnal sekaligus', function () {
    $p = catatBayar($this->tagihan, '1000000');
    $p = $this->svc->verifikasi($p->id, $this->admin);

    expect($p->status)->toBe('terverifikasi')
        ->and($p->nomor_kwitansi)->toMatch('/^KWT\/2026\/\d{6}$/')
        ->and($p->diverifikasi_oleh)->toEqual($this->admin->id);

    expect($this->tagihan->refresh()->terbayar)->toBe('1000000.00')
        ->and($this->tagihan->status)->toBe('sebagian');

    expect(PenerimaanDana::where('pembayaran_id', $p->id)->count())->toBe(1);

    $j = Jurnal::where('kunci_sumber', 'pembayaran:'.$p->id)->with('detail')->firstOrFail();
    expect($j->total_debit)->toBe('1000000.00')
        ->and($j->total_kredit)->toBe('1000000.00')
        ->and($j->detail->firstWhere('chart_of_account_id', akunId('1120'))->debit)->toBe('1000000.00')
        ->and($j->detail->firstWhere('chart_of_account_id', akunId('4120'))->kredit)->toBe('1000000.00');
});

it('pembayaran tunai dijurnal ke akun Kas', function () {
    $p = catatBayar($this->tagihan, '500000', 'Tunai');
    $this->svc->verifikasi($p->id, $this->admin);

    $j = Jurnal::firstOrFail();
    expect($j->detail->firstWhere('chart_of_account_id', akunId('1110'))->debit)->toBe('500000.00');
});

it('tagihan menjadi Lunas setelah cicilan terpenuhi', function () {
    $this->svc->verifikasi(catatBayar($this->tagihan, '1000000')->id, $this->admin);
    expect($this->tagihan->refresh()->status)->toBe('sebagian');

    $this->svc->verifikasi(catatBayar($this->tagihan, '2000000')->id, $this->admin);

    expect($this->tagihan->refresh()->status)->toBe('lunas')
        ->and($this->tagihan->terbayar)->toBe('3000000.00')
        ->and($this->tagihan->sisa)->toBe('0.00');
    expect(fn () => catatBayar($this->tagihan, '1'))->toThrow(DomainException::class, 'sudah lunas');
});

it('tidak bisa memverifikasi pembayaran yang sama dua kali', function () {
    $p = catatBayar($this->tagihan, '1000000');
    $this->svc->verifikasi($p->id, $this->admin);

    expect(fn () => $this->svc->verifikasi($p->id, $this->admin))
        ->toThrow(DomainException::class, 'sudah diproses');

    expect(PenerimaanDana::count())->toBe(1)
        ->and(Jurnal::count())->toBe(1)
        ->and($this->tagihan->refresh()->terbayar)->toBe('1000000.00');
});

it('menolak pembayaran mengembalikan status tagihan dan menutup kemungkinan verifikasi', function () {
    $p = catatBayar($this->tagihan, '1000000');
    $ditolak = $this->svc->tolak($p->id, 'Bukti tidak valid', $this->admin);

    expect($ditolak->status)->toBe('ditolak')->and($ditolak->alasan_tolak)->toBe('Bukti tidak valid');
    expect($this->tagihan->refresh()->status)->toBe('belum_bayar');
    expect(fn () => $this->svc->verifikasi($p->id, $this->admin))->toThrow(DomainException::class, 'sudah diproses');
    expect(Jurnal::count())->toBe(0)->and(PenerimaanDana::count())->toBe(0);
});

it('membatalkan seluruh verifikasi jika jurnal gagal dibuat (atomik)', function () {
    $p = catatBayar($this->tagihan, '1000000');
    ChartOfAccount::where('kode', '1120')->update(['is_active' => false]); // akun Bank hilang

    expect(fn () => $this->svc->verifikasi($p->id, $this->admin))->toThrow(DomainException::class);

    expect($p->refresh()->status)->toBe('pending')
        ->and($p->nomor_kwitansi)->toBeNull();
    expect($this->tagihan->refresh()->terbayar)->toBe('0.00')
        ->and($this->tagihan->status)->toBe('pending');
    expect(PenerimaanDana::count())->toBe(0)->and(Jurnal::count())->toBe(0);
});

it('seluruh jurnal dari pembayaran tetap seimbang', function () {
    $this->svc->verifikasi(catatBayar($this->tagihan, '1000000.25')->id, $this->admin);
    $t2 = buatTagihan(null, '750000');
    $this->svc->verifikasi(catatBayar($t2, '750000', 'Tunai')->id, $this->admin);

    expect(round((float) JurnalDetail::sum('debit'), 2))
        ->toBe(round((float) JurnalDetail::sum('kredit'), 2));
});

it('menghitung sisa yang bisa dibayar dengan memperhitungkan Pending', function () {
    catatBayar($this->tagihan, '1000000');

    expect((string) $this->svc->sisaBisaDibayar($this->tagihan->refresh()))->toBe('2000000.00');
});
