<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\StatusKeuanganController;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BuatTokenSia extends Command
{
    protected $signature = 'sia:buat-token {--cabut : Cabut semua token lama sebelum membuat yang baru}';

    protected $description = 'Buat token API untuk Sistem SIA (hanya boleh membaca status keuangan, FR-152)';

    public function handle(): int
    {
        // Akun mesin: tanpa role, tidak bisa login ke aplikasi (password acak), hanya dipakai pemilik token.
        $user = User::firstOrCreate(
            ['email' => 'sia@sistem.local'],
            ['name' => 'Sistem SIA', 'password' => Str::random(64), 'is_active' => true],
        );

        if ($this->option('cabut')) {
            $user->tokens()->delete();
        }

        $token = $user->createToken('sia', [StatusKeuanganController::ABILITY])->plainTextToken;

        $this->info('Token dibuat. Simpan sekarang, tidak akan ditampilkan lagi:');
        $this->line($token);

        return self::SUCCESS;
    }
}
