<?php

namespace Database\Seeders;

use App\Models\Mahasiswa;
use App\Models\ProgramStudi;
use Illuminate\Database\Seeder;

class DummyMahasiswaSeeder extends Seeder
{
    public function run(): void
    {
        $prodi = ProgramStudi::where('is_active', true)->get();

        if ($prodi->isEmpty()) {
            $this->command->warn('Belum ada program studi aktif.');
            return;
        }

        $depan = ['Andi', 'Budi', 'Citra', 'Dewi', 'Eko', 'Fitri', 'Gita', 'Hadi', 'Indah', 'Joko', 'Kiki', 'Lestari'];
        $belakang = ['Pratama', 'Saputra', 'Wijaya', 'Lestari', 'Santoso', 'Hidayat', 'Putri', 'Nugroho'];

        $no = 0;
        foreach ($prodi as $p) {
            foreach ([2022, 2023, 2024, 2025] as $angkatan) {
                for ($i = 1; $i <= 8; $i++) {
                    $no++;
                    $nim = $angkatan.str_pad($p->id, 2, '0', STR_PAD_LEFT).str_pad($i, 3, '0', STR_PAD_LEFT);

                    Mahasiswa::updateOrCreate(
                        ['nim' => $nim],
                        [
                            'nama' => $depan[$no % count($depan)].' '.$belakang[($no * 3) % count($belakang)],
                            'program_studi_id' => $p->id,
                            'angkatan' => $angkatan,
                            'status_akademik' => 'Aktif',
                            'semester_aktif' => (string) ((2026 - $angkatan) * 2 + 1),
                        ]
                    );
                }
            }
        }
    }
}
