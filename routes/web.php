<?php

use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Livewire\Master\ProgramStudi\Index as ProdiIndex;
use App\Livewire\Master\TahunAnggaran\Index as TahunIndex;
use App\Livewire\Master\ChartOfAccount\Index as CoaIndex;

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});

Route::prefix('master')->name('master.')->group(function () {
    Route::get('/program-studi', ProdiIndex::class)->name('prodi.index');
    Route::get('/tahun-anggaran', TahunIndex::class)->name('tahun.index');
    Route::get('/chart-of-accounts', CoaIndex::class)->name('coa.index');
});
