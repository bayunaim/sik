<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class GantiPassword extends Component
{
    public string $password_lama = '';
    public string $password = '';
    public string $password_confirmation = '';

    protected function rules(): array
    {
        return [
            'password_lama' => ['required'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed', 'different:password_lama'],
        ];
    }

    protected function messages(): array
    {
        return [
            'password_lama.required' => 'Password saat ini wajib diisi.',
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak sama.',
            'password.different' => 'Password baru harus berbeda dari password saat ini.',
        ];
    }

    public function simpan()
    {
        $this->validate();
        $user = auth()->user();

        if (! Hash::check($this->password_lama, $user->password)) {
            $this->addError('password_lama', 'Password saat ini salah.');
            return;
        }

        if ($user->username && strcasecmp($this->password, $user->username) === 0) {
            $this->addError('password', 'Password tidak boleh sama dengan NIM.');
            return;
        }

        $user->forceFill(['password' => $this->password, 'must_change_password' => false])->save();

        activity('users')->performedOn($user)->causedBy($user)->log('password diubah');
        session()->regenerate();
        session()->flash('sukses', 'Password berhasil diubah.');

        return redirect()->to($user->hasRole('Mahasiswa') ? route('portal.index') : route('dashboard'));
    }

    public function render()
    {
        return view('livewire.auth.ganti-password');
    }
}
