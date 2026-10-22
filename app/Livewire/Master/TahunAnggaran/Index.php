<?php

namespace App\Livewire\Master\TahunAnggaran;

use App\Models\TahunAnggaran;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    public bool $tampilForm = false;
    public ?int $editId = null;

    public string $nama = '';
    public string $tanggal_mulai = '';
    public string $tanggal_selesai = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('master.lihat'), 403);
    }

    protected function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:20', Rule::unique('tahun_anggaran', 'nama')->ignore($this->editId)],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after:tanggal_mulai'],
        ];
    }

    protected function messages(): array
    {
        return [
            'nama.required' => 'Nama tahun anggaran wajib diisi.',
            'nama.unique' => 'Tahun anggaran dengan nama ini sudah ada.',
            'tanggal_mulai.required' => 'Tanggal mulai wajib diisi.',
            'tanggal_selesai.required' => 'Tanggal selesai wajib diisi.',
            'tanggal_selesai.after' => 'Tanggal selesai harus setelah tanggal mulai.',
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

        $tahun = TahunAnggaran::findOrFail($id);

        if ($tahun->isDitutup()) {
            session()->flash('gagal', 'Tahun anggaran yang sudah ditutup tidak dapat diubah.');
            return;
        }

        $this->editId = $tahun->id;
        $this->nama = $tahun->nama;
        $this->tanggal_mulai = $tahun->tanggal_mulai->format('Y-m-d');
        $this->tanggal_selesai = $tahun->tanggal_selesai->format('Y-m-d');
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function simpan(): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $data = $this->validate();

        // Periode tidak boleh tumpang tindih dengan tahun lain
        $bentrok = TahunAnggaran::query()
            ->when($this->editId, fn ($q) => $q->where('id', '!=', $this->editId))
            ->where('tanggal_mulai', '<=', $data['tanggal_selesai'])
            ->where('tanggal_selesai', '>=', $data['tanggal_mulai'])
            ->first();

        if ($bentrok) {
            $this->addError('tanggal_mulai', "Periode bentrok dengan tahun anggaran {$bentrok->nama}.");
            return;
        }

        DB::transaction(function () use ($data) {
            if ($this->editId) {
                $tahun = TahunAnggaran::lockForUpdate()->findOrFail($this->editId);

                if ($tahun->isDitutup()) {
                    abort(403, 'Tahun anggaran sudah ditutup.');
                }

                $tahun->update($data);
            } else {
                TahunAnggaran::create($data + ['status' => TahunAnggaran::DRAFT]);
            }
        });

        session()->flash('sukses', $this->editId ? 'Tahun anggaran berhasil diperbarui.' : 'Tahun anggaran berhasil ditambahkan (status Draft).');
        $this->tutupForm();
    }

    public function aktifkan(int $id): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $pesan = null;

        // Satu transaksi + lock: mencegah dua tahun aktif bersamaan
        DB::transaction(function () use ($id, &$pesan) {
            $tahun = TahunAnggaran::lockForUpdate()->findOrFail($id);

            if ($tahun->isDitutup()) {
                $pesan = ['gagal', 'Tahun anggaran yang sudah ditutup tidak dapat diaktifkan kembali.'];
                return;
            }

            TahunAnggaran::aktif()
                ->where('id', '!=', $tahun->id)
                ->lockForUpdate()
                ->get()
                ->each(fn ($lama) => $lama->update(['status' => TahunAnggaran::DRAFT]));

            $tahun->update(['status' => TahunAnggaran::AKTIF]);
            $pesan = ['sukses', "Tahun anggaran {$tahun->nama} sekarang aktif."];
        });

        session()->flash(...$pesan);
    }

    public function hapus(int $id): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $tahun = TahunAnggaran::findOrFail($id);

        if ($tahun->status !== TahunAnggaran::DRAFT) {
            session()->flash('gagal', 'Hanya tahun anggaran berstatus Draft yang dapat dihapus.');
            return;
        }

        // Fase 2: tambahkan pengecekan "sudah punya anggaran/transaksi" di sini.
        $tahun->delete();
        session()->flash('sukses', 'Tahun anggaran berhasil dihapus.');
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'nama', 'tanggal_mulai', 'tanggal_selesai']);
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.master.tahun-anggaran.index', [
            'data' => TahunAnggaran::orderByDesc('tanggal_mulai')->get(),
        ]);
    }
}
