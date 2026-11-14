<?php

use App\Models\ImportLog;
use App\Models\User;
use App\Services\NotifikasiService;
use Illuminate\Support\Carbon;

// FR-145: pengingat import GoFeeder ke Admin (mingguan dan awal semester).

beforeEach(function () {
    config([
        'sia.pengingat_import.hari_maksimal' => 7,
        'sia.pengingat_import.awal_semester' => [],
        'sia.pengingat_import.jendela_awal_semester' => 14,
    ]);
    $this->admin = userDengan('Admin Keuangan');

    // RolePermissionSeeder membuat akun SuperAdmin bawaan yang juga berhak impor; nonaktifkan
    // agar hanya $this->admin yang menjadi penerima dan jumlahnya pasti.
    User::role('SuperAdmin')->get()->each->update(['is_active' => false]);
});

/** Import berhasil yang selesai pada tanggal tertentu (dilakukan Operator supaya bukan penerima pengingat). */
function importSelesaiPada(string $tanggal): ImportLog
{
    $log = ImportLog::create(['user_id' => userDengan('Operator')->id, 'nama_file' => 'mhs.csv', 'status' => 'selesai']);
    ImportLog::whereKey($log->id)->toBase()->update(['updated_at' => Carbon::parse($tanggal)->setTime(10, 0)]);

    return $log;
}

function kirimPengingat(string $hari): int
{
    return app(NotifikasiService::class)->kirimPengingatImport(Carbon::parse($hari));
}

it('belum pernah ada import: pengingat mingguan hanya untuk yang berhak mengimpor', function () {
    $operator = userDengan('Operator');
    $pimpinan = userDengan('Pimpinan');

    expect(kirimPengingat('2026-11-18'))->toBe(1);

    $n = $this->admin->notifications()->first();
    expect($n->data['jenis'])->toBe('pengingat_import')
        ->and($n->data['judul'])->toContain('lebih dari 7 hari')
        ->and($n->data['pesan'])->toContain('Belum pernah')
        ->and($n->data['url'])->toBe('/import')
        ->and($operator->notifications()->count())->toBe(0)
        ->and($pimpinan->notifications()->count())->toBe(0);
});

it('import berhasil 3 hari lalu: tidak ada pengingat', function () {
    importSelesaiPada('2026-11-15');

    expect(kirimPengingat('2026-11-18'))->toBe(0)
        ->and($this->admin->notifications()->count())->toBe(0);
});

it('import terakhir lebih dari 7 hari lalu: pengingat menyebut tanggal import terakhir', function () {
    importSelesaiPada('2026-11-09');

    expect(kirimPengingat('2026-11-18'))->toBe(1);
    expect($this->admin->notifications()->first()->data['pesan'])->toContain('09/11/2026');
});

it('import yang gagal atau masih berjalan tidak dihitung sebagai import terakhir', function () {
    $log = importSelesaiPada('2026-11-17');
    $log->update(['status' => 'gagal']);

    expect(kirimPengingat('2026-11-18'))->toBe(1);
});

it('pengingat mingguan hanya sekali per minggu dan dikirim lagi minggu berikutnya', function () {
    expect(kirimPengingat('2026-11-18'))->toBe(1)
        ->and(kirimPengingat('2026-11-19'))->toBe(0)   // minggu yang sama
        ->and(kirimPengingat('2026-11-25'))->toBe(1); // minggu berikutnya, masih belum import

    expect($this->admin->notifications()->count())->toBe(2);
});

it('awal semester: dalam jendela dan belum import sejak tanggal mulai, pengingat semester didahulukan', function () {
    config(['sia.pengingat_import.awal_semester' => ['08-01']]);
    importSelesaiPada('2026-07-30'); // baru 6 hari: pengingat mingguan belum jatuh tempo

    expect(kirimPengingat('2026-08-05'))->toBe(1);

    $n = $this->admin->notifications()->first();
    expect($n->data['judul'])->toContain('awal semester')
        ->and($n->data['pesan'])->toContain('01/08/2026')
        ->and($n->data['ref'])->toBe('pengingat-import:semester:2026-08-01');
});

it('awal semester: sudah ada import sejak tanggal mulai, tidak diingatkan', function () {
    config(['sia.pengingat_import.awal_semester' => ['08-01']]);
    importSelesaiPada('2026-08-02');

    expect(kirimPengingat('2026-08-05'))->toBe(0);
});

it('awal semester: di luar jendela tidak diingatkan', function () {
    config(['sia.pengingat_import.awal_semester' => ['08-01']]);
    importSelesaiPada('2026-08-19'); // import masih baru: mingguan juga tidak jatuh tempo

    expect(kirimPengingat('2026-08-20'))->toBe(0);
});

it('pengingat awal semester sekali saja selama jendela', function () {
    config(['sia.pengingat_import.awal_semester' => ['08-01']]);
    importSelesaiPada('2026-07-30');

    expect(kirimPengingat('2026-08-03'))->toBe(1)
        ->and(kirimPengingat('2026-08-04'))->toBe(0);
});

it('tanggal awal semester yang salah format diabaikan tanpa error', function () {
    config(['sia.pengingat_import.awal_semester' => ['bukan-tanggal', '13-45']]);
    importSelesaiPada('2026-11-17');

    expect(kirimPengingat('2026-11-18'))->toBe(0);
});

it('import berhasil menandai pengingat yang belum dibaca sebagai dibaca', function () {
    kirimPengingat('2026-11-18');
    expect($this->admin->unreadNotifications()->count())->toBe(1);

    app(NotifikasiService::class)->pengingatImportSelesai();

    expect($this->admin->unreadNotifications()->count())->toBe(0);
});

it('perintah notifikasi:pengingat-import berjalan', function () {
    $this->artisan('notifikasi:pengingat-import')
        ->expectsOutputToContain('pengingat import terkirim')
        ->assertSuccessful();

    expect($this->admin->notifications()->count())->toBe(1);
});
