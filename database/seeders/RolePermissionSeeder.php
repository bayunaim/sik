<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // pengguna
            'user.kelola',
            // master data
            'master.lihat', 'master.kelola',
            // anggaran
            'anggaran.lihat', 'anggaran.buat', 'anggaran.approve',
            // tagihan
            'tagihan.lihat', 'tagihan.buat', 'tagihan.ubah-nominal', 'tagihan.batalkan',
            // pembayaran
            'pembayaran.lihat', 'pembayaran.input', 'pembayaran.verifikasi',
            // penerimaan & pengeluaran
            'penerimaan.lihat', 'penerimaan.input',
            'pengeluaran.lihat', 'pengeluaran.ajukan', 'pengeluaran.approve',
            // jurnal & laporan
            'jurnal.lihat', 'jurnal.penyesuaian',
            'laporan.lihat', 'laporan.ekspor',
            // rekonsiliasi, integrasi, tutup tahun
            'rekonsiliasi.kelola',
            'import.sia', 'tahun.tutup',
            // portal mahasiswa
            'portal.lihat', 'portal.upload-bukti',
        ];

        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'web');
        }

        // tambahkan ini
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // SuperAdmin: semua permission lewat Gate::before (di AppServiceProvider)
        Role::findOrCreate('SuperAdmin', 'web');

        Role::findOrCreate('Admin Keuangan', 'web')->syncPermissions([
            'master.lihat', 'master.kelola',
            'anggaran.lihat', 'anggaran.buat', 'anggaran.approve',
            'tagihan.lihat', 'tagihan.buat', 'tagihan.ubah-nominal', 'tagihan.batalkan',
            'pembayaran.lihat', 'pembayaran.input', 'pembayaran.verifikasi',
            'penerimaan.lihat', 'penerimaan.input',
            'pengeluaran.lihat', 'pengeluaran.ajukan', 'pengeluaran.approve',
            'jurnal.lihat', 'jurnal.penyesuaian',
            'laporan.lihat', 'laporan.ekspor',
            'rekonsiliasi.kelola', 'import.sia',
        ]);

        Role::findOrCreate('Operator', 'web')->syncPermissions([
            'master.lihat',
            'anggaran.lihat', 'anggaran.buat',          // hanya Draft (dicek di Policy)
            'tagihan.lihat', 'tagihan.buat',
            'pembayaran.lihat', 'pembayaran.input',
            'penerimaan.lihat',
            'pengeluaran.lihat', 'pengeluaran.ajukan',
        ]);

        Role::findOrCreate('Pimpinan', 'web')->syncPermissions([
            'anggaran.lihat', 'tagihan.lihat', 'pembayaran.lihat',
            'penerimaan.lihat', 'pengeluaran.lihat', 'pengeluaran.approve',
            'jurnal.lihat', 'laporan.lihat', 'laporan.ekspor',
        ]);

        Role::findOrCreate('Mahasiswa', 'web')->syncPermissions([
            'portal.lihat', 'portal.upload-bukti',
        ]);

        // Akun SuperAdmin awal (ganti password setelah login pertama)
        $admin = User::firstOrCreate(
            ['email' => 'admin@kampus.test'],
            [
                'name' => 'Super Admin',
                'password' => 'ganti-password-ini',   // otomatis di-hash oleh cast
                'is_active' => true,
            ]
        );
        $admin->assignRole('SuperAdmin');
    }
}
