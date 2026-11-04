<?php

use App\Models\ChartOfAccount;
use App\Models\Jurnal;
use App\Models\Pembayaran;
use App\Models\PenerimaanDana;
use App\Models\Tagihan;
use App\Services\JurnalService;
use App\Services\LaporanService;
use App\Services\PembayaranService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->svc = app(PembayaranService::class);
    $this->admin = userDengan('Admin Keuangan');
});

function bayarTerverifikasi(Tagihan $t, string $nominal): Pembayaran
{
    $p = catatBayar($t, $nominal);

    return app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));
}

it('membatalkan pembayaran terverifikasi: tagihan, penerimaan, dan jurnal ikut dikoreksi', function () {
    $t = buatTagihan(null, '3000000');
    $p = bayarTerverifikasi($t, '3000000');

    expect($t->refresh()->status)->toBe('lunas');

    $r = $this->svc->batalkan($p->id, 'Salah input nominal', $this->admin);

    expect($r->status)->toBe('dibatalkan')
        ->and($r->alasan_batal)->toBe('Salah input nominal')
        ->and($r->dibatalkan_oleh)->toBe($this->admin->id);

    $t->refresh();
    expect($t->terbayar)->toBe('0.00')->and($t->status)->toBe('belum_bayar');

    expect(PenerimaanDana::count())->toBe(0)
        ->and(PenerimaanDana::withTrashed()->count())->toBe(1);

    $jurnal = Jurnal::where('sumber_type', Pembayaran::class)->where('sumber_id', $p->id)->get();
    expect($jurnal)->toHaveCount(2)
        ->and($jurnal->where('jenis', Jurnal::PEMBALIK))->toHaveCount(1);

    // efek bersih jurnal per akun harus nol
    $net = DB::table('jurnal_detail')->whereIn('jurnal_id', $jurnal->pluck('id'))
        ->selectRaw('chart_of_account_id, SUM(debit) - SUM(kredit) as net')
        ->groupBy('chart_of_account_id')->pluck('net');
    expect($net)->toHaveCount(2)->and($net->every(fn ($v) => (float) $v === 0.0))->toBeTrue();
});

it('pembatalan cicilan menurunkan status Lunas menjadi Sebagian', function () {
    $t = buatTagihan(null, '3000000');
    bayarTerverifikasi($t, '1000000');
    $kedua = bayarTerverifikasi($t, '2000000');

    $this->svc->batalkan($kedua->id, 'Dana tidak masuk', $this->admin);

    $t->refresh();
    expect($t->terbayar)->toBe('1000000.00')->and($t->status)->toBe('sebagian');
});

it('status tagihan menjadi Pending jika masih ada pembayaran lain yang menunggu verifikasi', function () {
    $t = buatTagihan(null, '3000000');
    $p = bayarTerverifikasi($t, '1000000');
    catatBayar($t, '500000');   // Pending

    $this->svc->batalkan($p->id, 'Salah rekening', $this->admin);

    expect($t->refresh()->status)->toBe('pending')->and($t->terbayar)->toBe('0.00');
});

it('hanya pembayaran Terverifikasi yang bisa dibatalkan, dan hanya sekali', function () {
    $t = buatTagihan(null, '3000000');
    $pending = catatBayar($t, '1000000');

    expect(fn () => $this->svc->batalkan($pending->id, 'x', $this->admin))
        ->toThrow(DomainException::class, 'Terverifikasi');

    $this->svc->tolak($pending->id, 'Bukti palsu', $this->admin);
    expect(fn () => $this->svc->batalkan($pending->id, 'x', $this->admin))
        ->toThrow(DomainException::class, 'Terverifikasi');

    $ok = bayarTerverifikasi($t, '1000000');
    $this->svc->batalkan($ok->id, 'Salah input', $this->admin);
    expect(fn () => $this->svc->batalkan($ok->id, 'Lagi', $this->admin))
        ->toThrow(DomainException::class, 'Terverifikasi');

    expect(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(1);
});

it('alasan pembatalan wajib diisi', function () {
    $p = bayarTerverifikasi(buatTagihan(null, '3000000'), '1000000');

    expect(fn () => $this->svc->batalkan($p->id, '   ', $this->admin))
        ->toThrow(DomainException::class, 'Alasan');
});

it('menolak pembatalan pada tahun anggaran yang sudah ditutup', function () {
    $p = bayarTerverifikasi(buatTagihan(null, '3000000'), '1000000');
    tahunAktif()->update(['status' => 'ditutup']);

    expect(fn () => $this->svc->batalkan($p->id, 'Koreksi', $this->admin))
        ->toThrow(DomainException::class, 'sudah ditutup');
});

it('membatalkan seluruh pembatalan jika jurnal pembalik gagal dibuat (atomik)', function () {
    $t = buatTagihan(null, '3000000');
    $p = bayarTerverifikasi($t, '3000000');
    ChartOfAccount::where('kode', '1120')->update(['is_active' => false]);

    expect(fn () => $this->svc->batalkan($p->id, 'Koreksi', $this->admin))
        ->toThrow(DomainException::class);

    expect($p->refresh()->status)->toBe('terverifikasi');
    $t->refresh();
    expect($t->terbayar)->toBe('3000000.00')->and($t->status)->toBe('lunas');
    expect(PenerimaanDana::count())->toBe(1)
        ->and(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(0);
});

it('jurnal dari pembayaran tidak bisa dibalik langsung, hanya lewat batalkan pembayaran', function () {
    $t = buatTagihan(null, '3000000');
    $p = bayarTerverifikasi($t, '3000000');
    $jurnal = Jurnal::where('kunci_sumber', 'pembayaran:'.$p->id)->firstOrFail();

    expect(fn () => app(JurnalService::class)->balik($jurnal, 'coba langsung', $this->admin))
        ->toThrow(DomainException::class, 'dari transaksi');

    expect($t->refresh()->terbayar)->toBe('3000000.00')
        ->and(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(0);
});

it('setelah dibatalkan, tunggakan dan capaian anggaran kembali ke kondisi sebelum bayar', function () {
    $d = buatAnggaran('D1.1', '100000000');
    $t = buatTagihan(null, '3000000', $d);
    $p = bayarTerverifikasi($t, '3000000');

    $this->svc->batalkan($p->id, 'Salah input', $this->admin);

    $lap = app(LaporanService::class);
    $tunggakan = $lap->susun('tunggakan', $lap->normalisasi('tunggakan', []));
    $capaian = $lap->susun('capaian', $lap->normalisasi('capaian', []));
    $jurnal = $lap->susun('jurnal', $lap->normalisasi('jurnal', ['dari' => '2026-01-01', 'sampai' => '2026-12-31']));

    expect($tunggakan['baris'])->toHaveCount(1)
        ->and($tunggakan['baris'][0]['sisa'])->toBe('3000000.00')
        ->and($capaian['baris'][0]['realisasi'])->toBe('0.00')
        ->and($jurnal['info'])->toContain('seimbang');
});

it('tagihan yang pembayarannya sudah dibatalkan bisa dibatalkan juga', function () {
    $t = buatTagihan(null, '3000000');
    $p = bayarTerverifikasi($t, '3000000');
    $this->svc->batalkan($p->id, 'Salah input', $this->admin);

    app(\App\Services\TagihanService::class)->batalkan($t->refresh(), 'Salah terbit', $this->admin);

    expect(Tagihan::count())->toBe(0);
});
