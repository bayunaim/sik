<?php

use App\Models\Jurnal;
use App\Models\Pengeluaran;
use App\Services\PengeluaranService;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->svc = app(PengeluaranService::class);
    $this->operator = userDengan('Operator');
    $this->bendahara = userDengan('Admin Keuangan', ['batas_persetujuan' => '5000000']);
    $this->pimpinan = userDengan('Pimpinan', ['batas_persetujuan' => '50000000']);
});

it('mengajukan pengeluaran dengan status Pending dan nomor otomatis', function () {
    $p = ajukanPengeluaran($this->operator);

    expect($p->status)->toBe('pending')
        ->and($p->nomor)->toMatch('/^PNG\/2026\/\d{6}$/')
        ->and($p->nominal)->toBe('2000000.00');
});

it('menolak kode anggaran penerimaan, akun induk, dan akun bukan beban', function () {
    expect(fn () => ajukanPengeluaran($this->operator, '1000', '5130', buatAnggaran('D1.1')))
        ->toThrow(DomainException::class, 'pengeluaran (K)');
    expect(fn () => ajukanPengeluaran($this->operator, '1000', '5100'))
        ->toThrow(DomainException::class, 'akun induk');
    expect(fn () => ajukanPengeluaran($this->operator, '1000', '1110'))
        ->toThrow(DomainException::class, 'bertipe Beban');
});

it('menyetujui dan membuat jurnal Debit Beban, Kredit Bank', function () {
    $p = ajukanPengeluaran($this->operator, '2000000');
    $r = $this->svc->setujui($p->id, $this->bendahara);

    expect($r['pengeluaran']->status)->toBe('disetujui')->and($r['melebihi'])->toBeFalse();

    $j = Jurnal::where('kunci_sumber', 'pengeluaran:'.$p->id)->with('detail')->firstOrFail();
    expect($j->detail->firstWhere('chart_of_account_id', akunId('5130'))->debit)->toBe('2000000.00')
        ->and($j->detail->firstWhere('chart_of_account_id', akunId('1120'))->kredit)->toBe('2000000.00')
        ->and($j->total_debit)->toBe($j->total_kredit);
});

it('nilai terpakai anggaran hanya menghitung yang disetujui', function () {
    $a = buatAnggaran('K1.1', '10000000');
    $disetujui = ajukanPengeluaran($this->operator, '2000000', '5130', $a);
    ajukanPengeluaran($this->operator, '1500000', '5130', $a);          // tetap Pending
    $ditolak = ajukanPengeluaran($this->operator, '700000', '5130', $a);

    $this->svc->setujui($disetujui->id, $this->bendahara);
    $this->svc->tolak($ditolak->id, 'Tidak sesuai kebutuhan', $this->bendahara);

    expect((string) $this->svc->terpakai($a->id))->toBe('2000000.00')
        ->and((string) $this->svc->sisaAnggaran($a))->toBe('8000000.00');
});

it('pengaju tidak boleh menyetujui atau menolak pengajuannya sendiri', function () {
    $p = ajukanPengeluaran($this->bendahara, '1000000');

    expect(fn () => $this->svc->setujui($p->id, $this->bendahara))->toThrow(DomainException::class, 'pengajuan sendiri');
    expect(fn () => $this->svc->tolak($p->id, 'Menolak sendiri', $this->bendahara))->toThrow(DomainException::class, 'pengajuan sendiri');
});

it('SuperAdmin pun tidak boleh menyetujui pengajuannya sendiri', function () {
    $super = userDengan('SuperAdmin');
    $p = ajukanPengeluaran($super, '1000000');

    expect(fn () => $this->svc->setujui($p->id, $super))->toThrow(DomainException::class, 'pengajuan sendiri');
});

it('menolak persetujuan yang melebihi batas wewenang', function () {
    $p = ajukanPengeluaran($this->operator, '8000000');

    expect(fn () => $this->svc->setujui($p->id, $this->bendahara))->toThrow(DomainException::class, 'melebihi batas');
    expect($p->refresh()->status)->toBe('pending');

    expect($this->svc->setujui($p->id, $this->pimpinan)['pengeluaran']->status)->toBe('disetujui');
});

