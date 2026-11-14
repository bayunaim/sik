<?php

use App\Console\Commands\BackupDatabase;
use Illuminate\Support\Facades\Storage;

/* Backup database: pelaporan gagal dan masa simpan. Pemanggilan mysqldump asli tidak diuji di sini. */

it('backup yang gagal dilaporkan ke SuperAdmin dan tidak meninggalkan berkas', function () {
    Storage::fake('local');
    config(['backup.mysqldump' => '/tidak/ada/mysqldump']);
    $su = userDengan('SuperAdmin');

    $this->artisan('db:backup')->assertFailed();

    expect(Storage::disk('local')->allFiles('backup'))->toBe([])
        ->and($su->notifications()->where('data->ref', 'like', 'backup-gagal:%')->count())->toBe(1);
});

it('berkas backup lama dihapus tetapi tiga yang terbaru selalu disisakan', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    foreach ([50, 45, 40, 35, 30] as $i => $umur) {
        $f = "backup/sik-2026010{$i}-000000.sql.gz";
        $disk->put($f, 'x');
        touch($disk->path($f), time() - $umur * 86400);
    }
    $disk->put('backup/catatan.txt', 'bukan backup');

    $terhapus = app(BackupDatabase::class)->hapusLama(14);

    expect($terhapus)->toBe(2)
        ->and($disk->exists('backup/sik-20260100-000000.sql.gz'))->toBeFalse()
        ->and($disk->exists('backup/sik-20260101-000000.sql.gz'))->toBeFalse()
        ->and($disk->exists('backup/sik-20260104-000000.sql.gz'))->toBeTrue()
        ->and($disk->exists('backup/catatan.txt'))->toBeTrue();
});

it('uji restore gagal dengan jelas bila belum ada backup', function () {
    Storage::fake('local');
    $su = userDengan('SuperAdmin');

    $this->artisan('db:uji-restore')->assertFailed();

    expect($su->notifications()->where('data->ref', 'like', 'uji-restore-gagal:%')->count())->toBe(1);
});
