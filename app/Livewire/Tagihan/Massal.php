<?php

namespace App\Livewire\Tagihan;

use App\Models\Anggaran;
use App\Models\KomponenBiaya;
use App\Models\Mahasiswa;
use App\Models\ProgramStudi;
use App\Models\Tagihan;
use App\Services\TagihanService;
use DomainException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Massal extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    // pengaturan tagihan
    public string $anggaran_id = '';
    public string $komponen_biaya_id = '';
    public string $semester = 'Ganjil';
    public string $tahun_ajaran = '';
    public string $nominal = '';
    public string $tanggal_tagihan = '';
    public string $jatuh_tempo = '';
    public string $metode_bayar = '';
    public string $keterangan = '';

    // filter mahasiswa
    public string $filterProdi = '';
    public string $filterAngkatan = '';
    public string $filterStatus = 'Aktif';
    public string $cari = '';

    public array $terpilih = [];
    public ?array $pratinjau = null;
    public ?array $hasil = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('tagihan.buat'), 403);

        $tahun = (int) now()->format('Y');
        $awal = now()->month >= 7 ? $tahun : $tahun - 1;
        $this->tahun_ajaran = $awal.'/'.($awal + 1);
        $this->tanggal_tagihan = now()->format('Y-m-d');
        $this->jatuh_tempo = now()->addDays(30)->format('Y-m-d');
    }

    // Perubahan apa pun membuat pratinjau lama tidak berlaku lagi
    public function updated(): void
    {
        $this->pratinjau = null;
    }

    public function updatingCari(): void { $this->resetPage(); }
    public function updatingFilterProdi(): void { $this->resetPage(); }
    public function updatingFilterAngkatan(): void { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }

    protected function rules(): array
    {
        return [
            'komponen_biaya_id' => ['required', Rule::exists('komponen_biaya', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'anggaran_id' => ['required', Rule::exists('anggaran', 'id')->where('tipe', 'D')->where('status', 'approved')->whereNull('deleted_at')],
            'semester' => ['required', Rule::in(Tagihan::SEMESTER)],
            'tahun_ajaran' => ['required', 'regex:/^\d{4}\/\d{4}$/', function ($attr, $value, $fail) {
                [$a, $b] = array_map('intval', explode('/', $value));
                if ($b !== $a + 1) {
                    $fail('Tahun ajaran harus berurutan, contoh 2025/2026.');
                }
            }],
            'nominal' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'tanggal_tagihan' => ['required', 'date'],
            'jatuh_tempo' => ['required', 'date', 'after_or_equal:tanggal_tagihan'],
            'metode_bayar' => ['nullable', Rule::in(['Transfer Bank', 'Tunai'])],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function messages(): array
    {
        return [
            'komponen_biaya_id.required' => 'Komponen biaya wajib dipilih.',
            'anggaran_id.required' => 'Kode anggaran penerimaan wajib dipilih.',
            'anggaran_id.exists' => 'Kode anggaran harus bertipe D dan sudah Approved.',
            'tahun_ajaran.required' => 'Tahun ajaran wajib diisi.',
            'tahun_ajaran.regex' => 'Format tahun ajaran: 2025/2026.',
            'nominal.gt' => 'Nominal harus lebih dari nol.',
            'nominal.numeric' => 'Nominal harus berupa angka.',
            'tanggal_tagihan.required' => 'Tanggal tagihan wajib diisi.',
            'jatuh_tempo.required' => 'Tanggal jatuh tempo wajib diisi.',
            'jatuh_tempo.after_or_equal' => 'Jatuh tempo tidak boleh sebelum tanggal tagihan.',
        ];
    }

    protected function queryMahasiswa()
    {
        return Mahasiswa::query()
            ->when($this->filterProdi !== '', fn ($q) => $q->where('program_studi_id', $this->filterProdi))
            ->when($this->filterAngkatan !== '', fn ($q) => $q->where('angkatan', $this->filterAngkatan))
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status_akademik', $this->filterStatus))
            ->when($this->cari !== '', fn ($q) => $q->where(function ($q) {
                $q->where('nim', 'like', "%{$this->cari}%")
                  ->orWhere('nama', 'like', "%{$this->cari}%");
            }));
    }

    public function pilihSemua(): void
    {
        $maks = TagihanService::MAKS_MASSAL;
        $ids = $this->queryMahasiswa()->orderBy('nim')->limit($maks + 1)->pluck('id');

        $this->pratinjau = null;
        $this->resetErrorBag('terpilih');

        if ($ids->count() > $maks) {
            $this->addError('terpilih', 'Hasil filter lebih dari '.number_format($maks, 0, ',', '.').' mahasiswa. Persempit filter (misalnya per prodi atau angkatan).');
            return;
        }

        $this->terpilih = $ids->map(fn ($i) => (string) $i)->all();
    }

    public function batalPilih(): void
    {
        $this->terpilih = [];
        $this->pratinjau = null;
        $this->resetErrorBag('terpilih');
    }

    public function buatPratinjau(TagihanService $service): void
    {
        abort_unless(auth()->user()->can('tagihan.buat'), 403);

        $data = $this->validate();
        $this->hasil = null;

        if (empty($this->terpilih)) {
            $this->addError('terpilih', 'Pilih minimal satu mahasiswa.');
            return;
        }

        try {
            $r = $service->pratinjauMassal($data, $this->terpilih, auth()->user());
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());
            return;
        }

        $r['lewati_tampil'] = array_slice($r['lewati'], 0, 200);
        unset($r['lewati']);
        $this->pratinjau = $r;
    }

    public function terbitkan(TagihanService $service): void
    {
        abort_unless(auth()->user()->can('tagihan.buat'), 403);

        if ($this->pratinjau === null) {
            $this->addError('form', 'Buat pratinjau terlebih dahulu sebelum menerbitkan.');
            return;
        }

        $data = $this->validate();
        $mulai = microtime(true);

        try {
            $r = $service->buatMassal($data, $this->terpilih, auth()->user());
        } catch (DomainException $e) {
            $this->pratinjau = null;
            $this->addError('form', $e->getMessage());
            return;
        }

        $r['durasi'] = round(microtime(true) - $mulai, 2);
        $r['lewati_tampil'] = array_slice($r['lewati'], 0, 200);
        unset($r['lewati']);

        $this->hasil = $r;
        $this->pratinjau = null;
        $this->terpilih = [];
    }

    public function render()
    {
        $query = $this->queryMahasiswa();

        return view('livewire.tagihan.massal', [
            'data' => (clone $query)->with('programStudi')->orderBy('nim')->paginate(25),
            'totalFilter' => (clone $query)->count(),
            'daftarKomponen' => KomponenBiaya::where('is_active', true)->orderBy('kode')->get(),
            'daftarAnggaran' => Anggaran::with('tahun')
                ->where('tipe', 'D')->where('status', 'approved')
                ->whereHas('tahun', fn ($q) => $q->where('status', '!=', 'ditutup'))
                ->orderBy('kode')->get(),
            'daftarProdi' => ProgramStudi::where('is_active', true)->orderBy('kode')->get(),
            'daftarAngkatan' => Mahasiswa::query()->distinct()->orderByDesc('angkatan')->pluck('angkatan'),
            'daftarStatus' => array_merge(Mahasiswa::STATUS, [Mahasiswa::STATUS_TIDAK_ADA_DI_IMPORT]),
        ]);
    }
}
