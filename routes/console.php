<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-130: pengingat jatuh tempo ke mahasiswa. Butuh cron: * * * * * php artisan schedule:run
Schedule::command('notifikasi:jatuh-tempo')->dailyAt('07:00')->withoutOverlapping();

// FR-145: pengingat import GoFeeder ke Admin (mingguan dan awal semester; sekali per periode).
Schedule::command('notifikasi:pengingat-import')->dailyAt('07:30')->withoutOverlapping();

// Hapus hasil ekspor laporan antrean yang sudah lewat masa simpan (config/laporan.php).
Schedule::call(function () {
    $disk = Storage::disk('local');
    $batas = now()->subDays((int) config('laporan.simpan_hari', 2))->getTimestamp();

    foreach ($disk->allFiles('ekspor-laporan') as $berkas) {
        if ($disk->lastModified($berkas) < $batas) {
            $disk->delete($berkas);
        }
    }
})->name('bersihkan-ekspor-laporan')->dailyAt('02:00');

// Backup database harian dan uji restore mingguan (NFR ketersediaan). Hasil gagal dikabarkan ke SuperAdmin.
Schedule::command('db:backup')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('db:uji-restore')->weeklyOn(0, '03:00')->withoutOverlapping();
