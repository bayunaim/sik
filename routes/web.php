<?php

use App\Http\Middleware\EnsureUserAktif;
use App\Livewire\Anggaran\Index as AnggaranIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Jurnal\Index as JurnalIndex;
use App\Livewire\Master\ChartOfAccount\Index as CoaIndex;
use App\Livewire\Master\KomponenBiaya\Index as KomponenIndex;
use App\Livewire\Master\Mahasiswa\Index as MahasiswaIndex;
use App\Livewire\Master\ProgramStudi\Index as ProdiIndex;
use App\Livewire\Master\TahunAnggaran\Index as TahunIndex;
use App\Livewire\Master\TarifBiaya\Index as TarifIndex;
use App\Livewire\Pembayaran\Index as PembayaranIndex;
use App\Livewire\Tagihan\Index as TagihanIndex;
use App\Livewire\Tagihan\Massal as TagihanMassal;
use App\Livewire\User\Index as UserIndex;
use App\Models\Pembayaran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Tamu
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

// Wajib login dan akun aktif
Route::middleware(['auth', EnsureUserAktif::class])->group(function () {
    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    // Data master
    Route::prefix('master')->name('master.')->group(function () {
        Route::get('/program-studi', ProdiIndex::class)->name('prodi.index');
        Route::get('/tahun-anggaran', TahunIndex::class)->name('tahun.index');
        Route::get('/chart-of-accounts', CoaIndex::class)->name('coa.index');
        Route::get('/komponen-biaya', KomponenIndex::class)->name('komponen.index');
        Route::get('/tarif-biaya', TarifIndex::class)->name('tarif.index');
        Route::get('/mahasiswa', MahasiswaIndex::class)->name('mahasiswa.index');
    });

    // Keuangan
    Route::get('/anggaran', AnggaranIndex::class)->name('anggaran.index');

    Route::get('/tagihan', TagihanIndex::class)->name('tagihan.index');
    Route::get('/tagihan/massal', TagihanMassal::class)->name('tagihan.massal');

    Route::get('/pembayaran', PembayaranIndex::class)->name('pembayaran.index');

    // Bukti bayar disimpan privat, hanya bisa dibuka user berhak
    Route::get('/pembayaran/{pembayaran}/bukti', function (Pembayaran $pembayaran) {
        abort_unless(auth()->user()->can('pembayaran.lihat'), 403);
        abort_unless($pembayaran->bukti_path && Storage::disk('local')->exists($pembayaran->bukti_path), 404);

        return response()->file(Storage::disk('local')->path($pembayaran->bukti_path));
    })->name('pembayaran.bukti');

    Route::get('/jurnal', JurnalIndex::class)->name('jurnal.index');

    // Sistem
    Route::get('/pengguna', UserIndex::class)->name('user.index');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
