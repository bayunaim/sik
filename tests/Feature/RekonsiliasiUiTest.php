<?php

use App\Livewire\Rekonsiliasi\Import as ImportKomponen;
use App\Livewire\Rekonsiliasi\Index;
use App\Livewire\Rekonsiliasi\Rekening;
use App\Models\MutasiBank;
use App\Models\Pembayaran;
use App\Models\RekeningBank;
use Livewire\Livewire;

it('operator tidak boleh membuka halaman rekonsiliasi', function () {
    $this->actingAs(userDengan('Operator'));

    Livewire::test(Index::class)->assertForbidden();
    Livewire::test(ImportKomponen::class)->assertForbidden();
    Livewire::test(Rekening::class)->assertForbidden();
});

it('admin keuangan membuka halaman pencocokan dan melihat mutasi', function () {
    $this->actingAs(userDengan('Admin Keuangan'));
    $r = rekeningTes();
    mutasiTes($r, '2026-03-01', '3000000', '0', 'TRF SPP KHAS');

    Livewire::test(Index::class)->assertOk()->assertSee('TRF SPP KHAS');
});

it('admin keuangan mencocokkan mutasi lewat layar', function () {
    $this->actingAs(userDengan('Admin Keuangan'));
    $p = bayarTransfer('3000000');
    $m = mutasiTes(rekeningTes(), '2026-03-01', '3000000');

    Livewire::test(Index::class)
        ->call('bukaCocok', $m->id)
        ->assertSet('cocokId', $m->id)
        ->call('pilih', Pembayaran::class, $p->id)
        ->assertHasNoErrors()
        ->assertSet('cocokId', null);

    expect($m->refresh()->status)->toBe('cocok');
});

it('abaikan lewat layar wajib beralasan', function () {
    $this->actingAs(userDengan('Admin Keuangan'));
    $m = mutasiTes(rekeningTes(), '2026-03-05', '0', '6500', 'BIAYA ADM');

    Livewire::test(Index::class)
        ->call('konfirmasiAbaikan', $m->id)
        ->set('alasanAbaikan', '')
        ->call('simpanAbaikan')
        ->assertHasErrors(['alasanAbaikan'])
        ->set('alasanAbaikan', 'Biaya admin bank')
        ->call('simpanAbaikan')
        ->assertHasNoErrors()
        ->assertSet('abaikanId', null);

    expect($m->refresh()->status)->toBe('diabaikan');
});

it('pilih() menolak tipe yang tidak dikenal walau dipanggil langsung', function () {
    $this->actingAs(userDengan('Admin Keuangan'));
    $m = mutasiTes(rekeningTes(), '2026-03-01', '3000000');

    Livewire::test(Index::class)
        ->set('cocokId', $m->id)
        ->call('pilih', 'App\\Models\\User', 1)
        ->assertHasErrors(['cocok']);

    expect($m->refresh()->status)->toBe('belum_cocok');
});

it('rekening: tambah, tolak duplikat, nonaktifkan', function () {
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(Rekening::class)
        ->call('tambah')
        ->set('nama_bank', 'BCA')->set('nomor', '1234-567-890')->set('atas_nama', 'Yayasan Tes')
        ->call('simpan')
        ->assertHasNoErrors()
        ->call('tambah')
        ->set('nama_bank', 'BCA')->set('nomor', '1234-567-890')->set('atas_nama', 'Lain')
        ->call('simpan')
        ->assertHasErrors(['nomor'])
        ->call('tutupForm');

    $r = RekeningBank::firstOrFail();
    Livewire::test(Rekening::class)->call('toggleAktif', $r->id);
    expect($r->refresh()->is_active)->toBeFalse();
});

it('rekening nonaktif tidak bisa dipakai untuk import', function () {
    $this->actingAs(userDengan('Admin Keuangan'));
    $r = rekeningTes();
    $r->update(['is_active' => false]);

    Livewire::test(ImportKomponen::class)
        ->set('pilihRekening', (string) $r->id)
        ->call('unggah')
        ->assertHasErrors(['pilihRekening']);
});
