<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        // [kode, nama, tipe, kode induk]
        $akun = [
            ['1000', 'Aset', 'aset', null],
            ['1100', 'Kas dan Bank', 'aset', '1000'],
            ['1110', 'Kas', 'aset', '1100'],
            ['1120', 'Bank', 'aset', '1100'],
            ['1200', 'Piutang Mahasiswa', 'aset', '1000'],

            ['2000', 'Kewajiban', 'kewajiban', null],
            ['2100', 'Pendapatan Diterima Dimuka', 'kewajiban', '2000'],

            ['3000', 'Ekuitas', 'ekuitas', null],
            ['3100', 'Saldo Awal / Modal', 'ekuitas', '3000'],
            ['3200', 'Surplus / Defisit Berjalan', 'ekuitas', '3000'],

            ['4000', 'Pendapatan', 'pendapatan', null],
            ['4100', 'Pendapatan Pendidikan', 'pendapatan', '4000'],
            ['4110', 'Pendapatan Pendaftaran', 'pendapatan', '4100'],
            ['4120', 'Pendapatan SPP/UKT', 'pendapatan', '4100'],
            ['4130', 'Pendapatan Biaya Semester', 'pendapatan', '4100'],
            ['4140', 'Pendapatan Praktikum', 'pendapatan', '4100'],
            ['4150', 'Pendapatan Ujian', 'pendapatan', '4100'],
            ['4160', 'Pendapatan Wisuda', 'pendapatan', '4100'],
            ['4190', 'Pendapatan Pendidikan Lainnya', 'pendapatan', '4100'],
            ['4200', 'Pendapatan Non-Pendidikan', 'pendapatan', '4000'],
            ['4210', 'Hibah dan Donasi', 'pendapatan', '4200'],
            ['4290', 'Pendapatan Lain-lain', 'pendapatan', '4200'],

            ['5000', 'Beban', 'beban', null],
            ['5100', 'Beban Operasional', 'beban', '5000'],
            ['5110', 'Beban Gaji dan Honor', 'beban', '5100'],
            ['5120', 'Beban Kegiatan Akademik', 'beban', '5100'],
            ['5130', 'Beban Administrasi dan Umum', 'beban', '5100'],
            ['5140', 'Beban Listrik, Air, dan Internet', 'beban', '5100'],
            ['5150', 'Beban Pemeliharaan', 'beban', '5100'],
            ['5190', 'Beban Lain-lain', 'beban', '5100'],
        ];

        foreach ($akun as [$kode, $nama, $tipe, $kodeInduk]) {
            $parentId = $kodeInduk
                ? ChartOfAccount::where('kode', $kodeInduk)->value('id')
                : null;

            ChartOfAccount::updateOrCreate(
                ['kode' => $kode],
                ['nama' => $nama, 'tipe' => $tipe, 'parent_id' => $parentId, 'is_active' => true]
            );
        }
    }
}
