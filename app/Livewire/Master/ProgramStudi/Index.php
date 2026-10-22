<?php

namespace App\Livewire\Master\ProgramStudi;

use App\Models\ProgramStudi;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url(except: '')]
    public string $cari = '';

    public bool $tampilForm = false;
    public ?int $editId = null;

    public string $kode = '';
    public string $nama = '';
    public string $jenjang = '';
    public bool $is_active = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('master.lihat'), 403);
    }

    public function updatingCari(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        return [
            'kode' => ['required', 'string', 'max:20', Rule::unique('program_studi', 'kode')->ignore($this->editId)],
            'nama' => ['required', 'string', 'max:255'],
            'jenjang' => ['nullable', 'string', 'max:10'],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'kode.required' => 'Kode wajib diisi.',
            'kode.unique' => 'Kode sudah dipakai (termasuk data yang sudah dihapus).',
            'kode.max' => 'Kode maksimal 20 karakter.',
            'nama.required' => 'Nama program studi wajib diisi.',
        ];
    }

    public function tambah(): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $this->resetForm();
        $this->tampilForm = true;
    }

    public function ubah(int $id): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $prodi = ProgramStudi::findOrFail($id);
        $this->editId = $prodi->id;
        $this->kode = $prodi->kode;
        $this->nama = $prodi->nama;
        $this->jenjang = (string) $prodi->jenjang;
        $this->is_active = $prodi->is_active;
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function simpan(): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $data = $this->validate();
        $data['kode'] = strtoupper(trim($data['kode']));
        $data['jenjang'] = $data['jenjang'] !== '' ? strtoupper($data['jenjang']) : null;

        ProgramStudi::updateOrCreate(['id' => $this->editId], $data);

        session()->flash('sukses', $this->editId ? 'Program studi berhasil diperbarui.' : 'Program studi berhasil ditambahkan.');
        $this->tutupForm();
    }

    public function hapus(int $id): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $prodi = ProgramStudi::findOrFail($id);

        if ($prodi->mahasiswa()->exists()) {
            session()->flash('gagal', 'Tidak bisa dihapus: masih ada mahasiswa di program studi ini. Nonaktifkan saja.');
            return;
        }

        $prodi->delete(); // soft delete, tercatat di audit log
        session()->flash('sukses', 'Program studi berhasil dihapus.');
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'kode', 'nama', 'jenjang']);
        $this->is_active = true;
        $this->resetValidation();
    }

    public function render()
    {
        $data = ProgramStudi::query()
            ->withCount('mahasiswa')
            ->when($this->cari !== '', fn ($q) => $q->where(function ($q) {
                $q->where('kode', 'like', "%{$this->cari}%")
                  ->orWhere('nama', 'like', "%{$this->cari}%");
            }))
            ->orderBy('kode')
            ->paginate(10);

        return view('livewire.master.program-studi.index', ['data' => $data]);
    }
}
