<?php

namespace App\Livewire\Rekonsiliasi;

use App\Models\RekeningBank;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Rekening extends Component
{
    public bool $tampilForm = false;
    public ?int $editId = null;
    public string $nama_bank = '';
    public string $nomor = '';
    public string $atas_nama = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);
    }

    protected function rules(): array
    {
        return [
            'nama_bank' => ['required', 'string', 'max:50'],
            'nomor' => [
                'required', 'string', 'max:40', 'regex:/^[0-9][0-9 \-]*$/',
                Rule::unique('rekening_bank', 'nomor')->where('nama_bank', $this->nama_bank)->ignore($this->editId),
            ],
            'atas_nama' => ['required', 'string', 'max:100'],
        ];
    }

    protected function messages(): array
    {
        return [
            'nama_bank.required' => 'Nama bank wajib diisi.',
            'nomor.required' => 'Nomor rekening wajib diisi.',
            'nomor.regex' => 'Nomor rekening hanya boleh berisi angka, spasi, dan tanda hubung.',
            'nomor.unique' => 'Rekening ini sudah terdaftar.',
            'atas_nama.required' => 'Nama pemilik rekening wajib diisi.',
        ];
    }

    public function tambah(): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        $this->reset(['editId', 'nama_bank', 'nomor', 'atas_nama']);
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function ubah(int $id): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        $r = RekeningBank::findOrFail($id);
        $this->editId = $r->id;
        $this->nama_bank = $r->nama_bank;
        $this->nomor = $r->nomor;
        $this->atas_nama = $r->atas_nama;
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function simpan(): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        $data = $this->validate();
        $data = array_map('trim', $data);

        if ($this->editId) {
            RekeningBank::findOrFail($this->editId)->update($data);
            session()->flash('sukses', 'Rekening diperbarui.');
        } else {
            RekeningBank::create($data + ['is_active' => true]);
            session()->flash('sukses', 'Rekening ditambahkan.');
        }

        $this->tutupForm();
    }

    public function toggleAktif(int $id): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        $r = RekeningBank::findOrFail($id);
        $r->update(['is_active' => ! $r->is_active]);

        session()->flash('sukses', $r->is_active ? 'Rekening diaktifkan.' : 'Rekening dinonaktifkan. Mutasi lama tetap tersimpan.');
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->reset(['editId', 'nama_bank', 'nomor', 'atas_nama']);
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.rekonsiliasi.rekening', [
            'data' => RekeningBank::withCount('mutasi')->orderByDesc('is_active')->orderBy('nama_bank')->get(),
        ]);
    }
}
