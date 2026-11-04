<?php

use App\Livewire\Pembayaran\Index;
use App\Models\Pembayaran;
use App\Services\PembayaranService;
use Livewire\Livewire;

function pembayaranTerverifikasiUntukUi(): Pembayaran
{
    $p = catatBayar(buatTagihan(null, '3000000'), '3000000');

    return app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));
}

it('operator tidak boleh membuka form pembatalan', function () {
    $p = pembayaranTerverifikasiUntukUi();
    $this->actingAs(userDengan('Operator'));

    Livewire::test(Index::class)
        ->call('konfirmasiBatal', $p->id)
        ->assertForbidden();

    expect($p->refresh()->status)->toBe('terverifikasi');
});

it('operator tidak boleh menyimpan pembatalan walau memanggil langsung', function () {
    $p = pembayaranTerverifikasiUntukUi();
    $this->actingAs(userDengan('Operator'));

    Livewire::test(Index::class)
        ->set('batalId', $p->id)
        ->set('alasanBatal', 'Salah input nominal')
        ->call('simpanBatal')
        ->assertForbidden();

    expect($p->refresh()->status)->toBe('terverifikasi');
});

it('admin keuangan membatalkan pembayaran lewat layar dengan alasan', function () {
    $p = pembayaranTerverifikasiUntukUi();
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(Index::class)
        ->call('konfirmasiBatal', $p->id)
        ->assertSet('batalId', $p->id)
        ->set('alasanBatal', 'Salah input nominal')
        ->call('simpanBatal')
        ->assertHasNoErrors()
        ->assertSet('batalId', null);

    $p->refresh();
    expect($p->status)->toBe('dibatalkan')
        ->and($p->alasan_batal)->toBe('Salah input nominal')
        ->and($p->tagihan->terbayar)->toBe('0.00');
});

it('alasan pembatalan yang terlalu pendek ditolak dan tidak mengubah data', function () {
    $p = pembayaranTerverifikasiUntukUi();
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(Index::class)
        ->call('konfirmasiBatal', $p->id)
        ->set('alasanBatal', 'abc')
        ->call('simpanBatal')
        ->assertHasErrors(['alasanBatal']);

    expect($p->refresh()->status)->toBe('terverifikasi');
});

it('kesalahan dari service ditampilkan di form, bukan error 500', function () {
    $pending = catatBayar(buatTagihan(null, '3000000'), '1000000');
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(Index::class)
        ->set('batalId', $pending->id)
        ->set('alasanBatal', 'Coba batalkan yang pending')
        ->call('simpanBatal')
        ->assertHasErrors(['alasanBatal']);

    expect($pending->refresh()->status)->toBe('pending');
});

it('tombol Batalkan hanya tampil untuk pembayaran terverifikasi dan yang berhak', function () {
    pembayaranTerverifikasiUntukUi();

    $this->actingAs(userDengan('Admin Keuangan'));
    Livewire::test(Index::class)->set('filterStatus', 'terverifikasi')->assertSee('Batalkan');

    $this->actingAs(userDengan('Operator'));
    Livewire::test(Index::class)->set('filterStatus', 'terverifikasi')->assertDontSee('Batalkan');
});
