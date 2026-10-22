<?php

namespace App\Livewire\Tagihan;

use App\Models\Anggaran;
use App\Models\KomponenBiaya;
use App\Models\Mahasiswa;
use App\Models\Tagihan;
use App\Services\TagihanService;
use DomainException;
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
    public string $filterStatus = '';
    #[Url(except: '')]
    public string $filterKomponen = '';
    #[Url(except: '')]
    public string $filterTahunAjaran = '';

    // form tagihan
    public bool $tampilForm = false;
    public string $cariMhs = '';
    public string $mahasiswa_id = '';
    public string $labelMahasiswa = '';
    public string $komponen_biaya_id = '';
    public string $anggaran_id = '';
    public string $semester = 'Ganjil';
    public string $tahun_ajaran = '';
    public string $nominal = '';
    public string $infoTarif = '';
    public string $tanggal_tagihan = '';
    public string $jatuh_tempo = '';
    public string $metode_bayar = '';
    public string $keterangan = '';

    // pembatalan
    public ?int $batalId = null;
    public string $alasanBatal = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('tagihan.lihat'), 403);
    }

    public function updatingCari(): void { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }
    public function updatingFilterKomponen(): void { $this->resetPage(); }
    public function updatingFilterTahunAjaran(): void { $this->resetPage(); }

    protected function rules(): array
    {
        return [
            'mahasiswa_id' => ['required', Rule::exists('mahasiswa', 'id')->whereNull('deleted_at')],
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
            'mahasiswa_id.required' => 'Pilih mahasiswa terlebih dahulu.',
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

    public function tambah(): void
    {
        abort_unless(auth()->user()->can('tagihan.buat'), 403);

        $this->resetForm();
        $this->tampilForm = true;
    }

    public function pilihMahasiswa(int $id): void
    {
        $m = Mahasiswa::findOrFail($id);
        $this->mahasiswa_id = (string) $m->id;
        $this->labelMahasiswa = "{$m->nim} - {$m->nama}";
        $this->cariMhs = '';
        $this->isiNominal();
    }

    public function gantiMahasiswa(): void
    {
        $this->reset(['mahasiswa_id', 'labelMahasiswa', 'nominal', 'infoTarif']);
    }

    public function updatedKomponenBiayaId(): void
    {
        $this->isiNominal();
    }

    protected function isiNominal(): void
    {
        $this->nominal = '';
        $this->infoTarif = '';

        if ($this->mahasiswa_id === '' || $this->komponen_biaya_id === '') {
            return;
        }

        $tarif = app(TagihanService::class)->tarifUntuk((int) $this->mahasiswa_id, (int) $this->komponen_biaya_id);

        if ($tarif !== null) {
            $this->nominal = $tarif;
            $this->infoTarif = 'Nominal terisi otomatis dari tarif.';
        } else {
            $this->infoTarif = 'Tarif belum diatur untuk mahasiswa dan komponen ini.';
        }
    }

    public function simpan(TagihanService $service): void
    {
        $user = auth()->user();
        abort_unless($user->can('tagihan.buat'), 403);

        $data = $this->validate();

        try {
            $tagihan = $service->buat($data, $user);
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());
            return;
        }

        session()->flash('sukses', "Tagihan {$tagihan->nomor} berhasil dibuat.");
        $this->tutupForm();
    }

    public function konfirmasiBatal(int $id): void
    {
        abort_unless(auth()->user()->can('tagihan.batalkan'), 403);

        $this->batalId = $id;
        $this->alasanBatal = '';
        $this->resetValidation();
    }

    public function simpanBatal(TagihanService $service): void
    {
        abort_unless(auth()->user()->can('tagihan.batalkan'), 403);

        $this->validate(
            ['alasanBatal' => ['required', 'string', 'min:5', 'max:500']],
            ['alasanBatal.required' => 'Alasan pembatalan wajib diisi.', 'alasanBatal.min' => 'Alasan minimal 5 karakter.']
        );

        try {
            $service->batalkan(Tagihan::findOrFail($this->batalId), trim($this->alasanBatal), auth()->user());
        } catch (DomainException $e) {
            $this->addError('alasanBatal', $e->getMessage());
            return;
        }

        session()->flash('sukses', 'Tagihan berhasil dibatalkan.');
        $this->tutupBatal();
    }

    public function tutupBatal(): void
    {
        $this->reset(['batalId', 'alasanBatal']);
        $this->resetValidation();
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset([
            'cariMhs', 'mahasiswa_id', 'labelMahasiswa', 'komponen_biaya_id', 'anggaran_id',
            'nominal', 'infoTarif', 'metode_bayar', 'keterangan',
        ]);
        $this->semester = 'Ganjil';

        $tahun = (int) now()->format('Y');
        $awal = now()->month >= 7 ? $tahun : $tahun - 1;
        $this->tahun_ajaran = $awal.'/'.($awal + 1);

        $this->tanggal_tagihan = now()->format('Y-m-d');
        $this->jatuh_tempo = now()->addDays(30)->format('Y-m-d');
        $this->resetValidation();
    }

    public function render()
    {
        $query = $this->filterStatus === 'batal' ? Tagihan::onlyTrashed() : Tagihan::query();

        $data = $query
            ->with(['mahasiswa', 'komponen'])
            ->when($this->filterStatus !== '' && $this->filterStatus !== 'batal',
                fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterKomponen !== '', fn ($q) => $q->where('komponen_biaya_id', $this->filterKomponen))
            ->when($this->filterTahunAjaran !== '', fn ($q) => $q->where('tahun_ajaran', $this->filterTahunAjaran))
            ->when($this->cari !== '', fn ($q) => $q->where(function ($q) {
                $q->where('nomor', 'like', "%{$this->cari}%")
                  ->orWhereHas('mahasiswa', fn ($m) => $m->withTrashed()
                      ->where('nim', 'like', "%{$this->cari}%")
                      ->orWhere('nama', 'like', "%{$this->cari}%"));
            }))
            ->orderByDesc('id')
            ->paginate(20);

        $hasilMhs = strlen($this->cariMhs) >= 2
            ? Mahasiswa::with('programStudi')
                ->where(fn ($q) => $q->where('nim', 'like', "%{$this->cariMhs}%")
                    ->orWhere('nama', 'like', "%{$this->cariMhs}%"))
                ->orderBy('nim')->limit(8)->get()
            : collect();

        return view('livewire.tagihan.index', [
            'data' => $data,
            'hasilMhs' => $hasilMhs,
            'daftarKomponen' => KomponenBiaya::where('is_active', true)->orderBy('kode')->get(),
            'daftarAnggaran' => Anggaran::with('tahun')
                ->where('tipe', 'D')->where('status', 'approved')
                ->whereHas('tahun', fn ($q) => $q->where('status', '!=', 'ditutup'))
                ->orderBy('kode')->get(),
            'daftarTahunAjaran' => Tagihan::withTrashed()->distinct()->orderByDesc('tahun_ajaran')->pluck('tahun_ajaran'),
        ]);
    }
}
