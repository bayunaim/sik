<?php

use App\Http\Controllers\Api\V1\StatusKeuanganController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// FR-152: status keuangan untuk SIA. Token Sanctum, dibatasi per menit (config/sia.php).
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:sia-api'])->group(function () {
    Route::get('/mahasiswa/{nim}/status-keuangan', [StatusKeuanganController::class, 'show'])
        ->where('nim', '[A-Za-z0-9._-]+')
        ->name('api.v1.status-keuangan');
});
