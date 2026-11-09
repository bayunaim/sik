<?php

use App\Models\Mahasiswa;
use App\Models\ProgramStudi;
use App\Models\ImportLog;
use App\Services\ImportMahasiswaService;

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
