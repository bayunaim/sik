<?php

use App\Contracts\SiaProvider;
use App\Models\User;
use App\Services\PembayaranService;
use App\Services\Sia\CsvImportProvider;
use App\Services\Sia\SevimaApiProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function siaUser(array $ability = ['status-keuangan:baca']): User
{
    $u = User::create(['name' => 'Sistem SIA', 'email' => Str::uuid().'@sia.test', 'password' => 'rahasia123', 'is_active' => true]);
    Sanctum::actingAs($u, $ability);

    return $u;
}

// ------------------------------------------------------------------ adapter (FR-153)

it('provider SIA bawaan adalah import file (CSV)', function () {
    expect(app(SiaProvider::class))->toBeInstanceOf(CsvImportProvider::class)
        ->and(app(SiaProvider::class)->field())->toHaveKey('nim');
});

it('provider bisa diganti lewat config tanpa mengubah pemanggil', function () {
    config(['sia.provider' => 'sevima']);

    $p = app(SiaProvider::class);

    expect($p)->toBeInstanceOf(SevimaApiProvider::class)
        ->and(fn () => $p->baca('x.csv'))->toThrow(DomainException::class, 'belum diaktifkan');
});

it('provider yang tidak dikenal ditolak dengan jelas', function () {
    config(['sia.provider' => 'ngawur']);

    expect(fn () => app(SiaProvider::class))->toThrow(InvalidArgumentException::class, 'ngawur');
});

// ------------------------------------------------------------------ API status keuangan (FR-152)

it('API menolak permintaan tanpa token', function () {
    $m = buatMahasiswa();

    $this->getJson("/api/v1/mahasiswa/{$m->nim}/status-keuangan")->assertUnauthorized();
});

it('API menolak token yang tidak punya ability status keuangan', function () {
    $m = buatMahasiswa();
    siaUser(['lain:baca']);

    $this->getJson("/api/v1/mahasiswa/{$m->nim}/status-keuangan")->assertForbidden();
});

it('API menolak akun nonaktif walau tokennya benar', function () {
    $m = buatMahasiswa();
    siaUser()->update(['is_active' => false]);

    $this->getJson("/api/v1/mahasiswa/{$m->nim}/status-keuangan")->assertForbidden();
});

it('API mengembalikan 404 untuk NIM yang tidak dikenal', function () {
    siaUser();

    $this->getJson('/api/v1/mahasiswa/TIDAKADA/status-keuangan')->assertNotFound();
});

it('API: mahasiswa dengan tagihan belum lunas tidak boleh dan tunggakannya benar', function () {
    $m = buatMahasiswa();
    buatTagihan($m, '3000000');
    siaUser();

    $this->getJson("/api/v1/mahasiswa/{$m->nim}/status-keuangan")
        ->assertOk()
        ->assertJsonPath('data.nim', $m->nim)
        ->assertJsonPath('data.status_lunas', 'Belum lunas')
        ->assertJsonPath('data.total_tagihan', '3000000.00')
        ->assertJsonPath('data.terbayar', '0.00')
        ->assertJsonPath('data.tunggakan', '3000000.00')
        ->assertJsonPath('data.boleh', false);
});

it('API: pembayaran Pending belum mengurangi tunggakan, terverifikasi baru melunasi', function () {
    $m = buatMahasiswa();
    $t = buatTagihan($m, '3000000');
    $p = catatBayar($t, '3000000');
    siaUser();

    $this->getJson("/api/v1/mahasiswa/{$m->nim}/status-keuangan")
        ->assertOk()->assertJsonPath('data.boleh', false)->assertJsonPath('data.tunggakan', '3000000.00');

    app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));

    $this->getJson("/api/v1/mahasiswa/{$m->nim}/status-keuangan")
        ->assertOk()
        ->assertJsonPath('data.status_lunas', 'Lunas')
        ->assertJsonPath('data.tunggakan', '0.00')
        ->assertJsonPath('data.terbayar', '3000000.00')
        ->assertJsonPath('data.boleh', true);
});

it('API: mahasiswa tanpa tagihan dilaporkan sebagai Tidak ada tagihan', function () {
    $m = buatMahasiswa();
    siaUser();

    $this->getJson("/api/v1/mahasiswa/{$m->nim}/status-keuangan")
        ->assertOk()
        ->assertJsonPath('data.status_lunas', 'Tidak ada tagihan')
        ->assertJsonPath('data.periode', null);
});

it('API dibatasi per menit dan membalas 429 setelah batas', function () {
    config(['sia.api_per_menit' => 2]);
    $m = buatMahasiswa();
    siaUser();

    $this->getJson("/api/v1/mahasiswa/{$m->nim}/status-keuangan")->assertOk();
    $this->getJson("/api/v1/mahasiswa/{$m->nim}/status-keuangan")->assertOk();
    $this->getJson("/api/v1/mahasiswa/{$m->nim}/status-keuangan")->assertStatus(429);
});

it('perintah sia:buat-token membuat token yang hanya boleh membaca status keuangan', function () {
    $this->artisan('sia:buat-token')->assertSuccessful();

    $u = User::where('email', 'sia@sistem.local')->first();
    $tok = $u->tokens()->first();

    expect($u->roles)->toHaveCount(0)
        ->and($tok->abilities)->toBe(['status-keuangan:baca']);
});
