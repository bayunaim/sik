<?php

use App\Models\Jurnal;
use App\Models\PenerimaanDana;
use App\Services\PenerimaanService;

beforeEach(function () {
    $this->svc = app(PenerimaanService::class);
    $this->user = userDengan('Admin Keuangan');
});

function dataPenerimaan(array $o = []): array
{
    return array_merge([
        'sumber' => 'hibah',
        'anggaran_id' => buatAnggaran('D2.1', '50000000')->id,
        'tanggal' => '2026-03-01',
        'nominal' => '5000000',
        'metode' => 'Transfer Bank',
        'keterangan' => 'Hibah uji',
    ], $o);
}

it('mencatat penerimaan hibah dan membuat jurnal Debit Bank, Kredit Hibah/Donasi', function () {
    $p = $this->svc->catatManual(dataPenerimaan(), $this->user);

    expect((string) $p->nominal)->toBe('5000000.00')
        ->and($p->pembayaran_id)->toBeNull();

    $j = Jurnal::where('kunci_sumber', 'penerimaan:'.$p->id)->with('detail')->firstOrFail();
    expect($j->detail->firstWhere('chart_of_account_id', akunId('1120'))->debit)->toBe('5000000.00')
        ->and($j->detail->firstWhere('chart_of_account_id', akunId('4210'))->kredit)->toBe('5000000.00')
        ->and($j->total_debit)->toBe($j->total_kredit);
});

it('penerimaan tunai dijurnal ke akun Kas', function () {
    $p = $this->svc->catatManual(dataPenerimaan(['metode' => 'Tunai']), $this->user);

    $j = Jurnal::where('kunci_sumber', 'penerimaan:'.$p->id)->with('detail')->firstOrFail();
    expect($j->detail->firstWhere('chart_of_account_id', akunId('1110'))->debit)->toBe('5000000.00');
});

it('sumber selain hibah dan donasi dijurnal ke Pendapatan Lain-lain', function () {
    $p = $this->svc->catatManual(dataPenerimaan(['sumber' => 'sewa']), $this->user);

    $j = Jurnal::where('kunci_sumber', 'penerimaan:'.$p->id)->with('detail')->firstOrFail();
    expect($j->detail->firstWhere('chart_of_account_id', akunId('4290'))->kredit)->toBe('5000000.00');
});

it('menolak kode anggaran pengeluaran (K) dan kode yang masih Draft', function () {
    expect(fn () => $this->svc->catatManual(
        dataPenerimaan(['anggaran_id' => buatAnggaran('K1.1', '1000000')->id]), $this->user
    ))->toThrow(DomainException::class, 'penerimaan (D)');

    expect(fn () => $this->svc->catatManual(
        dataPenerimaan(['anggaran_id' => buatAnggaran('D9.9', '1000000', 'draft')->id]), $this->user
    ))->toThrow(DomainException::class, 'Draft');

    expect(PenerimaanDana::count())->toBe(0);
});

it('menolak nominal nol atau negatif', function () {
    expect(fn () => $this->svc->catatManual(dataPenerimaan(['nominal' => '0']), $this->user))
        ->toThrow(DomainException::class, 'lebih dari nol');
    expect(fn () => $this->svc->catatManual(dataPenerimaan(['nominal' => '-100']), $this->user))
        ->toThrow(DomainException::class, 'lebih dari nol');
});

it('menolak penerimaan pada tahun anggaran yang sudah ditutup', function () {
    $d = dataPenerimaan();
    tahunAktif()->update(['status' => 'ditutup']);

    expect(fn () => $this->svc->catatManual($d, $this->user))
        ->toThrow(DomainException::class, 'sudah ditutup');
});

it('membatalkan penerimaan jika jurnal gagal dibuat (atomik)', function () {
    $d = dataPenerimaan();
    \App\Models\ChartOfAccount::where('kode', '4210')->update(['is_active' => false]);

    expect(fn () => $this->svc->catatManual($d, $this->user))->toThrow(DomainException::class);

    expect(PenerimaanDana::count())->toBe(0)->and(Jurnal::count())->toBe(0);
});
