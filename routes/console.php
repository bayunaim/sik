<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-130: pengingat jatuh tempo ke mahasiswa. Butuh cron: * * * * * php artisan schedule:run
Schedule::command('notifikasi:jatuh-tempo')->dailyAt('07:00')->withoutOverlapping();
