<?php

use App\Models\Mahasiswa;
use App\Models\ProgramStudi;
use App\Models\ImportLog;
use App\Services\ImportMahasiswaService;
use App\Contracts\SiaProvider;
use App\Jobs\ProsesImportMahasiswa;
use App\Livewire\Import\Index as ImportIndex;
use App\Services\Sia\CsvImportProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function bacaPalsu(array $baris): array
{
    $header = ['NIM', 'Nama', 'Kode Prodi', 'Angkatan', 'Status'];
    $data = [];
    foreach ($baris as $i => $b) {
        $data[] = ['baris' => $i + 2, 'sel' => $b];
    }

    return ['header' => $header, 'data' => $data];
}

beforeEach(function () {
    $this->svc = app(ImportMahasiswaService::class);
    $this->peta = ['nim' => 'NIM', 'nama' => 'Nama', 'kode_prodi' => 'Kode Prodi', 'angkatan' => 'Angkatan', 'status' => 'Status', 'semester' => '', 'nama_prodi' => ''];
    $this->admin = userDengan('Admin Keuangan');
});

it('mengklasifikasi baru, berubah, sama, dan tidak valid', function () {
    buatMahasiswa(['nim' => '1001', 'nama' => 'Sama', 'angkatan' => 2024]);
    buatMahasiswa(['nim' => '1002', 'nama' => 'Lama', 'angkatan' => 2024]);

    $a = $this->svc->analisis(bacaPalsu([
        ['1001', 'Sama', 'TI', 2024, 'Aktif'],
        ['1002', 'Nama Baru', 'TI', 2024, 'Aktif'],
        ['1003', 'Baru', 'TI', 2025, 'Aktif'],
        ['', 'Tanpa NIM', 'TI', 2025, 'Aktif'],
        ['1004', 'Angkatan Salah', 'TI', 'abc', 'Aktif'],
        ['1003', 'Ganda', 'TI', 2025, 'Aktif'],
    ]), $this->peta);

    expect($a['sama'])->toBe(1)->and($a['berubah'])->toBe(1)->and($a['baru'])->toBe(1)
        ->and($a['error'])->toHaveCount(3);
});

it('menyimpan dengan upsert, membuat prodi otomatis, dan melewati baris error', function () {
    $log = ImportLog::create(['user_id' => $this->admin->id, 'nama_file' => 'x.xlsx']);

    $hasil = $this->svc->simpan($log->id, bacaPalsu([
        ['2001', 'Andi', 'SI', 2025, 'Aktif'],
        ['2002', 'Budi', 'SI', 2025, 'Cuti'],
        ['', 'Rusak', 'SI', 2025, 'Aktif'],
    ]), $this->peta, false, $this->admin);

    expect($hasil->status)->toBe('selesai')->and($hasil->jumlah_baru)->toBe(2)->and($hasil->jumlah_gagal)->toBe(1);
    expect(ProgramStudi::where('kode', 'SI')->exists())->toBeTrue()
        ->and(Mahasiswa::where('nim', '2002')->value('status_akademik'))->toBe('Cuti');
});

it('tidak memproses import yang sama dua kali', function () {
    $log = ImportLog::create(['user_id' => $this->admin->id, 'nama_file' => 'x.xlsx']);
    $baca = bacaPalsu([['3001', 'Andi', 'TI', 2025, 'Aktif']]);
    $this->svc->simpan($log->id, $baca, $this->peta, false, $this->admin);

    expect(fn () => $this->svc->simpan($log->id, $baca, $this->peta, false, $this->admin))
        ->toThrow(DomainException::class, 'sudah diproses');
    expect(Mahasiswa::where('nim', '3001')->count())->toBe(1);
});

it('menandai yang tidak ada di file tanpa menghapus, dan memulihkan yang soft delete', function () {
    buatMahasiswa(['nim' => '4001']);
    $dihapus = buatMahasiswa(['nim' => '4002']);
    $dihapus->delete();
    $log = ImportLog::create(['user_id' => $this->admin->id, 'nama_file' => 'x.xlsx']);

    $this->svc->simpan($log->id, bacaPalsu([['4002', 'Kembali', 'TI', 2026, 'Aktif']]), $this->peta, true, $this->admin);

    expect(Mahasiswa::where('nim', '4001')->value('status_akademik'))->toBe('Tidak Ada di Import')
        ->and(Mahasiswa::where('nim', '4002')->exists())->toBeTrue();
});

// ---- FR-144: import lewat antrean ----

