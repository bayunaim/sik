<?php

namespace App\Livewire\Master\KomponenBiaya;

use App\Models\ChartOfAccount;
use App\Models\KomponenBiaya;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    #[Url(except: '')]
    public string $cari = '';

    public bool $tampilForm = false;
    public ?int $editId = null;

    public string $kode = '';
    public string $nama = '';
    public string $jenis = 'spp_ukt';
    public string $akun_pendapatan_id = '';
    public bool $is_active = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('master.lihat'), 403);
    }

    protected function rules(): array
    {
        return [
            'kode' => ['required', 'string', 'max:20', Rule::unique('komponen_biaya', 'kode')->ignore($this->editId)],
            'nama' => ['required', 'string', 'max:255'],
            'jenis' => ['required', Rule::in(array_keys(KomponenBiaya::JENIS))],
            'akun_pendapatan_id' => [
                'required',
                Rule::exists('chart_of_accounts', 'id')
                    ->where('tipe', 'pendapatan')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'kode.required' => 'Kode wajib diisi.',
            'kode.unique' => 'Kode sudah dipakai (termasuk data yang sudah dihapus).',
            'nama.required' => 'Nama komponen wajib diisi.',
            'jenis.in' => 'Jenis tidak valid.',
            'akun_pendapatan_id.required' => 'Akun pendapatan wajib dipilih.',
            'akun_pendapatan_id.exists' => 'Akun harus bertipe Pendapatan dan aktif.',
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

        $k = KomponenBiaya::findOrFail($id);
        $this->editId = $k->id;
        $this->kode = $k->kode;
        $this->nama = $k->nama;
        $this->jenis = $k->jenis;
        $this->akun_pendapatan_id = (string) $k->akun_pendapatan_id;
        $this->is_active = $k->is_active;
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function simpan(): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $data = $this->validate();
        $data['kode'] = strtoupper(trim($data['kode']));
        $data['akun_pendapatan_id'] = (int) $data['akun_pendapatan_id'];

        KomponenBiaya::updateOrCreate(['id' => $this->editId], $data);

        session()->flash('sukses', $this->editId ? 'Komponen biaya berhasil diperbarui.' : 'Komponen biaya berhasil ditambahkan.');
        $this->tutupForm();
    }

    public function hapus(int $id): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $k = KomponenBiaya::findOrFail($id);

        if ($k->tarif()->exists()) {
            session()->flash('gagal', 'Tidak bisa dihapus: komponen ini sudah punya tarif. Nonaktifkan saja.');
            return;
        }

        // Fase 2: tambahkan pengecekan "sudah dipakai di tagihan" di sini.
        $k->delete();
        session()->flash('sukses', 'Komponen biaya berhasil dihapus.');
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'kode', 'nama', 'akun_pendapatan_id']);
        $this->jenis = 'spp_ukt';
        $this->is_active = true;
        $this->resetValidation();
    }

    public function render()
    {
        $data = KomponenBiaya::query()
            ->with('akunPendapatan')
            ->withCount('tarif')
            ->when($this->cari !== '', fn ($q) => $q->where(function ($q) {
                $q->where('kode', 'like', "%{$this->cari}%")
                  ->orWhere('nama', 'like', "%{$this->cari}%");
            }))
            ->orderBy('kode')
            ->get();

        $akunPendapatan = ChartOfAccount::where('tipe', 'pendapatan')
            ->where('is_active', true)
            ->orderBy('kode')
            ->get();

        return view('livewire.master.komponen-biaya.index', [
            'data' => $data,
            'akunPendapatan' => $akunPendapatan,
        ]);
    }
}
