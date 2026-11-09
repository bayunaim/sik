<?php

use App\Livewire\Auth\AturUlangPassword;
use App\Livewire\Auth\LupaPassword;
use App\Livewire\Master\Mahasiswa\Index as MahasiswaIndex;
use App\Services\AkunMahasiswaService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

it('mengirim tautan reset ke staf aktif', function () {
    Notification::fake();
    $u = userDengan('Admin Keuangan');

    Livewire::test(LupaPassword::class)->set('email', $u->email)->call('kirim')->assertSet('terkirim', true);

    Notification::assertSentTo($u, ResetPassword::class);
});

it('tidak mengirim ke mahasiswa, akun nonaktif, atau email tak dikenal, dengan respons yang sama', function () {
    Notification::fake();
    $mhs = userDengan('Mahasiswa');
    $nonaktif = userDengan('Operator', ['is_active' => false]);

    foreach ([$mhs->email, $nonaktif->email, 'tidak-ada@kampus.test'] as $email) {
        Livewire::test(LupaPassword::class)->set('email', $email)->call('kirim')->assertSet('terkirim', true);
    }

    Notification::assertNothingSent();
});

it('mengatur ulang password dengan token valid, hanya sekali pakai', function () {
    $u = userDengan('Admin Keuangan', ['must_change_password' => true]);
    $token = Password::createToken($u);

    Livewire::test(AturUlangPassword::class, ['token' => $token])
        ->set('email', $u->email)->set('password', 'passwordbaru1')->set('password_confirmation', 'passwordbaru1')
        ->call('simpan')->assertRedirect(route('login'));

    $u->refresh();
    expect(Hash::check('passwordbaru1', $u->password))->toBeTrue()->and($u->must_change_password)->toBeFalse();

    Livewire::test(AturUlangPassword::class, ['token' => $token])
        ->set('email', $u->email)->set('password', 'lainlagi123')->set('password_confirmation', 'lainlagi123')
        ->call('simpan')->assertHasErrors('email');
    expect(Hash::check('passwordbaru1', $u->refresh()->password))->toBeTrue();
});

it('menolak token yang salah', function () {
    $u = userDengan('Admin Keuangan');

    Livewire::test(AturUlangPassword::class, ['token' => 'salah'])
        ->set('email', $u->email)->set('password', 'passwordbaru1')->set('password_confirmation', 'passwordbaru1')
        ->call('simpan')->assertHasErrors('email');
});

it('admin keuangan mereset password mahasiswa ke NIM dan wajib ganti', function () {
    $m = buatMahasiswa(['nim' => '0456']);
    app(AkunMahasiswaService::class)->buat(100);
    $user = $m->refresh()->user;
    $user->forceFill(['password' => 'sudahDiganti1', 'must_change_password' => false])->save();

    Livewire::actingAs(userDengan('Admin Keuangan'))->test(MahasiswaIndex::class)->call('resetPassword', $m->id);

    $user->refresh();
    expect(Hash::check('0456', $user->password))->toBeTrue()->and($user->must_change_password)->toBeTrue();
});

it('operator tidak boleh mereset password mahasiswa', function () {
    $m = buatMahasiswa();
    app(AkunMahasiswaService::class)->buat(100);

    Livewire::actingAs(userDengan('Operator'))->test(MahasiswaIndex::class)
        ->call('resetPassword', $m->id)->assertForbidden();
});
