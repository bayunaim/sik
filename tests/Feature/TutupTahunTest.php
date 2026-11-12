<?php

use App\Livewire\Master\TahunAnggaran\Index as TahunIndex;
use App\Models\Anggaran;
use App\Models\PenerimaanDana;
use App\Models\TahunAnggaran;
use App\Services\PembayaranService;
use App\Services\TutupTahunService;
use Livewire\Livewire;

beforeEach(function () {
    $this->svc = app(TutupTahunService::class);
    $this->super = userDengan('SuperAdmin');
    $this->tahun = tahunAktif(); // 2026, aktif
});

function tahun2027(): TahunAnggaran
{
    return TahunAnggaran::where('nama', '2027')->firstOrFail();
}

it('menutup tahun: 2027 dibuat otomatis dan aktif, anggaran disalin sebagai Draft', function () {
    buatAnggaran('D1.1', '5000000');
    buatAnggaran('K1.1', '2000000');

    $r = $this->svc->tutup($this->tahun->id, $this->super);

    $baru = tahun2027();
    expect($this->tahun->refresh()->status)->toBe('ditutup')
        ->and($baru->status)->toBe('aktif')
        ->and($baru->tanggal_mulai->toDateString())->toBe('2027-01-01')
        ->and($baru->tanggal_selesai->toDateString())->toBe('2027-12-31')
        ->and($r['anggaran_jumlah'])->toBe(2);

    $salinan = Anggaran::where('tahun_anggaran_id', $baru->id)->orderBy('kode')->get();
    expect($salinan->pluck('kode')->all())->toBe(['D1.1', 'K1.1'])
        ->and($salinan->pluck('status')->unique()->all())->toBe(['draft'])
        ->and($salinan->first()->jumlah)->toBe('5000000.00');

    // anggaran tahun lama tidak berubah
    expect(Anggaran::where('tahun_anggaran_id', $this->tahun->id)->where('status', 'approved')->count())->toBe(2);
});

it('tagihan belum lunas dibawa sebagai carry over, tagihan lunas dan batal tetap di tahun lama', function () {
    $a = buatAnggaran('D1.1');
    $belum = buatTagihan(null, '3000000', $a);
    $lunas = buatTagihan(null, '3000000', $a);
    $batal = buatTagihan(null, '3000000', $a);

    $p = catatBayar($lunas, '3000000');
    app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));
    $batal->delete();

    $r = $this->svc->tutup($this->tahun->id, $this->super);

    $baru = tahun2027();
    $anggaranBaru = Anggaran::where('tahun_anggaran_id', $baru->id)->where('kode', 'D1.1')->firstOrFail();

    $belum->refresh();
    expect($belum->tahun_anggaran_id)->toEqual($baru->id)
        ->and($belum->anggaran_id)->toEqual($anggaranBaru->id)
        ->and($belum->carry_over)->toBeTrue()
        ->and($belum->tahun_asal_id)->toEqual($this->tahun->id)
        ->and($r['tagihan_jumlah'])->toBe(1)
        ->and($r['tagihan_sisa'])->toBe('3000000.00');

    expect($lunas->refresh()->tahun_anggaran_id)->toEqual($this->tahun->id)
        ->and($lunas->carry_over)->toBeFalse();
    expect($batal->refresh()->tahun_anggaran_id)->toEqual($this->tahun->id);
});

it('tagihan carry over masih bisa dibayar dan diverifikasi di tahun baru', function () {
    $t = buatTagihan(null, '3000000', buatAnggaran('D1.1'));

    $this->svc->tutup($this->tahun->id, $this->super);

    $p = catatBayar($t->refresh(), '3000000');
    $v = app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));

    expect($v->status)->toBe('terverifikasi')
        ->and($t->refresh()->status)->toBe('lunas')
        ->and(PenerimaanDana::where('pembayaran_id', $p->id)->first()->tahun_anggaran_id)->toEqual(tahun2027()->id);
});

it('tahun yang sudah ditutup menolak pembayaran baru pada tagihan yang tertinggal di sana', function () {
    $lunas = buatTagihan(null, '3000000', buatAnggaran('D1.1'));
    $p = catatBayar($lunas, '3000000');
    app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));

    $this->svc->tutup($this->tahun->id, $this->super);

    // tagihan lunas tidak ikut pindah; tahunnya sudah ditutup
    expect(fn () => catatBayar($lunas->refresh(), '1000000'))
        ->toThrow(DomainException::class, 'sudah ditutup');
});

it('diblokir dan tidak ada yang berubah jika masih ada pembayaran Pending', function () {
    buatAnggaran('D1.1');
    catatBayar(buatTagihan(null, '3000000'), '1000000');

    expect(fn () => $this->svc->tutup($this->tahun->id, $this->super))
        ->toThrow(DomainException::class, 'menunggu verifikasi');

    expect($this->tahun->refresh()->status)->toBe('aktif')
        ->and(TahunAnggaran::where('nama', '2027')->exists())->toBeFalse();
});

