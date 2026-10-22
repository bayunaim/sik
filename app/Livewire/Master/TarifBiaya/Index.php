<?php

namespace App\Livewire\Master\TarifBiaya;

use App\Models\KomponenBiaya;
use App\Models\ProgramStudi;
use App\Models\TarifBiaya;
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
    public string $filterKomponen = '';

    #[Url(except: '')]
    public string $filterProdi = '';

    public bool $tampilForm = false;
    public ?int $editId = null;

    public string $komponen_biaya_id = '';
    public string $program_studi_id = '';   // '' = semua prodi
    public string $angkatan = '';           // '' = semua angkatan
    public string $nominal = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('master.lihat'), 403);
    }

    public function updatingFilterKomponen(): void { $this->resetPage(); }
    public function updatingFilterProdi(): void { $this->resetPage(); }

    protected function rules(): array
    {
        return [
            'komponen_biaya_id' => ['required', Rule::exists('komponen_biaya', 'id')->whereNull('deleted_at')],
            'program_studi_id' => ['nullable', Rule::exists('program_studi', 'id')->whereNull('deleted_at')],
            'angkatan' => ['nullable', 'integer', 'between:1990,2100'],
            'nominal' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
        ];
    }

    protected function messages(): array
    {
        return [
            'komponen_biaya_id.required' => 'Komponen biaya wajib dipilih.',
            'komponen_biaya_id.exists' => 'Komponen biaya tidak ditemukan.',
            'program_studi_id.exists' => 'Program studi tidak ditemukan.',
            'angkatan.integer' => 'Angkatan harus berupa angka tahun.',
            'angkatan.between' => 'Angkatan harus antara 1990 dan 2100.',
            'nominal.required' => 'Nominal wajib diisi.',
            'nominal.numeric' => 'Nominal harus berupa angka.',
            'nominal.min' => 'Nominal tidak boleh negatif.',
        ];
    }

    public function tambah(): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $this->resetForm();
        $this->komponen_biaya_id = $this->filterKomponen;
        $this->tampilForm = true;
    }

    public function ubah(int $id): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $t = TarifBiaya::findOrFail($id);
        $this->editId = $t->id;
        $this->komponen_biaya_id = (string) $t->komponen_biaya_id;
        $this->program_studi_id = (string) $t->program_studi_id;
        $this->angkatan = (string) $t->angkatan;
        $this->nominal = (string) $t->nominal;
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function simpan(): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        $data = $this->validate();

        $payload = [
            'komponen_biaya_id' => (int) $data['komponen_biaya_id'],
            'program_studi_id' => $data['program_studi_id'] !== '' ? (int) $data['program_studi_id'] : null,
            'angkatan' => $data['angkatan'] !== '' ? (int) $data['angkatan'] : null,
            'nominal' => $data['nominal'],
        ];

        $ganda = false;

        // Transaksi + lock: MySQL tidak bisa unique index dengan NULL, jadi dijaga di sini
        DB::transaction(function () use ($payload, &$ganda) {
            $ganda = TarifBiaya::query()
                ->where('komponen_biaya_id', $payload['komponen_biaya_id'])
                ->when($payload['program_studi_id'],
                    fn ($q, $v) => $q->where('program_studi_id', $v),
                    fn ($q) => $q->whereNull('program_studi_id'))
                ->when($payload['angkatan'],
                    fn ($q, $v) => $q->where('angkatan', $v),
                    fn ($q) => $q->whereNull('angkatan'))
                ->when($this->editId, fn ($q) => $q->where('id', '!=', $this->editId))
                ->lockForUpdate()
                ->exists();

            if (! $ganda) {
                TarifBiaya::updateOrCreate(['id' => $this->editId], $payload);
            }
        });

        if ($ganda) {
            $this->addError('komponen_biaya_id', 'Tarif untuk kombinasi komponen, prodi, dan angkatan ini sudah ada. Ubah tarif yang sudah ada.');
            return;
        }

        session()->flash('sukses', $this->editId ? 'Tarif berhasil diperbarui.' : 'Tarif berhasil ditambahkan.');
        $this->tutupForm();
    }

    public function hapus(int $id): void
    {
        abort_unless(auth()->user()->can('master.kelola'), 403);

        // Tagihan yang sudah terbit menyimpan nominalnya sendiri, jadi aman dihapus.
        TarifBiaya::findOrFail($id)->delete();
        session()->flash('sukses', 'Tarif berhasil dihapus.');
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editId', 'komponen_biaya_id', 'program_studi_id', 'angkatan', 'nominal']);
        $this->resetValidation();
    }

    public function render()
    {
        $data = TarifBiaya::query()
            ->with(['komponen', 'programStudi'])
            ->when($this->filterKomponen !== '', fn ($q) => $q->where('komponen_biaya_id', $this->filterKomponen))
            ->when($this->filterProdi !== '', fn ($q) => $q->where('program_studi_id', $this->filterProdi))
            ->orderBy('komponen_biaya_id')
            ->orderBy('program_studi_id')
            ->orderBy('angkatan')
            ->paginate(15);

        return view('livewire.master.tarif-biaya.index', [
            'data' => $data,
            'daftarKomponen' => KomponenBiaya::where('is_active', true)->orderBy('kode')->get(),
            'daftarProdi' => ProgramStudi::where('is_active', true)->orderBy('kode')->get(),
        ]);
    }
}
