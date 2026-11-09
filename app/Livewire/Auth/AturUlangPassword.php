<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class AturUlangPassword extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function simpan()
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
        ], [
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak sama.',
        ]);

        $gagal = 'Tautan tidak valid atau sudah kedaluwarsa. Minta tautan baru.';

        $user = User::where('email', strtolower(trim($this->email)))->first();
        if (! $user || ! $user->is_active || $user->hasRole('Mahasiswa')) {
            $this->addError('email', $gagal);
            return;
        }

        $status = Password::reset(
            ['email' => $user->email, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function (User $u, string $password) {
                $u->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                    'remember_token' => Str::random(60),
                ])->save();

                // putuskan semua sesi lama akun ini
                DB::table('sessions')->where('user_id', $u->id)->delete();

                activity('users')->performedOn($u)->causedBy($u)->log('password direset lewat email');
                event(new PasswordReset($u));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', $gagal);
            return;
        }

        session()->flash('sukses', 'Password berhasil diubah. Silakan masuk dengan password baru.');

        return redirect()->route('login');
    }

    public function render()
    {
        return view('livewire.auth.atur-ulang-password');
    }
}
