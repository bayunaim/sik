<?php

namespace App\Livewire\Master\Mahasiswa;

use App\Models\Mahasiswa;
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
    #[Url(except: '')]
    public string $filterProdi = '';
    #[Url(except: '')]
    public string $filterAngkatan = '';
    #[Url(except: '')]
    public string $filterStatus = '';

    public bool $tampilForm = false;
    public ?int $editId = null;

    public string $nim = '';
    public string $nama = '';
    public string $program_studi_id = '';
    public string $angkatan = '';
    public string $status_akademik = 'Aktif';
    public string $semester_aktif = '';
    public string $sia_id = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('master.lihat'), 403);
    }

    public function updatingCari(): void { $this->resetPage(); }
    public function updatingFilterProdi(): void { $this->resetPage(); }
    public function updatingFilterAngkatan(): void { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }

    protected function rules(): array
    {
        return [
            'nim' => ['required', 'string', 'max:30', Rule::unique('mahasiswa', 'nim')->ignore($this->editId)],
            'nama' => ['required', 'string', 'max:255'],
            'program_studi_id' => [
                'required',
                Rule::exists('program_studi', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'angkatan' => ['required', 'integer', 'between:1990,2100'],
            'status_akademik' => ['required', Rule::in(Mahasiswa::STATUS)],
            'semester_aktif' => ['nullable', 'string', 'max:10'],
            'sia_id' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function messages(): array
    {
        return [
            'nim.required' => 'NIM wajib diisi.',
            'nim.unique' => 'NIM sudah terdaftar (termasuk data yang sudah dihapus).',
            'nama.required' => 'Nama wajib diisi.',
            'program_studi_id.required' => 'Program studi wajib dipilih.',
            'program_studi_id.exists' => 'Program studi harus aktif.',
            'angkatan.required' => 'Angkatan wajib diisi.',
            'angkatan.integer' => 'Angkatan harus berupa angka tahun.',
            'angkatan.between' => 'Angkatan harus antara 1990 dan 2100.',
            'status_akademik.in' => 'Status akademik tidak valid.',
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

        $m = Mahasiswa::findOrFail($id);
        $this->editId = $m->id;
        $this->nim = $m->nim;
        $this->nama = $m->nama;
        $this->program_studi_id = (string) $m->program_studi_id;
        $this->angkatan = (string) $m->angkatan;
        // status "Tidak Ada di Import" tidak ada di pilihan manual -> tampil sebagai Nonaktif
        $this->status_akademik = in_array($m->status_akademik, Mahasiswa::STATUS) ? $m->status_akademik : 'Nonaktif';
        $this->semester_aktif = (string) $m->semester_aktif;
        $this->sia_id = (string) $m->sia_id;
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function simpan(): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $data = $this->validate();

        $payload = [
            'nim' => trim($data['nim']),
            'nama' => trim($data['nama']),
            'program_studi_id' => (int) $data['program_studi_id'],
            'angkatan' => (int) $data['angkatan'],
            'status_akademik' => $data['status_akademik'],
            'semester_aktif' => $data['semester_aktif'] !== '' ? $data['semester_aktif'] : null,
            'sia_id' => $data['sia_id'] !== '' ? $data['sia_id'] : null,
        ];

        Mahasiswa::updateOrCreate(['id' => $this->editId], $payload);

        session()->flash('sukses', $this->editId ? 'Data mahasiswa berhasil diperbarui.' : 'Mahasiswa berhasil ditambahkan.');
        $this->tutupForm();
    }

    public function hapus(int $id): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        if (\App\Models\Tagihan::where('mahasiswa_id', $id)->exists()) {
            session()->flash('gagal', 'Tidak bisa dihapus: mahasiswa ini sudah punya tagihan. Ubah status akademiknya saja.');
            return;
        }

        // Fase 2: tolak jika mahasiswa sudah punya tagihan/pembayaran.
        Mahasiswa::findOrFail($id)->delete();
        session()->flash('sukses', 'Mahasiswa berhasil dihapus.');
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'nim', 'nama', 'program_studi_id', 'angkatan', 'semester_aktif', 'sia_id']);
        $this->status_akademik = 'Aktif';
        $this->resetValidation();
    }

    public function render()
    {
        $data = Mahasiswa::query()
            ->with('programStudi')
            ->when($this->cari !== '', fn ($q) => $q->where(function ($q) {
                $q->where('nim', 'like', "%{$this->cari}%")
                  ->orWhere('nama', 'like', "%{$this->cari}%");
            }))
            ->when($this->filterProdi !== '', fn ($q) => $q->where('program_studi_id', $this->filterProdi))
            ->when($this->filterAngkatan !== '', fn ($q) => $q->where('angkatan', $this->filterAngkatan))
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status_akademik', $this->filterStatus))
            ->orderBy('nim')
            ->paginate(15);

        return view('livewire.master.mahasiswa.index', [
            'data' => $data,
            'daftarProdi' => ProgramStudi::where('is_active', true)->orderBy('kode')->get(),
            'daftarAngkatan' => Mahasiswa::query()->distinct()->orderByDesc('angkatan')->pluck('angkatan'),
            'daftarStatus' => array_merge(Mahasiswa::STATUS, [Mahasiswa::STATUS_TIDAK_ADA_DI_IMPORT]),
        ]);
    }
}
