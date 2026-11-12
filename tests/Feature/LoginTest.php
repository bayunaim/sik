<?php

use App\Livewire\Auth\Login;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

it('form login memanggil method yang namanya tidak bentrok dengan properti', function () {
    $html = Livewire::test(Login::class)->html();

    expect($html)->toContain('wire:submit="masuk"')->not->toContain('wire:submit="login"');
});

it('tidak ada komponen Livewire yang punya properti dan method publik dengan nama sama', function () {
    // Di browser, $wire.<nama> mengembalikan nilai properti, sehingga method bernama sama tidak bisa dipanggil.
    $bentrok = [];

    foreach (glob(app_path('Livewire').'/{,*/,*/*/}*.php', GLOB_BRACE) as $file) {
        $kelas = 'App\\Livewire\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(app_path('Livewire')) + 1));
        if (! class_exists($kelas)) {
            continue;
        }
        $r = new ReflectionClass($kelas);
        $props = array_map(fn ($p) => $p->getName(), $r->getProperties(ReflectionProperty::IS_PUBLIC));
        $metode = array_map(fn ($m) => $m->getName(), array_filter($r->getMethods(ReflectionMethod::IS_PUBLIC), fn ($m) => $m->getDeclaringClass()->getName() === $kelas));

        foreach (array_intersect($props, $metode) as $nama) {
            $bentrok[] = "{$kelas}::{$nama}";
        }
    }

    expect($bentrok)->toBe([]);
});

it('staf berhasil masuk dengan email dan diarahkan ke dashboard', function () {
    $u = userDengan('Admin Keuangan', ['email' => 'staf-login@tes.test']);

    Livewire::test(Login::class)
        ->set('login', 'staf-login@tes.test')->set('password', 'rahasia123')
        ->call('masuk')
        ->assertHasNoErrors()->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($u);
});

it('mahasiswa masuk dengan NIM dan diarahkan ke portal', function () {
    $u = userDengan('Mahasiswa', ['username' => '2026001', 'email' => '2026001@mahasiswa.tes']);

    Livewire::test(Login::class)
        ->set('login', '2026001')->set('password', 'rahasia123')
        ->call('masuk')
        ->assertRedirect(route('portal.index'));

    $this->assertAuthenticatedAs($u);
});

it('password salah menampilkan pesan dan tidak masuk', function () {
    userDengan('Admin Keuangan', ['email' => 'staf-login@tes.test']);

    Livewire::test(Login::class)
        ->set('login', 'staf-login@tes.test')->set('password', 'salah')
        ->call('masuk')
        ->assertHasErrors(['login']);

    $this->assertGuest();
});

it('akun nonaktif tidak bisa masuk', function () {
    userDengan('Admin Keuangan', ['email' => 'nonaktif-login@tes.test', 'is_active' => false]);

    Livewire::test(Login::class)
        ->set('login', 'nonaktif-login@tes.test')->set('password', 'rahasia123')
        ->call('masuk')
        ->assertHasErrors(['login']);

    $this->assertGuest();
});

it('login dikunci setelah lima percobaan gagal', function () {
    userDengan('Admin Keuangan', ['email' => 'staf-login@tes.test']);
    RateLimiter::clear(\Illuminate\Support\Str::lower('staf-login@tes.test').'|127.0.0.1');

    $c = Livewire::test(Login::class)->set('login', 'staf-login@tes.test')->set('password', 'salah');
    for ($i = 0; $i < 5; $i++) {
        $c->call('masuk');
    }

    $c->set('password', 'rahasia123')->call('masuk')->assertHasErrors(['login']);
    expect($c->errors()->first('login'))->toContain('Terlalu banyak percobaan');
    $this->assertGuest();
});

it('wajib ganti password diarahkan ke halaman ganti password setelah masuk', function () {
    $u = userDengan('Admin Keuangan', ['must_change_password' => true]);

    $this->actingAs($u)->get(route('dashboard'))->assertRedirect(route('password.ganti'));
});
