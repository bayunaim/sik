<?php

use App\Models\ChartOfAccount;
use App\Models\Jurnal;
use App\Models\PenerimaanDana;
use App\Models\Pengeluaran;
use App\Services\AnggaranService;
use App\Services\JurnalService;
use App\Services\LaporanService;
use App\Services\PembayaranService;
use App\Services\PenerimaanService;
use App\Services\PengeluaranService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->pengeluaran = app(PengeluaranService::class);
    $this->penerimaan = app(PenerimaanService::class);
    $this->operator = userDengan('Operator');
    $this->bendahara = userDengan('Admin Keuangan', ['batas_persetujuan' => '5000000']);
});

function pengeluaranDisetujui(string $nominal = '2000000', ?\App\Models\Anggaran $a = null): Pengeluaran
{
    $a ??= buatAnggaran('K1.1', '10000000');
    $p = ajukanPengeluaran(userDengan('Operator'), $nominal, '5130', $a);

    return app(PengeluaranService::class)
        ->setujui($p->id, userDengan('Admin Keuangan', ['batas_persetujuan' => '50000000']))['pengeluaran'];
}

function penerimaanManual(string $nominal = '5000000', string $metode = 'Transfer Bank'): PenerimaanDana
{
    return app(PenerimaanService::class)->catatManual([
        'sumber' => 'hibah',
        'anggaran_id' => buatAnggaran('D2.1', '50000000')->id,
        'tanggal' => '2026-03-01',
        'nominal' => $nominal,
        'metode' => $metode,
        'keterangan' => 'Hibah uji',
    ], userDengan('Admin Keuangan'));
}

function efekBersihJurnal(string $sumberType, int $sumberId)
{
    $ids = Jurnal::where('sumber_type', $sumberType)->where('sumber_id', $sumberId)->pluck('id');

    return DB::table('jurnal_detail')->whereIn('jurnal_id', $ids)
        ->selectRaw('chart_of_account_id, SUM(debit) - SUM(kredit) as net')
        ->groupBy('chart_of_account_id')->pluck('net');
}

// ------------------------------------------------------------------ Pengeluaran disetujui

