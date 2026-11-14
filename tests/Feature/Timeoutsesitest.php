<?php

use App\Http\Middleware\TimeoutSesiIdle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Symfony\Component\HttpKernel\Exception\HttpException;

// FR-004: sesi berakhir setelah tidak ada aktivitas.

beforeEach(function () {
    config(['session.idle_timeout' => 30]);
    $this->admin = userDengan('Admin Keuangan');
});

/** Permintaan Livewire tiruan lewat middleware langsung; mengembalikan [session, middleware, request]. */
function requestLivewireTiruan(User $user, array $calls, array $updates = [], ?int $terakhir = null): array
{
    $request = Request::create('/livewire/update', 'POST', [
        'components' => [['snapshot' => '{}', 'updates' => $updates, 'calls' => $calls]],
    ]);
    $request->headers->set('X-Livewire', 'true');

    $session = new Store('tes', new ArraySessionHandler(120));
    if ($terakhir !== null) {
        $session->put('aktivitas_terakhir', $terakhir);
    }
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);

    return [$session, new TimeoutSesiIdle, $request];
}

function panggilanTiruan(string $method): array
{
    return [['path' => '', 'method' => $method, 'params' => []]];
}

it('tidak aktif lebih dari batas: dikeluarkan dan diarahkan ke login dengan pesan', function () {
    $this->actingAs($this->admin)
        ->withSession(['aktivitas_terakhir' => now()->subMinutes(31)->getTimestamp()])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('masih dalam batas: tetap masuk dan penanda aktivitas diperbarui', function () {
    $lama = now()->subMinutes(10)->getTimestamp();

    $this->actingAs($this->admin)
        ->withSession(['aktivitas_terakhir' => $lama])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSessionHas('aktivitas_terakhir', fn ($v) => $v > $lama);
});

it('request pertama tanpa penanda tidak mengeluarkan dan membuat penanda awal', function () {
    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSessionHas('aktivitas_terakhir');
});

it('batas 0 menonaktifkan timeout', function () {
    config(['session.idle_timeout' => 0]);

    $this->actingAs($this->admin)
        ->withSession(['aktivitas_terakhir' => now()->subDays(2)->getTimestamp()])
        ->get(route('dashboard'))
        ->assertOk();
});

it('tamu tidak terpengaruh', function () {
    $this->get(route('login'))->assertOk();
});

it('polling Livewire tidak menyegarkan penanda aktivitas', function () {
    $lama = now()->subMinutes(10)->getTimestamp();

    foreach (['$refresh', 'periksaStatus'] as $method) {
        [$session, $mw, $request] = requestLivewireTiruan($this->admin, panggilanTiruan($method), [], $lama);

        $mw->handle($request, fn () => response('ok'));

        expect($session->get('aktivitas_terakhir'))->toBe($lama);
    }
});

it('aksi pengguna lewat Livewire menyegarkan penanda aktivitas', function () {
    $lama = now()->subMinutes(10)->getTimestamp();

    [$session, $mw, $request] = requestLivewireTiruan($this->admin, panggilanTiruan('simpan'), [], $lama);
    $mw->handle($request, fn () => response('ok'));

    expect($session->get('aktivitas_terakhir'))->toBeGreaterThan($lama);
});

it('mengetik di isian (updates) dihitung sebagai aktivitas walau tanpa aksi', function () {
    $lama = now()->subMinutes(10)->getTimestamp();

    [$session, $mw, $request] = requestLivewireTiruan($this->admin, panggilanTiruan('$refresh'), ['cari' => 'andi'], $lama);
    $mw->handle($request, fn () => response('ok'));

    expect($session->get('aktivitas_terakhir'))->toBeGreaterThan($lama);
});

it('polling pada sesi yang sudah kedaluwarsa dijawab 419 dan sesi dibuang', function () {
    [$session, $mw, $request] = requestLivewireTiruan(
        $this->admin, panggilanTiruan('$refresh'), [], now()->subMinutes(31)->getTimestamp()
    );

    try {
        $mw->handle($request, fn () => response('ok'));
        $status = null;
    } catch (HttpException $e) {
        $status = $e->getStatusCode();
    }

    expect($status)->toBe(419)->and($session->has('aktivitas_terakhir'))->toBeFalse();
});
