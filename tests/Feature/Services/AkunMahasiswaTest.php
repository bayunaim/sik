<?php

use App\Livewire\Portal\Index as PortalIndex;
use App\Models\Pembayaran;
use App\Services\AkunMahasiswaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('membuat akun dengan username NIM, role Mahasiswa, dan wajib ganti password', function () {
    $m = buatMahasiswa(['nim' => '0123']);

    $r = app(AkunMahasiswaService::class)->buat(100);
    $u = $m->refresh()->user;

    expect($r['dibuat'])->toBe(1)
        ->and($u->username)->toBe('0123')
        ->and($u->hasRole('Mahasiswa'))->toBeTrue()
        ->and($u->must_change_password)->toBeTrue()
        ->and(Hash::check('0123', $u->password))->toBeTrue();
});

it('tidak membuat akun ganda saat dijalankan ulang', function () {
    buatMahasiswa();
    app(AkunMahasiswaService::class)->buat(100);

    expect(app(AkunMahasiswaService::class)->buat(100)['dibuat'])->toBe(0);
});

it('mahasiswa tidak bisa mengunggah bukti untuk tagihan milik orang lain', function () {
    $a = buatMahasiswa();
    $b = buatMahasiswa();
    app(AkunMahasiswaService::class)->buat(100);
    $tagihanB = buatTagihan($b);

    Livewire::actingAs($a->refresh()->user)->test(PortalIndex::class)
        ->call('bukaUpload', $tagihanB->id)
        ->assertStatus(404);
});

it('unggah bukti membuat pembayaran Pending atas nama tagihannya', function () {
    Storage::fake('local');
    $m = buatMahasiswa();
    app(AkunMahasiswaService::class)->buat(100);
    $t = buatTagihan($m, '3000000');
    $user = $m->refresh()->user;

    Livewire::actingAs($user)->test(PortalIndex::class)
        ->call('bukaUpload', $t->id)
        ->set('nominal', '1000000')
        ->set('tanggal_bayar', now()->format('Y-m-d'))
        ->set('bukti', UploadedFile::fake()->image('bukti.jpg'))
        ->call('kirim')
        ->assertHasNoErrors();

    $p = Pembayaran::firstOrFail();
    expect($p->status)->toBe('pending')
        ->and($p->tagihan_id)->toBe($t->id)
        ->and($p->diinput_oleh)->toBe($user->id)
        ->and($p->bukti_path)->not->toBeNull();
});
