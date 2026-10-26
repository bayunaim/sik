<?php

namespace App\Livewire\Penerimaan;

use App\Models\Anggaran;
use App\Models\PenerimaanDana;
use App\Services\PenerimaanService;
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

    public const SUMBER = ['hibah' => 'Hibah', 'donasi' => 'Donasi', 'lainnya' => 'Lainnya'];

    #[Url(except: '')]
    public string $filterSumber = '';
    #[Url(except: '')]
    public string $dari = '';
    #[Url(except: '')]
    public string $sampai = '';

    public bool $tampilForm = false;
    public string $sumber = 'hibah';
    public string $anggaran_id = '';
    public string $tanggal = '';
    public string $nominal = '';
    public string $metode = 'Transfer Bank';
    public string $keterangan = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('penerimaan.lihat'), 403);
    }

    public function updatingFilterSumber(): void { $this->resetPage(); }
    public function updatingDari(): void { $this->resetPage(); }
    public function updatingSampai(): void { $this->resetPage(); }

    protected function rules(): array
    {
        return [
            'sumber' => ['required', Rule::in(array_keys(self::SUMBER))],
            'anggaran_id' => ['required', Rule::exists('anggaran', 'id')->where('tipe', 'D')->where('status', 'approved')->whereNull('deleted_at')],
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'nominal' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'metode' => ['required', Rule::in(['Transfer Bank', 'Tunai'])],
            'keterangan' => ['required', 'string', 'max:255'],
        ];
    }

    protected function messages(): array
    {
        return [
            'anggaran_id.required' => 'Kode anggaran penerimaan wajib dipilih.',
            'anggaran_id.exists' => 'Kode anggaran harus bertipe D dan sudah Approved.',
            'tanggal.required' => 'Tanggal wajib diisi.',
            'tanggal.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'nominal.required' => 'Nominal wajib diisi.',
            'nominal.gt' => 'Nominal harus lebih dari nol.',
            'nominal.decimal' => 'Nominal maksimal 2 angka di belakang koma.',
            'keterangan.required' => 'Keterangan (misalnya nama pemberi) wajib diisi.',
        ];
    }

    public function tambah(): void
    {
        abort_unless(auth()->user()->can('penerimaan.input'), 403);

        $this->reset(['anggaran_id', 'nominal', 'keterangan']);
        $this->sumber = 'hibah';
        $this->metode = 'Transfer Bank';
        $this->tanggal = now()->format('Y-m-d');
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function simpan(PenerimaanService $service): void
    {
        $user = auth()->user();
        abort_unless($user->can('penerimaan.input'), 403);

        $data = $this->validate();

        try {
            $service->catatManual($data, $user);
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());
            return;
        }

        session()->flash('sukses', 'Penerimaan dana dicatat dan jurnal otomatis dibuat.');
        $this->tampilForm = false;
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetValidation();
    }

    public function render()
    {
        $data = PenerimaanDana::query()
            ->with(['anggaran', 'pembayaran.mahasiswa'])
            ->when($this->filterSumber !== '', fn ($q) => $q->where('sumber', $this->filterSumber))
            ->when($this->dari !== '', fn ($q) => $q->whereDate('tanggal', '>=', $this->dari))
            ->when($this->sampai !== '', fn ($q) => $q->whereDate('tanggal', '<=', $this->sampai))
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.penerimaan.index', [
            'data' => $data,
            'daftarAnggaran' => Anggaran::with('tahun')
                ->where('tipe', 'D')->where('status', 'approved')
                ->whereHas('tahun', fn ($q) => $q->where('status', '!=', 'ditutup'))
                ->orderBy('kode')->get(),
        ]);
    }
}
