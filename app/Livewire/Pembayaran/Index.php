<?php

namespace App\Livewire\Pembayaran;

use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Services\PembayaranService;
use DomainException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination, WithFileUploads;

    protected string $paginationTheme = 'bootstrap';

    #[Url(except: 'pending')]
    public string $filterStatus = 'pending';
    #[Url(except: '')]
    public string $cari = '';

    // form catat pembayaran
    public bool $tampilForm = false;
    public string $cariTagihan = '';
    public string $tagihan_id = '';
    public string $labelTagihan = '';
    public string $sisa = '';
    public string $nominal = '';
    public string $tanggal_bayar = '';
    public string $metode = 'Transfer Bank';
    public string $referensi = '';
    public $bukti = null;

    // tolak
    public ?int $tolakId = null;
    public string $alasanTolak = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('pembayaran.lihat'), 403);
    }

    public function updatingFilterStatus(): void { $this->resetPage(); }
    public function updatingCari(): void { $this->resetPage(); }

    protected function rules(): array
    {
        return [
            'tagihan_id' => ['required', Rule::exists('tagihan', 'id')->whereNull('deleted_at')],
            'nominal' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'tanggal_bayar' => ['required', 'date', 'before_or_equal:today'],
            'metode' => ['required', Rule::in(Pembayaran::METODE)],
            'referensi' => ['nullable', 'string', 'max:100'],
            'bukti' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ];
    }

    protected function messages(): array
    {
        return [
            'tagihan_id.required' => 'Pilih tagihan terlebih dahulu.',
            'nominal.required' => 'Nominal wajib diisi.',
            'nominal.numeric' => 'Nominal harus berupa angka.',
            'nominal.gt' => 'Nominal harus lebih dari nol.',
            'nominal.decimal' => 'Nominal maksimal 2 angka di belakang koma.',
            'tanggal_bayar.required' => 'Tanggal bayar wajib diisi.',
            'tanggal_bayar.before_or_equal' => 'Tanggal bayar tidak boleh di masa depan.',
            'bukti.mimes' => 'Bukti harus berupa JPG, PNG, atau PDF.',
            'bukti.max' => 'Ukuran bukti maksimal 2 MB.',
        ];
    }

    public function tambah(): void
    {
        abort_unless(auth()->user()->can('pembayaran.input'), 403);

        $this->resetForm();
        $this->tampilForm = true;
    }

    public function pilihTagihan(int $id, PembayaranService $service): void
    {
        abort_unless(auth()->user()->can('pembayaran.input'), 403);

        $t = Tagihan::with(['mahasiswa', 'komponen'])->findOrFail($id);
        $sisa = $service->sisaBisaDibayar($t);

        if ($sisa->isLessThanOrEqualTo(0)) {
            $this->addError('tagihan_id', 'Tagihan ini sudah lunas, atau seluruh sisanya sedang menunggu verifikasi.');
            return;
        }

        $this->resetErrorBag('tagihan_id');
        $this->tagihan_id = (string) $t->id;
        $this->labelTagihan = "{$t->nomor} - {$t->mahasiswa->nama} ({$t->komponen->nama}, {$t->semester} {$t->tahun_ajaran})";
        $this->sisa = (string) $sisa;
        $this->nominal = (string) $sisa;
        $this->cariTagihan = '';
    }

    public function gantiTagihan(): void
    {
        $this->reset(['tagihan_id', 'labelTagihan', 'sisa', 'nominal']);
    }

    public function simpan(PembayaranService $service): void
    {
        $user = auth()->user();
        abort_unless($user->can('pembayaran.input'), 403);

        $data = $this->validate();

        $path = $this->bukti ? $this->bukti->store('bukti-pembayaran', 'local') : null;

        try {
            $service->catat($data, $user, $path);
        } catch (DomainException $e) {
            if ($path) {
                \Storage::disk('local')->delete($path);
            }
            $this->addError('form', $e->getMessage());
            return;
        }

        session()->flash('sukses', 'Pembayaran dicatat dan menunggu verifikasi.');
        $this->tutupForm();
    }

    public function verifikasi(int $id, PembayaranService $service): void
    {
        abort_unless(auth()->user()->can('pembayaran.verifikasi'), 403);

        try {
            $p = $service->verifikasi($id, auth()->user());
        } catch (DomainException $e) {
            session()->flash('gagal', $e->getMessage());
            return;
        }

        session()->flash('sukses', "Pembayaran terverifikasi. Kwitansi {$p->nomor_kwitansi}, jurnal otomatis dibuat.");
    }

    public function konfirmasiTolak(int $id): void
    {
        abort_unless(auth()->user()->can('pembayaran.verifikasi'), 403);

        $this->tolakId = $id;
        $this->alasanTolak = '';
        $this->resetValidation();
    }

    public function simpanTolak(PembayaranService $service): void
    {
        abort_unless(auth()->user()->can('pembayaran.verifikasi'), 403);

        $this->validate(
            ['alasanTolak' => ['required', 'string', 'min:5', 'max:500']],
            ['alasanTolak.required' => 'Alasan penolakan wajib diisi.', 'alasanTolak.min' => 'Alasan minimal 5 karakter.']
        );

        try {
            $service->tolak($this->tolakId, trim($this->alasanTolak), auth()->user());
        } catch (DomainException $e) {
            $this->addError('alasanTolak', $e->getMessage());
            return;
        }

        session()->flash('sukses', 'Pembayaran ditolak.');
        $this->tutupTolak();
    }

    public function tutupTolak(): void
    {
        $this->reset(['tolakId', 'alasanTolak']);
        $this->resetValidation();
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['cariTagihan', 'tagihan_id', 'labelTagihan', 'sisa', 'nominal', 'referensi', 'bukti']);
        $this->metode = 'Transfer Bank';
        $this->tanggal_bayar = now()->format('Y-m-d');
        $this->resetValidation();
    }

    public function render()
    {
        $data = Pembayaran::query()
            ->with(['mahasiswa', 'tagihan.komponen', 'penginput'])
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->cari !== '', fn ($q) => $q->where(function ($q) {
                $q->where('nomor_kwitansi', 'like', "%{$this->cari}%")
                  ->orWhere('referensi', 'like', "%{$this->cari}%")
                  ->orWhereHas('mahasiswa', fn ($m) => $m->withTrashed()
                      ->where('nim', 'like', "%{$this->cari}%")
                      ->orWhere('nama', 'like', "%{$this->cari}%"));
            }))
            ->orderByDesc('id')
            ->paginate(20);

        $hasilTagihan = strlen($this->cariTagihan) >= 2
            ? Tagihan::with(['mahasiswa', 'komponen'])
                ->whereIn('status', [Tagihan::BELUM_BAYAR, Tagihan::SEBAGIAN, Tagihan::PENDING])
                ->where(function ($q) {
                    $q->where('nomor', 'like', "%{$this->cariTagihan}%")
                      ->orWhereHas('mahasiswa', fn ($m) => $m->where('nim', 'like', "%{$this->cariTagihan}%")
                          ->orWhere('nama', 'like', "%{$this->cariTagihan}%"));
                })
                ->orderByDesc('id')->limit(8)->get()
            : collect();

        return view('livewire.pembayaran.index', [
            'data' => $data,
            'hasilTagihan' => $hasilTagihan,
            'jumlahPending' => Pembayaran::where('status', Pembayaran::PENDING)->count(),
        ]);
    }
}
