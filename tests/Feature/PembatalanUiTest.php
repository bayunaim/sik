<?php

use App\Livewire\Penerimaan\Index as PenerimaanIndex;
use App\Livewire\Pengeluaran\Index as PengeluaranIndex;
use App\Models\Jurnal;
use App\Models\PenerimaanDana;
use App\Models\Pengeluaran;
use App\Services\PembayaranService;
use App\Services\PenerimaanService;
use App\Services\PengeluaranService;
use Livewire\Livewire;

function pengeluaranDisetujuiUi(): Pengeluaran
{
    $p = ajukanPengeluaran(userDengan('Operator'), '2000000', '5130', buatAnggaran('K1.1', '10000000'));

    return app(PengeluaranService::class)
        ->setujui($p->id, userDengan('Admin Keuangan', ['batas_persetujuan' => '50000000']))['pengeluaran'];
}

function penerimaanManualUi(): PenerimaanDana
{
    return app(PenerimaanService::class)->catatManual([
        'sumber' => 'hibah',
        'anggaran_id' => buatAnggaran('D2.1', '50000000')->id,
        'tanggal' => '2026-03-01',
        'nominal' => '5000000',
        'metode' => 'Transfer Bank',
        'keterangan' => 'Hibah uji',
    ], userDengan('Admin Keuangan'));
}

// ------------------------------------------------------------------ Pengeluaran

it('pengeluaran: operator tidak boleh membuka form pembatalan', function () {
    $p = pengeluaranDisetujuiUi();
    $this->actingAs(userDengan('Operator'));

    Livewire::test(PengeluaranIndex::class)
        ->call('konfirmasiBatalSetuju', $p->id)
        ->assertForbidden();

    expect($p->refresh()->status)->toBe('disetujui');
});

it('pengeluaran: operator tidak boleh menyimpan pembatalan walau memanggil langsung', function () {
    $p = pengeluaranDisetujuiUi();
    $this->actingAs(userDengan('Operator'));

    Livewire::test(PengeluaranIndex::class)
        ->set('batalSetujuId', $p->id)
        ->set('alasanBatalSetuju', 'Salah input nominal')
        ->call('simpanBatalSetuju')
        ->assertForbidden();

    expect($p->refresh()->status)->toBe('disetujui');
});

it('pengeluaran: admin keuangan membatalkan lewat layar dan jurnal dibalik', function () {
    $p = pengeluaranDisetujuiUi();
    $this->actingAs(userDengan('Admin Keuangan', ['batas_persetujuan' => '5000000']));

    Livewire::test(PengeluaranIndex::class)
        ->call('konfirmasiBatalSetuju', $p->id)
        ->assertSet('batalSetujuId', $p->id)
        ->set('alasanBatalSetuju', 'Salah input nominal')
        ->call('simpanBatalSetuju')
        ->assertHasNoErrors()
        ->assertSet('batalSetujuId', null);

    expect($p->refresh()->status)->toBe('dibatalkan')
        ->and($p->alasan_batal)->toBe('Salah input nominal')
        ->and(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(1);
});

it('pengeluaran: alasan terlalu pendek ditolak dan tidak mengubah data', function () {
    $p = pengeluaranDisetujuiUi();
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(PengeluaranIndex::class)
        ->call('konfirmasiBatalSetuju', $p->id)
        ->set('alasanBatalSetuju', 'abc')
        ->call('simpanBatalSetuju')
        ->assertHasErrors(['alasanBatalSetuju']);

    expect($p->refresh()->status)->toBe('disetujui');
});

it('pengeluaran: kesalahan dari service tampil di form (status belum Disetujui)', function () {
    $pending = ajukanPengeluaran(userDengan('Operator'));
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(PengeluaranIndex::class)
        ->set('batalSetujuId', $pending->id)
        ->set('alasanBatalSetuju', 'Coba batalkan yang pending')
        ->call('simpanBatalSetuju')
        ->assertHasErrors(['alasanBatalSetuju']);

    expect($pending->refresh()->status)->toBe('pending');
});

it('pengeluaran: tombol Batalkan pada baris Disetujui hanya tampil untuk yang berhak', function () {
    pengeluaranDisetujuiUi();

    $this->actingAs(userDengan('Admin Keuangan'));
    Livewire::test(PengeluaranIndex::class)->set('filterStatus', 'disetujui')->assertSee('Batalkan');

    $this->actingAs(userDengan('Operator'));
    Livewire::test(PengeluaranIndex::class)->set('filterStatus', 'disetujui')->assertDontSee('Batalkan');
});

// ------------------------------------------------------------------ Penerimaan

it('penerimaan: operator tidak boleh membatalkan', function () {
    $p = penerimaanManualUi();
    $this->actingAs(userDengan('Operator'));

    Livewire::test(PenerimaanIndex::class)
        ->call('konfirmasiBatal', $p->id)
        ->assertForbidden();

    expect(PenerimaanDana::count())->toBe(1);
});

it('penerimaan: admin keuangan membatalkan lewat layar', function () {
    $p = penerimaanManualUi();
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(PenerimaanIndex::class)
        ->call('konfirmasiBatal', $p->id)
        ->assertSet('batalId', $p->id)
        ->set('alasanBatal', 'Salah input nominal')
        ->call('simpanBatal')
        ->assertHasNoErrors()
        ->assertSet('batalId', null);

    expect(PenerimaanDana::count())->toBe(0)
        ->and(PenerimaanDana::withTrashed()->find($p->id)->alasan_batal)->toBe('Salah input nominal')
        ->and(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(1);
});

it('penerimaan: alasan terlalu pendek ditolak', function () {
    $p = penerimaanManualUi();
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(PenerimaanIndex::class)
        ->call('konfirmasiBatal', $p->id)
        ->set('alasanBatal', 'abc')
        ->call('simpanBatal')
        ->assertHasErrors(['alasanBatal']);

    expect(PenerimaanDana::count())->toBe(1);
});

it('penerimaan dari pembayaran mahasiswa: service menolak dan tombol tidak tampil', function () {
    $byr = catatBayar(buatTagihan(null, '3000000'), '3000000');
    app(PembayaranService::class)->verifikasi($byr->id, userDengan('Admin Keuangan'));
    $penerimaan = PenerimaanDana::where('pembayaran_id', $byr->id)->firstOrFail();

    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(PenerimaanIndex::class)
        ->set('filterSumber', 'mahasiswa')
        ->assertDontSee('Batalkan')
        ->set('batalId', $penerimaan->id)
        ->set('alasanBatal', 'Coba lewat sini')
        ->call('simpanBatal')
        ->assertHasErrors(['alasanBatal']);

    expect(PenerimaanDana::count())->toBe(1);
});

it('penerimaan: tombol Batalkan tampil untuk penerimaan manual', function () {
    penerimaanManualUi();
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(PenerimaanIndex::class)->assertSee('Batalkan');
});