it('menolak persetujuan dari user tanpa batas persetujuan', function () {
    $tanpaBatas = userDengan('Admin Keuangan');
    $p = ajukanPengeluaran($this->operator, '1000');

    expect(fn () => $this->svc->setujui($p->id, $tanpaBatas))->toThrow(DomainException::class, 'batas persetujuan');
});

it('SuperAdmin tidak dibatasi nominal', function () {
    $p = ajukanPengeluaran($this->operator, '9000000', '5130', buatAnggaran('K1.1', '100000000'));

    expect($this->svc->setujui($p->id, userDengan('SuperAdmin'))['pengeluaran']->status)->toBe('disetujui');
});

it('operator tidak punya hak approval', function () {
    $p = ajukanPengeluaran($this->operator);

    expect(fn () => $this->svc->setujui($p->id, userDengan('Operator')))->toThrow(DomainException::class, 'hak approval');
});

it('tidak bisa menyetujui dua kali', function () {
    $p = ajukanPengeluaran($this->operator);
    $this->svc->setujui($p->id, $this->bendahara);

    expect(fn () => $this->svc->setujui($p->id, $this->bendahara))->toThrow(DomainException::class, 'sudah diproses');
    expect(Jurnal::count())->toBe(1);
});

it('tetap menyetujui saat melebihi sisa anggaran, tetapi mencatat peringatan', function () {
    $a = buatAnggaran('K1.1', '1000000');
    $p = ajukanPengeluaran($this->operator, '2000000', '5130', $a);

    $r = $this->svc->setujui($p->id, userDengan('SuperAdmin'));

    expect($r['melebihi'])->toBeTrue()->and($r['pengeluaran']->status)->toBe('disetujui');
    expect(Activity::where('description', 'disetujui melebihi sisa anggaran')->count())->toBe(1);
});

it('penolakan tidak membuat jurnal dan menyimpan alasan', function () {
    $p = ajukanPengeluaran($this->operator);
    $d = $this->svc->tolak($p->id, 'Anggaran tidak tersedia', $this->bendahara);

    expect($d->status)->toBe('ditolak')->and($d->alasan_tolak)->toBe('Anggaran tidak tersedia');
    expect(Jurnal::count())->toBe(0);
});

it('pengaju bisa membatalkan selama Pending, orang lain tidak', function () {
    $p = ajukanPengeluaran($this->operator);

    expect(fn () => $this->svc->batalkan($p->id, $this->bendahara))->toThrow(DomainException::class, 'Hanya pengaju');

    $this->svc->batalkan($p->id, $this->operator);
    expect(Pengeluaran::count())->toBe(0);
});

it('pengajuan yang sudah disetujui tidak bisa dibatalkan', function () {
    $p = ajukanPengeluaran($this->operator);
    $this->svc->setujui($p->id, $this->bendahara);

    expect(fn () => $this->svc->batalkan($p->id, $this->operator))->toThrow(DomainException::class, 'Pending');
});

it('menolak persetujuan pada tahun anggaran yang sudah ditutup', function () {
    $p = ajukanPengeluaran($this->operator);
    tahunAktif()->update(['status' => 'ditutup']);

    expect(fn () => $this->svc->setujui($p->id, $this->bendahara))->toThrow(DomainException::class, 'sudah ditutup');
});

it('membatalkan persetujuan jika jurnal gagal dibuat (atomik)', function () {
    $p = ajukanPengeluaran($this->operator);
    \App\Models\ChartOfAccount::where('kode', '1120')->update(['is_active' => false]);

    expect(fn () => $this->svc->setujui($p->id, $this->bendahara))->toThrow(DomainException::class);

    expect($p->refresh()->status)->toBe('pending');
    expect(Jurnal::count())->toBe(0);
});