it('membatalkan pengeluaran disetujui: status, sisa anggaran, dan jurnal pulih', function () {
    $a = buatAnggaran('K1.1', '10000000');
    $p = pengeluaranDisetujui('2000000', $a);
    expect((string) $this->pengeluaran->sisaAnggaran($a))->toBe('8000000.00');

    $r = $this->pengeluaran->batalkanDisetujui($p->id, 'Salah input nominal', $this->bendahara);

    expect($r->status)->toBe('dibatalkan')
        ->and($r->alasan_batal)->toBe('Salah input nominal')
        ->and($r->dibatalkan_oleh)->toBe($this->bendahara->id)
        ->and((string) $this->pengeluaran->sisaAnggaran($a))->toBe('10000000.00')
        ->and(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(1);

    $net = efekBersihJurnal(Pengeluaran::class, $p->id);
    expect($net)->toHaveCount(2)->and($net->every(fn ($v) => (float) $v === 0.0))->toBeTrue();
});

it('hanya pengeluaran Disetujui yang bisa dibatalkan, dan hanya sekali', function () {
    $pending = ajukanPengeluaran($this->operator);
    expect(fn () => $this->pengeluaran->batalkanDisetujui($pending->id, 'x', $this->bendahara))
        ->toThrow(DomainException::class, 'Disetujui');

    $ditolak = ajukanPengeluaran($this->operator, '500000');
    $this->pengeluaran->tolak($ditolak->id, 'Tidak sesuai', $this->bendahara);
    expect(fn () => $this->pengeluaran->batalkanDisetujui($ditolak->id, 'x', $this->bendahara))
        ->toThrow(DomainException::class, 'Disetujui');

    $ok = pengeluaranDisetujui('1000000');
    $this->pengeluaran->batalkanDisetujui($ok->id, 'Salah input', $this->bendahara);
    expect(fn () => $this->pengeluaran->batalkanDisetujui($ok->id, 'Lagi', $this->bendahara))
        ->toThrow(DomainException::class, 'Disetujui');

    expect(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(1);
});

it('operator tanpa hak approval tidak bisa membatalkan pengeluaran disetujui', function () {
    $p = pengeluaranDisetujui();

    expect(fn () => $this->pengeluaran->batalkanDisetujui($p->id, 'Coba', $this->operator))
        ->toThrow(DomainException::class, 'hak approval');
    expect($p->refresh()->status)->toBe('disetujui');
});

it('alasan pembatalan pengeluaran wajib diisi', function () {
    $p = pengeluaranDisetujui();

    expect(fn () => $this->pengeluaran->batalkanDisetujui($p->id, '  ', $this->bendahara))
        ->toThrow(DomainException::class, 'Alasan');
});

it('menolak pembatalan pengeluaran pada tahun anggaran yang sudah ditutup', function () {
    $p = pengeluaranDisetujui();
    tahunAktif()->update(['status' => 'ditutup']);

    expect(fn () => $this->pengeluaran->batalkanDisetujui($p->id, 'Koreksi', $this->bendahara))
        ->toThrow(DomainException::class, 'sudah ditutup');
});

it('membatalkan seluruh pembatalan pengeluaran jika jurnal pembalik gagal (atomik)', function () {
    $p = pengeluaranDisetujui();
    ChartOfAccount::where('kode', '1120')->update(['is_active' => false]);

    expect(fn () => $this->pengeluaran->batalkanDisetujui($p->id, 'Koreksi', $this->bendahara))
        ->toThrow(DomainException::class);

    expect($p->refresh()->status)->toBe('disetujui')
        ->and(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(0);
});

it('jurnal pengeluaran tidak bisa dibalik langsung', function () {
    $p = pengeluaranDisetujui();
    $jurnal = Jurnal::where('kunci_sumber', 'pengeluaran:'.$p->id)->firstOrFail();

    expect(fn () => app(JurnalService::class)->balik($jurnal, 'langsung', $this->bendahara))
        ->toThrow(DomainException::class, 'dari transaksi');
    expect($p->refresh()->status)->toBe('disetujui');
});

it('laporan capaian tidak lagi menghitung pengeluaran yang dibatalkan', function () {
    $a = buatAnggaran('K1.1', '10000000');
    $p = pengeluaranDisetujui('2000000', $a);
    $this->pengeluaran->batalkanDisetujui($p->id, 'Salah input', $this->bendahara);

    $lap = app(LaporanService::class);
    $capaian = $lap->susun('capaian', $lap->normalisasi('capaian', []));

    expect($capaian['baris'][0]['realisasi'])->toBe('0.00')
        ->and(app(AnggaranService::class)->realisasi(collect([$a]))[$a->id])->toBe('0.00');
});

// ------------------------------------------------------------------ Penerimaan non-mahasiswa

it('membatalkan penerimaan manual: dihapus, alasan tersimpan, jurnal dibalik, realisasi pulih', function () {
    $p = penerimaanManual('5000000');
    $anggaran = $p->anggaran;

    $this->penerimaan->batalkan($p->id, 'Salah input nominal', $this->bendahara);

    expect(PenerimaanDana::count())->toBe(0);
    $arsip = PenerimaanDana::withTrashed()->find($p->id);
    expect($arsip->alasan_batal)->toBe('Salah input nominal')
        ->and($arsip->dibatalkan_oleh)->toBe($this->bendahara->id)
        ->and(app(AnggaranService::class)->realisasi(collect([$anggaran]))[$anggaran->id])->toBe('0.00');

    $net = efekBersihJurnal(PenerimaanDana::class, $p->id);
    expect($net)->toHaveCount(2)->and($net->every(fn ($v) => (float) $v === 0.0))->toBeTrue();
});

it('penerimaan yang berasal dari pembayaran mahasiswa tidak bisa dibatalkan dari sini', function () {
    $t = buatTagihan(null, '3000000');
    $byr = catatBayar($t, '3000000');
    app(PembayaranService::class)->verifikasi($byr->id, $this->bendahara);
    $penerimaan = PenerimaanDana::where('pembayaran_id', $byr->id)->firstOrFail();

    expect(fn () => $this->penerimaan->batalkan($penerimaan->id, 'Coba', $this->bendahara))
        ->toThrow(DomainException::class, 'modul pembayaran');
    expect(PenerimaanDana::count())->toBe(1);
});

it('operator tidak bisa membatalkan penerimaan', function () {
    $p = penerimaanManual();

    expect(fn () => $this->penerimaan->batalkan($p->id, 'Coba', $this->operator))
        ->toThrow(DomainException::class, 'tidak punya hak');
    expect(PenerimaanDana::count())->toBe(1);
});

it('penerimaan tidak bisa dibatalkan dua kali dan alasan wajib diisi', function () {
    $p = penerimaanManual();

    expect(fn () => $this->penerimaan->batalkan($p->id, '', $this->bendahara))
        ->toThrow(DomainException::class, 'Alasan');

    $this->penerimaan->batalkan($p->id, 'Salah input', $this->bendahara);
    expect(fn () => $this->penerimaan->batalkan($p->id, 'Lagi', $this->bendahara))
        ->toThrow(DomainException::class, 'sudah dibatalkan');

    expect(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(1);
});

it('menolak pembatalan penerimaan pada tahun anggaran yang sudah ditutup', function () {
    $p = penerimaanManual();
    tahunAktif()->update(['status' => 'ditutup']);

    expect(fn () => $this->penerimaan->batalkan($p->id, 'Koreksi', $this->bendahara))
        ->toThrow(DomainException::class, 'sudah ditutup');
});

it('membatalkan seluruh pembatalan penerimaan jika jurnal pembalik gagal (atomik)', function () {
    $p = penerimaanManual();
    ChartOfAccount::where('kode', '1120')->update(['is_active' => false]);

    expect(fn () => $this->penerimaan->batalkan($p->id, 'Koreksi', $this->bendahara))
        ->toThrow(DomainException::class);

    expect(PenerimaanDana::count())->toBe(1)
        ->and(PenerimaanDana::find($p->id)->alasan_batal)->toBeNull()
        ->and(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(0);
});
