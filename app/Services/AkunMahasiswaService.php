<?php

namespace App\Services;

use App\Models\Mahasiswa;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AkunMahasiswaService
{
    public const BATCH = 100;

    protected function query()
    {
        return Mahasiswa::whereNull('user_id')->whereIn('status_akademik', ['Aktif', 'Cuti']);
    }

    public function sisa(): int
    {
        return $this->query()->count();
    }

    /** Buat akun untuk satu batch mahasiswa yang belum punya akun. Password awal = NIM, wajib diganti. */
    public function buat(int $batas = self::BATCH, ?User $oleh = null): array
    {
        $dibuat = 0;
        $dihubungkan = 0;

        $daftar = $this->query()->orderBy('id')->limit($batas)->get();

        // audit log dicatat satu entri per batch, bukan per akun
        activity()->withoutLogs(function () use ($daftar, &$dibuat, &$dihubungkan) {
            foreach ($daftar as $m) {
                DB::transaction(function () use ($m, &$dibuat, &$dihubungkan) {
                    $ada = User::where('username', $m->nim)->first();

                    if ($ada) {
                        // akun sudah ada tapi belum tertaut ke data mahasiswa
                        $m->update(['user_id' => $ada->id]);
                        $dihubungkan++;
                        return;
                    }

                    $user = User::create([
                        'name' => $m->nama,
                        'email' => strtolower($m->nim).'@mahasiswa.local',
                        'username' => $m->nim,
                        'password' => $m->nim,
                        'is_active' => true,
                        'must_change_password' => true,
                    ]);
                    $user->assignRole('Mahasiswa');
                    $m->update(['user_id' => $user->id]);
                    $dibuat++;
                });
            }
        });

        if ($dibuat + $dihubungkan > 0) {
            activity('users')->causedBy($oleh)
                ->withProperties(['dibuat' => $dibuat, 'dihubungkan' => $dihubungkan])
                ->log('akun mahasiswa dibuat dari NIM');
        }

        return ['dibuat' => $dibuat, 'dihubungkan' => $dihubungkan, 'sisa' => $this->sisa()];
    }
}