it('diblokir jika masih ada pengeluaran Pending', function () {
    ajukanPengeluaran(userDengan('Operator'), '1000000');

    expect(fn () => $this->svc->tutup($this->tahun->id, $this->super))
        ->toThrow(DomainException::class, 'menunggu persetujuan');

    expect($this->tahun->refresh()->status)->toBe('aktif');
});

it('hanya yang punya hak tahun.tutup yang boleh menutup', function () {
    buatAnggaran('D1.1');

    expect(fn () => $this->svc->tutup($this->tahun->id, userDengan('Admin Keuangan')))
        ->toThrow(DomainException::class, 'hak');

    expect($this->tahun->refresh()->status)->toBe('aktif');
});

it('hanya tahun berstatus Aktif yang bisa ditutup', function () {
    $this->tahun->update(['status' => 'draft']);

    expect(fn () => $this->svc->tutup($this->tahun->id, $this->super))
        ->toThrow(DomainException::class, 'Aktif');
});

it('memakai tahun berikutnya yang sudah ada (Draft) dan tidak menggandakan kode anggaran', function () {
    $next = TahunAnggaran::create([
        'nama' => '2027', 'tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-12-31', 'status' => 'draft',
    ]);
    Anggaran::create([
        'tahun_anggaran_id' => $next->id, 'kode' => 'D1.1', 'tipe' => 'D', 'nama' => 'Sudah ada',
        'jumlah' => '1', 'status' => 'draft',
    ]);
    buatAnggaran('D1.1');
    buatAnggaran('K1.1');

    $this->svc->tutup($this->tahun->id, $this->super);

    expect(TahunAnggaran::where('nama', '2027')->count())->toBe(1)
        ->and(Anggaran::where('tahun_anggaran_id', $next->id)->count())->toBe(2)
        ->and(Anggaran::where('tahun_anggaran_id', $next->id)->where('kode', 'D1.1')->value('nama'))->toBe('Sudah ada')
        ->and($next->refresh()->status)->toBe('aktif');
});

it('menolak jika tahun berikutnya sudah ditutup', function () {
    TahunAnggaran::create([
        'nama' => '2027', 'tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-12-31', 'status' => 'ditutup',
    ]);

    expect(fn () => $this->svc->tutup($this->tahun->id, $this->super))
        ->toThrow(DomainException::class, 'harus Draft');
});

it('pratinjau menampilkan dampak tanpa mengubah data', function () {
    $a = buatAnggaran('D1.1');
    buatAnggaran('K1.1');
    buatTagihan(null, '3000000', $a);

    $r = $this->svc->pratinjau($this->tahun);

    expect($r['tahun_berikut'])->toBe('2027')
        ->and($r['tahun_berikut_baru'])->toBeTrue()
        ->and($r['anggaran_d'])->toBe(1)
        ->and($r['anggaran_k'])->toBe(1)
        ->and($r['tagihan_jumlah'])->toBe(1)
        ->and($r['hambatan'])->toBe([]);

    expect($this->tahun->refresh()->status)->toBe('aktif')
        ->and(TahunAnggaran::where('nama', '2027')->exists())->toBeFalse();
});

it('UI: dua langkah, nama tahun harus diketik persis', function () {
    buatAnggaran('D1.1');
    $this->actingAs($this->super);

    Livewire::test(TahunIndex::class)
        ->call('mulaiTutup', $this->tahun->id)
        ->assertSet('langkahTutup', 1)
        ->call('lanjutTutup')
        ->assertSet('langkahTutup', 2)
        ->set('konfirmasiNama', 'salah')
        ->call('jalankanTutup')
        ->assertHasErrors('konfirmasiNama');

    expect($this->tahun->refresh()->status)->toBe('aktif');

    Livewire::test(TahunIndex::class)
        ->call('mulaiTutup', $this->tahun->id)
        ->call('lanjutTutup')
        ->set('konfirmasiNama', '2026')
        ->call('jalankanTutup')
        ->assertHasNoErrors()
        ->assertSet('langkahTutup', 0);

    expect($this->tahun->refresh()->status)->toBe('ditutup');
});

it('UI: tidak bisa lanjut ke konfirmasi selama ada hambatan', function () {
    catatBayar(buatTagihan(null, '3000000', buatAnggaran('D1.1')), '1000000');
    $this->actingAs($this->super);

    Livewire::test(TahunIndex::class)
        ->call('mulaiTutup', $this->tahun->id)
        ->call('lanjutTutup')
        ->assertSet('langkahTutup', 1);
});

it('UI: pengguna tanpa hak tahun.tutup mendapat 403', function () {
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(TahunIndex::class)
        ->call('mulaiTutup', $this->tahun->id)
        ->assertForbidden();
});
