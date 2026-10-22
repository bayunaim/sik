<?php

namespace App\Livewire\Master\ChartOfAccount;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Index extends Component
{
    #[Url(except: '')]
    public string $cari = '';

    #[Url(except: '')]
    public string $filterTipe = '';

    public bool $tampilForm = false;
    public ?int $editId = null;

    public string $kode = '';
    public string $nama = '';
    public string $tipe = 'aset';
    public ?int $parent_id = null;
    public bool $is_active = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('master.lihat'), 403);
    }

    protected function rules(): array
    {
        return [
            'kode' => ['required', 'string', 'max:20', Rule::unique('chart_of_accounts', 'kode')->ignore($this->editId)],
            'nama' => ['required', 'string', 'max:255'],
            'tipe' => ['required', Rule::in(array_keys(ChartOfAccount::TIPE))],
            'parent_id' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'kode.required' => 'Kode akun wajib diisi.',
            'kode.unique' => 'Kode akun sudah dipakai (termasuk akun yang sudah dihapus).',
            'kode.max' => 'Kode maksimal 20 karakter.',
            'nama.required' => 'Nama akun wajib diisi.',
            'tipe.in' => 'Tipe akun tidak valid.',
            'parent_id.exists' => 'Akun induk tidak ditemukan.',
        ];
    }

    // Ganti tipe -> induk lama tidak relevan lagi
    public function updatedTipe(): void
    {
        $this->parent_id = null;
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

        $akun = ChartOfAccount::findOrFail($id);
        $this->editId = $akun->id;
        $this->kode = $akun->kode;
        $this->nama = $akun->nama;
        $this->tipe = $akun->tipe;
        $this->parent_id = $akun->parent_id;
        $this->is_active = $akun->is_active;
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function simpan(): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $data = $this->validate();
        $data['kode'] = trim($data['kode']);

        if ($data['parent_id']) {
            $induk = ChartOfAccount::findOrFail($data['parent_id']);

            if ($induk->tipe !== $data['tipe']) {
                $this->addError('parent_id', 'Akun induk harus bertipe sama.');
                return;
            }

            if ($this->editId && $this->apakahTurunan($induk->id, $this->editId)) {
                $this->addError('parent_id', 'Akun induk tidak boleh diri sendiri atau turunannya.');
                return;
            }
        }

        if ($this->editId) {
            $akun = ChartOfAccount::findOrFail($this->editId);

            if ($akun->tipe !== $data['tipe'] && $akun->children()->exists()) {
                $this->addError('tipe', 'Tipe tidak bisa diubah karena akun ini punya sub-akun.');
                return;
            }
        }

        ChartOfAccount::updateOrCreate(['id' => $this->editId], $data);

        session()->flash('sukses', $this->editId ? 'Akun berhasil diperbarui.' : 'Akun berhasil ditambahkan.');
        $this->tutupForm();
    }

    public function hapus(int $id): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $akun = ChartOfAccount::findOrFail($id);

        if ($akun->children()->exists()) {
            session()->flash('gagal', 'Tidak bisa dihapus: akun ini punya sub-akun.');
            return;
        }

        $dipakai = DB::table('komponen_biaya')
            ->where('akun_pendapatan_id', $id)
            ->whereNull('deleted_at')
            ->exists();

        if ($dipakai) {
            session()->flash('gagal', 'Tidak bisa dihapus: akun dipakai oleh komponen biaya. Nonaktifkan saja.');
            return;
        }

        // Fase 2: tambahkan pengecekan "sudah dipakai di jurnal_detail" di sini.
        $akun->delete();
        session()->flash('sukses', 'Akun berhasil dihapus.');
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'kode', 'nama', 'parent_id']);
        $this->tipe = 'aset';
        $this->is_active = true;
        $this->resetValidation();
    }

    // Apakah $kandidat adalah $akunId sendiri atau salah satu turunannya?
    protected function apakahTurunan(int $kandidat, int $akunId): bool
    {
        $peta = ChartOfAccount::pluck('parent_id', 'id');
        $saat = $kandidat;
        $batas = 50;

        while ($saat !== null && $batas-- > 0) {
            if ($saat === $akunId) {
                return true;
            }
            $saat = $peta[$saat] ?? null;
        }

        return false;
    }

    public function render()
    {
        $semua = ChartOfAccount::orderBy('kode')->get();
        $peta = $semua->pluck('parent_id', 'id');

        // hitung kedalaman untuk indentasi
        $kedalaman = function (int $id) use ($peta) {
            $d = 0;
            $saat = $peta[$id] ?? null;
            while ($saat !== null && $d < 10) {
                $d++;
                $saat = $peta[$saat] ?? null;
            }
            return $d;
        };

        $adaAnak = $semua->pluck('parent_id')->filter()->flip();

        $data = $semua
            ->when($this->filterTipe !== '', fn ($c) => $c->where('tipe', $this->filterTipe))
            ->when($this->cari !== '', fn ($c) => $c->filter(
                fn ($a) => str_contains(mb_strtolower($a->kode.' '.$a->nama), mb_strtolower($this->cari))
            ))
            ->values()
            ->map(function ($a) use ($kedalaman, $adaAnak) {
                $a->kedalaman = $kedalaman($a->id);
                $a->punya_anak = $adaAnak->has($a->id);
                return $a;
            });

        // pilihan induk: semua akun sebesar tipe yang sedang dipilih, kecuali diri sendiri
        $pilihanInduk = $semua
            ->where('tipe', $this->tipe)
            ->where('id', '!=', $this->editId)
            ->values();

        return view('livewire.master.chart-of-account.index', [
            'data' => $data,
            'pilihanInduk' => $pilihanInduk,
        ]);
    }
}
