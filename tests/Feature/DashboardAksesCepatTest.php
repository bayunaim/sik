<?php

use App\Livewire\Dashboard;
use App\Models\User;
use App\Services\AksesCepatService;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/* FR-104: akses cepat di dashboard, disaring menurut izin. */

function labelAkses(string $peran, array $antrean = []): array
{
    return collect(app(AksesCepatService::class)->untuk(userDengan($peran), $antrean))->pluck('label')->all();
}

it('setiap tombol akses cepat menunjuk rute dan izin yang benar-benar ada', function () {
    $rusak = collect(config('akses_cepat'))
        ->filter(fn (array $i) => ! Route::has($i['route']) || ! Permission::where('name', $i['can'])->exists())
        ->pluck('label')
        ->all();

    expect($rusak)->toBe([]);
});

it('akses cepat mengikuti peran', function (string $peran, array $harapan) {
    expect(labelAkses($peran))->toBe($harapan);
})->with([
    'operator' => ['Operator', ['Buat tagihan massal', 'Catat pembayaran', 'Ajukan pengeluaran']],
    'pimpinan' => ['Pimpinan', ['Setujui pengeluaran', 'Jurnal umum', 'Laporan tunggakan', 'Histori mahasiswa']],
    'mahasiswa' => ['Mahasiswa', []],
]);

it('admin keuangan dan superadmin mendapat seluruh tombol', function (string $peran) {
    expect(labelAkses($peran))->toHaveCount(count(config('akses_cepat')));
})->with(['Admin Keuangan', 'SuperAdmin']);

it('tombol antrean menampilkan jumlah, tombol lain nol, dan jumlah negatif diabaikan', function () {
    $item = collect(app(AksesCepatService::class)->untuk(userDengan('Admin Keuangan'), ['verifikasi' => 3, 'approval' => -2]))
        ->keyBy('label');

    expect($item['Verifikasi pembayaran']['badge'])->toBe(3)
        ->and($item['Setujui pengeluaran']['badge'])->toBe(0)
        ->and($item['Catat pembayaran']['badge'])->toBe(0);
});

it('tombol setujui pengeluaran langsung menyaring yang pending', function () {
    $item = collect(app(AksesCepatService::class)->untuk(userDengan('Pimpinan')))->keyBy('label');

    expect($item['Setujui pengeluaran']['url'])->toContain('filterStatus=pending');
});

it('dashboard menampilkan akses cepat beserta jumlah antrean verifikasi', function () {
    catatBayar(buatTagihan(null, '3000000'), '1000000');   // pending
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(Dashboard::class)
        ->assertSee('Akses cepat')
        ->assertSee('Buat tagihan massal')
        ->assertSee('Verifikasi pembayaran')
        ->assertSeeHtml('<span class="badge bg-danger ms-1">1</span>');
});

it('dashboard menyembunyikan kartu akses cepat bila tidak ada tombol yang berhak', function () {
    $user = User::create([
        'name' => 'Hanya Anggaran', 'email' => 'hanya-anggaran@tes.test', 'password' => 'rahasia123', 'is_active' => true,
    ]);
    $user->givePermissionTo('anggaran.lihat');
    $this->actingAs($user);

    Livewire::test(Dashboard::class)->assertDontSee('Akses cepat');
});
