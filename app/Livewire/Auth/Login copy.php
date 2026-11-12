<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Login extends Component
{
    public string $login = '';
    public string $password = '';
    public bool $remember = false;

    protected function rules(): array
    {
        return ['login' => 'required|string|max:255', 'password' => 'required'];
    }

    protected function messages(): array
    {
        return [
            'login.required' => 'Email atau NIM wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ];
    }

    /**
     * Jangan dinamai `login`: properti `$login` sudah memakai nama itu, dan di browser
     * `wire:submit="login"` akan terbaca sebagai teks isian, bukan fungsi (form tidak mengirim apa pun).
     */
    public function masuk()
    {
        $this->validate();
        $this->ensureNotRateLimited();

        $kolom = str_contains($this->login, '@') ? 'email' : 'username';

        $berhasil = Auth::attempt([
            $kolom => trim($this->login),
            'password' => $this->password,
            'is_active' => true,
        ], $this->remember);

        if (! $berhasil) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages([
                'login' => 'Email/NIM atau password salah, atau akun tidak aktif.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        $tujuan = Auth::user()->hasRole('Mahasiswa') ? route('portal.index') : route('dashboard');

        return redirect()->intended($tujuan);
    }

    protected function ensureNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $detik = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => "Terlalu banyak percobaan login. Coba lagi dalam {$detik} detik.",
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower(trim($this->login)).'|'.request()->ip());
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
