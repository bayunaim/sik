<?php

namespace App\Livewire\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url(except: '')]
    public string $cari = '';
    #[Url(except: '')]
    public string $filterRole = '';

    public bool $tampilForm = false;
    public ?int $editId = null;

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $role = '';
    public string $batas_persetujuan = '';
    public bool $is_active = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('user.kelola'), 403);
    }

    public function updatingCari(): void { $this->resetPage(); }
    public function updatingFilterRole(): void { $this->resetPage(); }

    // Role yang bisa dipilih di halaman ini (Mahasiswa dikelola lewat import)
    protected function daftarRole(): array
    {
        return Role::where('name', '!=', 'Mahasiswa')->orderBy('id')->pluck('name')->all();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editId)],
            'password' => [$this->editId ? 'nullable' : 'required', 'string', 'min:8', 'max:100'],
            'role' => ['required', Rule::in($this->daftarRole())],
            'batas_persetujuan' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah dipakai user lain.',
            'password.required' => 'Password wajib diisi untuk user baru.',
            'password.min' => 'Password minimal 8 karakter.',
            'role.required' => 'Role wajib dipilih.',
            'role.in' => 'Role tidak valid.',
            'batas_persetujuan.numeric' => 'Batas persetujuan harus berupa angka.',
            'batas_persetujuan.min' => 'Batas persetujuan tidak boleh negatif.',
        ];
    }

    public function tambah(): void
    {
        abort_unless(auth()->user()->can('user.kelola'), 403);

        $this->resetForm();
        $this->tampilForm = true;
    }

    public function ubah(int $id): void
    {
        abort_unless(auth()->user()->can('user.kelola'), 403);

        $u = $this->queryStaf()->findOrFail($id);
        $this->editId = $u->id;
        $this->name = $u->name;
        $this->email = $u->email;
        $this->password = '';
        $this->role = (string) $u->getRoleNames()->first();
        $this->batas_persetujuan = $u->batas_persetujuan !== null ? (string) $u->batas_persetujuan : '';
        $this->is_active = $u->is_active;
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function simpan(): void
    {
        abort_unless(auth()->user()->can('user.kelola'), 403);

        $data = $this->validate();
        $galat = null;

        DB::transaction(function () use ($data, &$galat) {
            $user = $this->editId
                ? $this->queryStaf()->lockForUpdate()->findOrFail($this->editId)
                : new User();

            // Jaga: jangan sampai tidak ada SuperAdmin aktif, dan jangan nonaktifkan diri sendiri
            if ($user->exists) {
                $akanHilang = $user->hasRole('SuperAdmin')
                    && ($data['role'] !== 'SuperAdmin' || ! $data['is_active']);

                if ($akanHilang && ! $this->adaSuperAdminLain($user->id)) {
                    $galat = ['role', 'Harus ada minimal satu SuperAdmin aktif. Tidak bisa mencopot atau menonaktifkan yang terakhir.'];
                    return;
                }

                if ($user->id === auth()->id() && ! $data['is_active']) {
                    $galat = ['is_active', 'Anda tidak bisa menonaktifkan akun sendiri.'];
                    return;
                }
            }

            $roleLama = $user->exists ? $user->getRoleNames()->first() : null;

            $user->fill([
                'name' => trim($data['name']),
                'email' => strtolower(trim($data['email'])),
                'batas_persetujuan' => $data['batas_persetujuan'] !== '' ? $data['batas_persetujuan'] : null,
                'is_active' => $data['is_active'],
            ]);

            if ($data['password'] !== '') {
                $user->password = $data['password']; // di-hash oleh cast
            }

            $user->save();
            $user->syncRoles([$data['role']]);

            if ($roleLama !== $data['role']) {
                activity('users')
                    ->performedOn($user)
                    ->causedBy(auth()->user())
                    ->withProperties(['role_lama' => $roleLama, 'role_baru' => $data['role']])
                    ->log('role diubah');
            }

            if ($data['password'] !== '' && $user->wasRecentlyCreated === false) {
                activity('users')
                    ->performedOn($user)
                    ->causedBy(auth()->user())
                    ->log('password direset');
            }
        });

        if ($galat) {
            $this->addError(...$galat);
            return;
        }

        session()->flash('sukses', $this->editId ? 'User berhasil diperbarui.' : 'User berhasil ditambahkan.');
        $this->tutupForm();
    }

    public function toggleAktif(int $id): void
    {
        abort_unless(auth()->user()->can('user.kelola'), 403);

        $pesan = null;

        DB::transaction(function () use ($id, &$pesan) {
            $user = $this->queryStaf()->lockForUpdate()->findOrFail($id);

            if ($user->id === auth()->id()) {
                $pesan = ['gagal', 'Anda tidak bisa menonaktifkan akun sendiri.'];
                return;
            }

            if ($user->is_active && $user->hasRole('SuperAdmin') && ! $this->adaSuperAdminLain($user->id)) {
                $pesan = ['gagal', 'Tidak bisa menonaktifkan SuperAdmin aktif yang terakhir.'];
                return;
            }

            $user->update(['is_active' => ! $user->is_active]);
            $pesan = ['sukses', $user->is_active ? "User {$user->name} diaktifkan." : "User {$user->name} dinonaktifkan."];
        });

        session()->flash(...$pesan);
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'name', 'email', 'password', 'role', 'batas_persetujuan']);
        $this->is_active = true;
        $this->resetValidation();
    }

    protected function queryStaf()
    {
        return User::query()->whereDoesntHave('roles', fn ($q) => $q->where('name', 'Mahasiswa'));
    }

    protected function adaSuperAdminLain(int $kecualiId): bool
    {
        return User::role('SuperAdmin')
            ->where('is_active', true)
            ->where('id', '!=', $kecualiId)
            ->exists();
    }

    public function render()
    {
        $data = $this->queryStaf()
            ->with('roles')
            ->when($this->cari !== '', fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', "%{$this->cari}%")
                  ->orWhere('email', 'like', "%{$this->cari}%");
            }))
            ->when($this->filterRole !== '', fn ($q) => $q->role($this->filterRole))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.user.index', [
            'data' => $data,
            'daftarRole' => $this->daftarRole(),
        ]);
    }
}