/** Provider yang membaca baris palsu, supaya test job tidak bergantung pada file di disk. */
function providerPalsu(array $baris): SiaProvider
{
    return new class(app(ImportMahasiswaService::class), $baris) extends CsvImportProvider
    {
        public function __construct(ImportMahasiswaService $svc, private array $baris)
        {
            parent::__construct($svc);
        }

        public function baca(string $path): array
        {
            return bacaPalsu($this->baris);
        }
    };
}

it('job antrean menyimpan data, menandai selesai, dan menghapus file sumber', function () {
    Storage::fake('local');
    Storage::disk('local')->put('import-gofeeder/a.csv', 'x');
    $log = ImportLog::create(['user_id' => $this->admin->id, 'nama_file' => 'a.csv', 'file_path' => 'import-gofeeder/a.csv', 'status' => 'antre']);

    (new ProsesImportMahasiswa($log->id, $this->peta, false, $this->admin->id))
        ->handle(providerPalsu([['5001', 'Andi', 'TI', 2025, 'Aktif'], ['5002', 'Budi', 'TI', 2025, 'Aktif']]));

    $log->refresh();
    expect($log->status)->toBe('selesai')->and($log->jumlah_baru)->toBe(2)->and($log->file_path)->toBeNull();
    expect(Mahasiswa::whereIn('nim', ['5001', '5002'])->count())->toBe(2);
    Storage::disk('local')->assertMissing('import-gofeeder/a.csv');
});

it('job antrean yang dijalankan dua kali tidak menyimpan ganda', function () {
    $log = ImportLog::create(['user_id' => $this->admin->id, 'nama_file' => 'a.csv', 'file_path' => 'import-gofeeder/a.csv', 'status' => 'antre']);
    $provider = providerPalsu([['5101', 'Andi', 'TI', 2025, 'Aktif']]);
    $job = new ProsesImportMahasiswa($log->id, $this->peta, false, $this->admin->id);

    $job->handle($provider);
    $job->handle($provider); // status sudah selesai: diabaikan, tanpa error

    expect(Mahasiswa::where('nim', '5101')->count())->toBe(1)
        ->and($log->fresh()->status)->toBe('selesai');
});

it('job yang gagal menandai log gagal, mencatat alasan, dan menghapus file sumber', function () {
    Storage::fake('local');
    Storage::disk('local')->put('import-gofeeder/b.csv', 'x');
    $log = ImportLog::create(['user_id' => $this->admin->id, 'nama_file' => 'b.csv', 'file_path' => 'import-gofeeder/b.csv', 'status' => 'berjalan']);

    (new ProsesImportMahasiswa($log->id, $this->peta, false, $this->admin->id))
        ->failed(new DomainException('File terlalu besar'));

    $log->refresh();
    expect($log->status)->toBe('gagal')->and($log->catatan)->toBe('File terlalu besar')->and($log->file_path)->toBeNull();
    Storage::disk('local')->assertMissing('import-gofeeder/b.csv');
});

it('error sistem tidak membocorkan pesan internal ke catatan log', function () {
    $log = ImportLog::create(['user_id' => $this->admin->id, 'nama_file' => 'c.csv', 'status' => 'berjalan']);

    (new ProsesImportMahasiswa($log->id, $this->peta, false, $this->admin->id))
        ->failed(new RuntimeException('SQLSTATE[HY000]: rahasia'));

    expect($log->fresh()->catatan)->not->toContain('SQLSTATE')->and($log->fresh()->status)->toBe('gagal');
});

it('tombol simpan hanya mengantrekan job dan halaman menunggu giliran', function () {
    Storage::fake('local');
    Queue::fake();
    $this->actingAs($this->admin);

    $csv = UploadedFile::fake()->createWithContent('mhs.csv', "NIM,Nama,Kode Prodi,Angkatan,Status\n6001,Andi,TI,2025,Aktif\n");

    Livewire::test(ImportIndex::class)
        ->set('berkas', $csv)
        ->call('unggah')
        ->call('buatPratinjau')
        ->call('proses')
        ->assertSet('langkah', 4)
        ->assertSet('statusProses', 'antre')
        ->assertSee('Menunggu giliran');

    Queue::assertPushed(ProsesImportMahasiswa::class, fn ($j) => $j->tandaiHilang === false && $j->userId === $this->admin->id);
    expect(ImportLog::latest('id')->first()->status)->toBe('antre')
        ->and(Mahasiswa::where('nim', '6001')->exists())->toBeFalse(); // belum disimpan di request web
});
