<?php

use App\Models\ChartOfAccount;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Services\JurnalService;

beforeEach(function () {
    $this->svc = app(JurnalService::class);
    $this->user = userDengan('Admin Keuangan');
    $this->header = ['tanggal' => '2026-03-01', 'tahun_anggaran_id' => tahunAktif()->id, 'keterangan' => 'Uji'];
});

it('membuat jurnal yang balance dengan nomor otomatis', function () {
    $j = jurnalContoh('250000');

    expect($j->nomor)->toMatch('/^JRN\/2026\/\d{6}$/')
        ->and($j->total_debit)->toBe('250000.00')
        ->and($j->total_kredit)->toBe('250000.00')
        ->and($j->detail)->toHaveCount(2);
});

it('menolak jurnal yang tidak balance dan tidak menyimpan apa pun', function () {
    expect(fn () => $this->svc->buat($this->header, [
        ['akun_id' => akunId('1110'), 'debit' => 100, 'kredit' => 0],
        ['akun_id' => akunId('4120'), 'debit' => 0, 'kredit' => 90],
    ], $this->user))->toThrow(DomainException::class, 'tidak balance');

    expect(Jurnal::count())->toBe(0)->and(JurnalDetail::count())->toBe(0);
});

it('menolak jurnal dengan satu baris saja', function () {
    expect(fn () => $this->svc->buat($this->header, [
        ['akun_id' => akunId('1110'), 'debit' => 100, 'kredit' => 0],
    ], $this->user))->toThrow(DomainException::class, 'minimal terdiri');
});

it('menolak baris yang berisi debit dan kredit sekaligus', function () {
    expect(fn () => $this->svc->buat($this->header, [
        ['akun_id' => akunId('1110'), 'debit' => 100, 'kredit' => 100],
        ['akun_id' => akunId('4120'), 'debit' => 0, 'kredit' => 0],
    ], $this->user))->toThrow(DomainException::class, 'debit atau kredit');
});

it('menolak nilai negatif', function () {
    expect(fn () => $this->svc->buat($this->header, [
        ['akun_id' => akunId('1110'), 'debit' => -5, 'kredit' => 0],
        ['akun_id' => akunId('4120'), 'debit' => 0, 'kredit' => -5],
    ], $this->user))->toThrow(DomainException::class, 'negatif');
});

it('menolak akun nonaktif', function () {
    ChartOfAccount::where('kode', '1110')->update(['is_active' => false]);

    expect(fn () => jurnalContoh())->toThrow(DomainException::class, 'nonaktif');
});

it('menolak jurnal pada tahun anggaran yang sudah ditutup', function () {
    tahunAktif()->update(['status' => 'ditutup']);

    expect(fn () => jurnalContoh())->toThrow(DomainException::class, 'sudah ditutup');
});

it('menolak jurnal ganda untuk sumber transaksi yang sama', function () {
    $h = $this->header + ['kunci_sumber' => 'pembayaran:99'];
    $baris = [
        ['akun_id' => akunId('1110'), 'debit' => 100, 'kredit' => 0],
        ['akun_id' => akunId('4120'), 'debit' => 0, 'kredit' => 100],
    ];
    $this->svc->buat($h, $baris, $this->user);

    expect(fn () => $this->svc->buat($h, $baris, $this->user))
        ->toThrow(DomainException::class, 'sudah pernah dibuat');
    expect(Jurnal::count())->toBe(1);
});

it('tidak mengizinkan jurnal diubah atau dihapus', function () {
    $j = jurnalContoh();

    expect(fn () => $j->update(['keterangan' => 'diubah']))->toThrow(LogicException::class);
    expect(fn () => $j->delete())->toThrow(LogicException::class);
    expect(fn () => $j->detail()->first()->update(['debit' => 1]))->toThrow(LogicException::class);
    expect(fn () => $j->detail()->first()->delete())->toThrow(LogicException::class);
});

it('membuat jurnal pembalik dengan debit dan kredit tertukar', function () {
    $j = jurnalContoh('100000');
    $b = $this->svc->balik($j, 'salah input', $this->user);

    $kas = $b->detail->firstWhere('chart_of_account_id', akunId('1110'));

    expect($b->jenis)->toBe('pembalik')
        ->and($b->jurnal_asal_id)->toEqual($j->id)
        ->and((float) $kas->kredit)->toBe(100000.0)
        ->and((float) $kas->debit)->toBe(0.0);
});

it('hanya mengizinkan satu jurnal dibalik sekali', function () {
    $j = jurnalContoh();
    $b = $this->svc->balik($j, 'pertama', $this->user);

    expect(fn () => $this->svc->balik($j, 'kedua', $this->user))
        ->toThrow(DomainException::class, 'sudah pernah dibalik');
    expect(fn () => $this->svc->balik($b, 'balik pembalik', $this->user))
        ->toThrow(DomainException::class, 'tidak dapat dibalik lagi');
});

it('menjaga total debit sama dengan total kredit di seluruh jurnal', function () {
    jurnalContoh('100000');
    jurnalContoh('333333.33');
    $this->svc->balik(jurnalContoh('50000'), 'koreksi', $this->user);

    expect(round((float) JurnalDetail::sum('debit'), 2))
        ->toBe(round((float) JurnalDetail::sum('kredit'), 2));
});
