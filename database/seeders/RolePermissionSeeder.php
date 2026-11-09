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
        // Bersihkan cache permission
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Daftar seluruh permission
        $permissions = [
            // pengguna
            'user.kelola',

            // master data
            'master.lihat',
            'master.kelola',

            // anggaran
            'anggaran.lihat',
            'anggaran.buat',
            'anggaran.approve',

            // tagihan
            'tagihan.lihat',
            'tagihan.buat',
            'tagihan.ubah-nominal',
            'tagihan.batalkan',

            // pembayaran
            'pembayaran.lihat',
            'pembayaran.input',
            'pembayaran.verifikasi',

            // penerimaan & pengeluaran
            'penerimaan.lihat',
            'penerimaan.input',
            'pengeluaran.lihat',
            'pengeluaran.ajukan',
            'pengeluaran.approve',

            // jurnal & laporan
            'jurnal.lihat',
            'jurnal.penyesuaian',
            'laporan.lihat',
            'laporan.ekspor',

            // rekonsiliasi, integrasi, tutup tahun
            'rekonsiliasi.kelola',
            'import.sia',
            'tahun.tutup',

            // portal mahasiswa
            'portal.lihat',
            'portal.upload-bukti',
            'mahasiswa.reset-password',
        ];

        // Buat permission jika belum tersedia
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Bersihkan cache setelah pembuatan permission
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ==========================================
        // ROLE SUPER ADMIN
        // ==========================================

        // SuperAdmin mendapatkan semua akses melalui Gate::before
        Role::findOrCreate('SuperAdmin', 'web');

        // ==========================================
        // ROLE ADMIN KEUANGAN
        // ==========================================

        Role::findOrCreate('Admin Keuangan', 'web')->syncPermissions([
            'master.lihat',
            'master.kelola',

            'anggaran.lihat',
            'anggaran.buat',
            'anggaran.approve',

            'tagihan.lihat',
            'tagihan.buat',
            'tagihan.ubah-nominal',
            'tagihan.batalkan',

            'pembayaran.lihat',
            'pembayaran.input',
            'pembayaran.verifikasi',

            'penerimaan.lihat',
            'penerimaan.input',

            'pengeluaran.lihat',
            'pengeluaran.ajukan',
            'pengeluaran.approve',

            'jurnal.lihat',
            'jurnal.penyesuaian',

            'laporan.lihat',
            'laporan.ekspor',

            'rekonsiliasi.kelola',
            'import.sia',

            // Reset password mahasiswa
            'mahasiswa.reset-password',
        ]);

        // ==========================================
        // ROLE OPERATOR
        // ==========================================

        Role::findOrCreate('Operator', 'web')->syncPermissions([
            'master.lihat',

            'anggaran.lihat',
            'anggaran.buat', // Hanya Draft (dicek di Policy)

            'tagihan.lihat',
            'tagihan.buat',

            'pembayaran.lihat',
            'pembayaran.input',

            'penerimaan.lihat',

            'pengeluaran.lihat',
            'pengeluaran.ajukan',
        ]);

        // ==========================================
        // ROLE PIMPINAN
        // ==========================================

        Role::findOrCreate('Pimpinan', 'web')->syncPermissions([
            'anggaran.lihat',
            'tagihan.lihat',
            'pembayaran.lihat',

            'penerimaan.lihat',

            'pengeluaran.lihat',
            'pengeluaran.approve',

            'jurnal.lihat',

            'laporan.lihat',
            'laporan.ekspor',
        ]);

        // ==========================================
        // ROLE MAHASISWA
        // ==========================================

        Role::findOrCreate('Mahasiswa', 'web')->syncPermissions([
            'portal.lihat',
            'portal.upload-bukti',
        ]);

        // ==========================================
        // AKUN SUPER ADMIN AWAL
        // ==========================================

        // Ganti password setelah login pertama
        $admin = User::firstOrCreate(
            ['email' => 'admin@kampus.test'],
            [
                'name' => 'Super Admin',
                'password' => 'ganti-password-ini',
                'is_active' => true,
            ]
        );

        $admin->assignRole('SuperAdmin');

        // Bersihkan cache terakhir
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
