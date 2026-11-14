<?php

use App\Http\Controllers\BerkasController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LaporanController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\RekonsiliasiController;
use App\Http\Middleware\EnsureUserAktif;
use App\Http\Middleware\WajibGantiPassword;
use App\Livewire\Anggaran\Index as AnggaranIndex;
use App\Livewire\Auth\GantiPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Import\Index as ImportIndex;
use App\Livewire\Jurnal\Index as JurnalIndex;
use App\Livewire\Laporan\Tampil as LaporanTampil;
use App\Livewire\Master\ChartOfAccount\Index as CoaIndex;
use App\Livewire\Master\KomponenBiaya\Index as KomponenIndex;
use App\Livewire\Master\Mahasiswa\Index as MahasiswaIndex;
use App\Livewire\Master\ProgramStudi\Index as ProdiIndex;
use App\Livewire\Master\TahunAnggaran\Index as TahunIndex;
use App\Livewire\Master\TarifBiaya\Index as TarifIndex;
use App\Livewire\Pembayaran\Index as PembayaranIndex;
use App\Livewire\Penerimaan\Index as PenerimaanIndex;
use App\Livewire\Pengeluaran\Index as PengeluaranIndex;
use App\Livewire\Portal\Index as PortalIndex;
use App\Livewire\Rekonsiliasi\Import as RekonsiliasiImport;
use App\Livewire\Rekonsiliasi\Index as RekonsiliasiIndex;
use App\Livewire\Rekonsiliasi\Rekening as RekonsiliasiRekening;
use App\Livewire\Tagihan\Index as TagihanIndex;
use App\Livewire\Tagihan\Massal as TagihanMassal;
use App\Livewire\User\Index as UserIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Livewire\Auth\AturUlangPassword;
use App\Livewire\Auth\LupaPassword;

// Tamu
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
    Route::get('/lupa-password', LupaPassword::class)->name('password.request');
    Route::get('/reset-password/{token}', AturUlangPassword::class)->name('password.reset');
});

// Wajib login, akun aktif, dan sudah mengganti password awal
Route::middleware(['auth', EnsureUserAktif::class, WajibGantiPassword::class])->group(function () {
    Route::get('/', fn () => redirect()->route(
        auth()->user()->hasRole('Mahasiswa') ? 'portal.index' : 'dashboard'
    ));
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/akun/password', GantiPassword::class)->name('password.ganti');

    // Portal mahasiswa
    Route::prefix('portal')->name('portal.')->group(function () {
        Route::get('/', PortalIndex::class)->name('index');
        Route::get('/pembayaran/{pembayaran}/bukti', [PortalController::class, 'bukti'])->name('bukti');
        Route::get('/pembayaran/{pembayaran}/kwitansi', [PortalController::class, 'kwitansi'])->name('kwitansi');
        Route::get('/tagihan/{tagihan}/cetak', [PortalController::class, 'tagihan'])->name('tagihan');
        Route::get('/surat-lunas', [PortalController::class, 'suratLunas'])->name('surat-lunas');
    });

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

    Route::get('/tagihan/{tagihan}/cetak', [BerkasController::class, 'tagihan'])->name('tagihan.cetak');
    Route::get('/mahasiswa/{mahasiswa}/surat-lunas', [BerkasController::class, 'suratLunas'])->name('mahasiswa.surat-lunas');

    Route::get('/pembayaran', PembayaranIndex::class)->name('pembayaran.index');
    Route::get('/pembayaran/{pembayaran}/bukti', [BerkasController::class, 'buktiPembayaran'])->name('pembayaran.bukti');
    Route::get('/pembayaran/{pembayaran}/kwitansi', [BerkasController::class, 'kwitansi'])->name('pembayaran.kwitansi');

    Route::get('/penerimaan', PenerimaanIndex::class)->name('penerimaan.index');

    Route::get('/pengeluaran', PengeluaranIndex::class)->name('pengeluaran.index');
    Route::get('/pengeluaran/{pengeluaran}/lampiran', [BerkasController::class, 'lampiranPengeluaran'])->name('pengeluaran.lampiran');

    Route::get('/jurnal', JurnalIndex::class)->name('jurnal.index');

    // Rekonsiliasi bank
    Route::prefix('rekonsiliasi')->name('rekonsiliasi.')->group(function () {
        Route::get('/', RekonsiliasiIndex::class)->name('index');
        Route::get('/import', RekonsiliasiImport::class)->name('import');
        Route::get('/rekening', RekonsiliasiRekening::class)->name('rekening');
        Route::get('/import/{impor}/error', [RekonsiliasiController::class, 'error'])->name('import.error');
    });

    // Laporan
    Route::prefix('laporan')->name('laporan.')->group(function () {
        Route::get('/jurnal', LaporanTampil::class)->defaults('jenis', 'jurnal')->name('jurnal');
        Route::get('/tunggakan', LaporanTampil::class)->defaults('jenis', 'tunggakan')->name('tunggakan');
        Route::get('/capaian', LaporanTampil::class)->defaults('jenis', 'capaian')->name('capaian');
        Route::get('/status-keuangan', LaporanTampil::class)->defaults('jenis', 'status-keuangan')->name('status-keuangan');
        Route::get('/histori-mahasiswa', LaporanTampil::class)->defaults('jenis', 'histori-mahasiswa')->name('histori-mahasiswa');
        Route::get('/laba-rugi', LaporanTampil::class)->defaults('jenis', 'laba-rugi')->name('laba-rugi');
        Route::get('/arus-kas', LaporanTampil::class)->defaults('jenis', 'arus-kas')->name('arus-kas');
        Route::get('/buku-besar', LaporanTampil::class)->defaults('jenis', 'buku-besar')->name('buku-besar');
        Route::get('/neraca-saldo', LaporanTampil::class)->defaults('jenis', 'neraca-saldo')->name('neraca-saldo');
        Route::get('/unduh/{berkas}', [LaporanController::class, 'unduh'])->name('unduh');
        Route::get('/{jenis}/ekspor/{format}', [LaporanController::class, 'ekspor'])
            ->whereIn('jenis', ['jurnal', 'tunggakan', 'capaian', 'status-keuangan', 'histori-mahasiswa', 'laba-rugi', 'arus-kas', 'buku-besar', 'neraca-saldo'])
            ->whereIn('format', ['pdf', 'xlsx', 'csv'])
            ->name('ekspor');
    });

    // Import GoFeeder
    Route::get('/import', ImportIndex::class)->name('import.index');
    Route::get('/import/{log}/error', [ImportController::class, 'error'])->name('import.error');

    // Sistem
    Route::get('/pengguna', UserIndex::class)->name('user.index');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
