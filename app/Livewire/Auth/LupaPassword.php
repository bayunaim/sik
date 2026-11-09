<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class LupaPassword extends Component
{
    public string $email = '';
    public bool $terkirim = false;

    public function kirim(): void
    {
        $this->validate(
            ['email' => 'required|email|max:255'],
            ['email.required' => 'Email wajib diisi.', 'email.email' => 'Format email tidak valid.']
        );

        $kunci = 'lupa-password:'.request()->ip();
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            $this->addError('email', 'Terlalu banyak permintaan. Coba lagi dalam '.ceil(RateLimiter::availableIn($kunci) / 60).' menit.');
            return;
        }
        RateLimiter::hit($kunci, 900);

        $user = User::where('email', strtolower(trim($this->email)))->first();

        // hanya staf aktif; hasilnya tidak ditampilkan agar akun tidak bisa ditebak
        if ($user && $user->is_active && ! $user->hasRole('Mahasiswa')) {
            Password::sendResetLink(['email' => $user->email]);
        }

        $this->terkirim = true;
    }

    public function render()
    {
        return view('livewire.auth.lupa-password');
    }
}
