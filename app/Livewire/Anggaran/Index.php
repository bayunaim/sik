<?php

namespace App\Livewire\Anggaran;

use App\Models\Anggaran;
use App\Models\TahunAnggaran;
use App\Services\AnggaranService;
use Illuminate\Support\Facades\DB;
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
    public string $filterTahun = '';
    #[Url(except: '')]
    public string $filterTipe = '';
    #[Url(except: '')]
    public string $filterStatus = '';
    #[Url(except: '')]
    public string $cari = '';

    public bool $tampilForm = false;
    public ?int $editId = null;

    public string $tahun_anggaran_id = '';
    public string $kode = '';
    public string $nama = '';
    public string $sub_anggaran = '';
    public string $jumlah = '';
    public string $status = 'draft';
    public string $catatan = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('anggaran.lihat'), 403);

        if ($this->filterTahun === '') {
            $tahun = TahunAnggaran::aktifSekarang() ?? TahunAnggaran::orderByDesc('tanggal_mulai')->first();
            $this->filterTahun = $tahun ? (string) $tahun->id : '';
        }
    }

    public function updatingFilterTahun(): void { $this->resetPage(); }
    public function updatingFilterTipe(): void { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }
    public function updatingCari(): void { $this->resetPage(); }

    protected function rules(): array
    {
        return [
            'tahun_anggaran_id' => [
                'required',
                Rule::exists('tahun_anggaran', 'id')->where('status', '!=', TahunAnggaran::DITUTUP),
            ],
            'kode' => [
                'required', 'max:20', 'regex:/^[DK]\d+(\.\d+)*$/',
                Rule::unique('anggaran', 'kode')
                    ->where('tahun_anggaran_id', $this->tahun_anggaran_id)
                    ->ignore($this->editId),
            ],
            'nama' => ['required', 'string', 'max:255'],
            'sub_anggaran' => ['nullable', 'string', 'max:255'],
            'jumlah' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'status' => ['required', Rule::in([Anggaran::DRAFT, Anggaran::APPROVED])],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'tahun_anggaran_id.required' => 'Tahun anggaran wajib dipilih.',
            'tahun_anggaran_id.exists' => 'Tahun anggaran tidak ditemukan atau sudah ditutup.',
            'kode.required' => 'Kode anggaran wajib diisi.',
            'kode.regex' => 'Format kode: huruf D (penerimaan) atau K (pengeluaran) diikuti angka, contoh D1.1 atau K2.3.',
            'kode.unique' => 'Kode sudah dipakai di tahun anggaran ini (termasuk yang sudah dihapus).',
            'nama.required' => 'Nama anggaran wajib diisi.',
            'jumlah.required' => 'Jumlah wajib diisi.',
            'jumlah.numeric' => 'Jumlah harus berupa angka.',
            'jumlah.min' => 'Jumlah tidak boleh negatif.',
        ];
    }

    public function tambah(): void
    {
        abort_unless(auth()->user()->can('anggaran.buat'), 403);

        $this->resetForm();
        $this->tahun_anggaran_id = $this->filterTahun;
        $this->tampilForm = true;
    }

    public function ubah(int $id): void
    {
        $a = Anggaran::with('tahun')->findOrFail($id);

        if (! $this->bolehUbah($a)) {
            session()->flash('gagal', 'Anda tidak boleh mengubah anggaran ini.');
            return;
        }

        $this->editId = $a->id;
        $this->tahun_anggaran_id = (string) $a->tahun_anggaran_id;
        $this->kode = $a->kode;
        $this->nama = $a->nama;
        $this->sub_anggaran = (string) $a->sub_anggaran;
        $this->jumlah = (string) $a->jumlah;
        $this->status = $a->status;
        $this->catatan = (string) $a->catatan;
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function simpan(): void
    {
        $user = auth()->user();
        abort_unless($user->can('anggaran.buat'), 403);

        $bisaApprove = $user->can('anggaran.approve');
        $this->kode = strtoupper(trim($this->kode));

        // Saat edit, tahun tidak boleh diganti (cegah manipulasi dari browser)
        if ($this->editId) {
            $this->tahun_anggaran_id = (string) Anggaran::findOrFail($this->editId)->tahun_anggaran_id;
        }

        $data = $this->validate();
        $galat = null;

        DB::transaction(function () use ($data, $user, $bisaApprove, &$galat) {
            $a = $this->editId
                ? Anggaran::with('tahun')->lockForUpdate()->findOrFail($this->editId)
                : new Anggaran();

            if ($a->exists && ! $this->bolehUbah($a)) {
                $galat = 'Anda tidak boleh mengubah anggaran ini.';
                return;
            }

            $statusBaru = $bisaApprove ? $data['status'] : Anggaran::DRAFT;

            if ($a->exists && $a->isApproved() && $statusBaru === Anggaran::DRAFT) {
                $galat = 'Anggaran yang sudah Approved tidak bisa dikembalikan ke Draft.';
                return;
            }

            $a->fill([
                'tahun_anggaran_id' => (int) $data['tahun_anggaran_id'],
                'kode' => $data['kode'],
                'tipe' => substr($data['kode'], 0, 1),
                'nama' => trim($data['nama']),
                'sub_anggaran' => $data['sub_anggaran'] !== '' ? trim($data['sub_anggaran']) : null,
                'jumlah' => $data['jumlah'],
                'status' => $statusBaru,
                'catatan' => $data['catatan'] !== '' ? $data['catatan'] : null,
            ]);

            if (! $a->exists) {
                $a->dibuat_oleh = $user->id;
            }

            if ($statusBaru === Anggaran::APPROVED && ! $a->getOriginal('disetujui_oleh')) {
                $a->disetujui_oleh = $user->id;
                $a->disetujui_pada = now();
            }

            $a->save();
        });

        if ($galat) {
            session()->flash('gagal', $galat);
            $this->tutupForm();
            return;
        }

        session()->flash('sukses', $this->editId
            ? 'Anggaran berhasil diperbarui.'
            : ($bisaApprove && $data['status'] === Anggaran::APPROVED
                ? 'Anggaran berhasil ditambahkan dan disetujui.'
                : 'Anggaran berhasil ditambahkan (status Draft, menunggu persetujuan Admin Keuangan).'));
        $this->tutupForm();
    }

    public function setujui(int $id): void
    {
        abort_unless(auth()->user()->can('anggaran.approve'), 403);

        $pesan = null;

        DB::transaction(function () use ($id, &$pesan) {
            $a = Anggaran::with('tahun')->lockForUpdate()->findOrFail($id);

            if ($a->tahun->isDitutup()) {
                $pesan = ['gagal', 'Tahun anggaran sudah ditutup.'];
                return;
            }

            if ($a->isApproved()) {
                $pesan = ['gagal', 'Anggaran ini sudah disetujui.'];
                return;
            }

            $a->update([
                'status' => Anggaran::APPROVED,
                'disetujui_oleh' => auth()->id(),
                'disetujui_pada' => now(),
            ]);
            $pesan = ['sukses', "Anggaran {$a->kode} disetujui."];
        });

        session()->flash(...$pesan);
    }

    public function hapus(int $id): void
    {
        $a = Anggaran::with('tahun')->findOrFail($id);

        if ($a->isApproved()) {
            session()->flash('gagal', 'Anggaran yang sudah Approved tidak dapat dihapus.');
            return;
        }

        if (! $this->bolehUbah($a)) {
            session()->flash('gagal', 'Anda tidak boleh menghapus anggaran ini.');
            return;
        }

        $a->delete(); // soft delete, tercatat di audit log
        session()->flash('sukses', 'Anggaran berhasil dihapus.');
    }

    // Aturan siapa boleh mengubah (FR-023 + aturan tahun ditutup)
    protected function bolehUbah(Anggaran $a): bool
    {
        $user = auth()->user();

        if (! $user->can('anggaran.buat') || $a->tahun->isDitutup()) {
            return false;
        }

        if ($user->can('anggaran.approve')) {
            return true;
        }

        // staf biasa: hanya Draft buatannya sendiri
        return ! $a->isApproved() && $a->dibuat_oleh === $user->id;
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'tahun_anggaran_id', 'kode', 'nama', 'sub_anggaran', 'jumlah', 'catatan']);
        $this->status = 'draft';
        $this->resetValidation();
    }

    public function render(AnggaranService $service)
    {
        $dasar = Anggaran::query()->where('tahun_anggaran_id', $this->filterTahun ?: 0);

        // Ringkasan (FR-022): hanya yang Approved
        $total = (clone $dasar)->where('status', Anggaran::APPROVED)
            ->selectRaw('tipe, SUM(jumlah) as total')->groupBy('tipe')->pluck('total', 'tipe');
        $penerimaan = (float) ($total['D'] ?? 0);
        $pengeluaran = (float) ($total['K'] ?? 0);
        $jumlahDraft = (clone $dasar)->where('status', Anggaran::DRAFT)->count();

        $data = (clone $dasar)
            ->with('tahun')
            ->when($this->filterTipe !== '', fn ($q) => $q->where('tipe', $this->filterTipe))
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->cari !== '', fn ($q) => $q->where(function ($q) {
                $q->where('kode', 'like', "%{$this->cari}%")
                  ->orWhere('nama', 'like', "%{$this->cari}%")
                  ->orWhere('sub_anggaran', 'like', "%{$this->cari}%");
            }))
            ->orderBy('tipe')
            ->orderByRaw("CAST(SUBSTRING_INDEX(SUBSTRING(kode, 2), '.', 1) AS UNSIGNED)")
            ->orderBy('kode')
            ->paginate(20);

        return view('livewire.anggaran.index', [
            'data' => $data,
            'realisasi' => $service->realisasi($data->getCollection()),
            'daftarTahun' => TahunAnggaran::orderByDesc('tanggal_mulai')->get(),
            'tahunForm' => TahunAnggaran::where('status', '!=', TahunAnggaran::DITUTUP)->orderByDesc('tanggal_mulai')->get(),
            'penerimaan' => $penerimaan,
            'pengeluaran' => $pengeluaran,
            'saldo' => $penerimaan - $pengeluaran,
            'jumlahDraft' => $jumlahDraft,
        ]);
    }
}
