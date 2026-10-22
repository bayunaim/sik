<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\KomponenBiaya;
use Illuminate\Database\Seeder;

class KomponenBiayaSeeder extends Seeder
{
    public function run(): void
    {
        // [kode, nama, jenis, kode akun pendapatan]
        $data = [
            ['DAFTAR', 'Biaya Pendaftaran', 'pendaftaran', '4110'],
            ['SPP', 'SPP/UKT', 'spp_ukt', '4120'],
            ['SEMESTER', 'Biaya Semester', 'semester', '4130'],
            ['PRAKTIKUM', 'Biaya Praktikum', 'praktikum', '4140'],
            ['UJIAN', 'Biaya Ujian', 'ujian', '4150'],
            ['WISUDA', 'Biaya Wisuda', 'wisuda', '4160'],
        ];

        foreach ($data as [$kode, $nama, $jenis, $kodeAkun]) {
            KomponenBiaya::updateOrCreate(
                ['kode' => $kode],
                [
                    'nama' => $nama,
                    'jenis' => $jenis,
                    'akun_pendapatan_id' => ChartOfAccount::where('kode', $kodeAkun)->value('id'),
                    'is_active' => true,
                ]
            );
        }
    }
}
