<?php

namespace App\Livewire\Pengeluaran;

use App\Models\Anggaran;
use App\Models\ChartOfAccount;
use App\Models\Pengeluaran;
use App\Services\PengeluaranService;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    #[Url(except: '')]
    public string $filterStatus = '';
    #[Url(except: '')]
    public string $filterAnggaran = '';
    #[Url(except: '')]
    public string $filterBulan = '';
    #[Url(except: '')]
    public string $filterTahun = '';
    #[Url(except: '')]
    public string $cari = '';

    // form pengajuan
    public bool $tampilForm = false;
    public string $tanggal = '';
    public string $anggaran_id = '';
    public string $akun_beban_id = '';
    public string $keperluan = '';
    public string $nominal = '';
    public string $metode = 'Transfer Bank';
    public $lampiran = null;

    // tolak
    public ?int $tolakId = null;
    public string $alasanTolak = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('pengeluaran.lihat'), 403);
    }

    public function updatingFilterStatus(): void { $this->resetPage(); }
    public function updatingFilterAnggaran(): void { $this->resetPage(); }
    public function updatingFilterBulan(): void { $this->resetPage(); }
    public function updatingFilterTahun(): void { $this->resetPage(); }
    public function updatingCari(): void { $this->resetPage(); }

    protected function rules(): array
    {
        return [
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'anggaran_id' => ['required', Rule::exists('anggaran', 'id')->where('tipe', 'K')->where('status', 'approved')->whereNull('deleted_at')],
            'akun_beban_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('tipe', 'beban')->where('is_active', true)->whereNull('deleted_at')],
            'keperluan' => ['required', 'string', 'max:255'],
            'nominal' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'metode' => ['required', Rule::in(Pengeluaran::METODE)],
            'lampiran' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ];
    }

    protected function messages(): array
    {
        return [
            'tanggal.required' => 'Tanggal wajib diisi.',
            'tanggal.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'anggaran_id.required' => 'Kode anggaran pengeluaran wajib dipilih.',
            'anggaran_id.exists' => 'Kode anggaran harus bertipe K dan sudah Approved.',
            'akun_beban_id.required' => 'Akun beban wajib dipilih.',
            'akun_beban_id.exists' => 'Akun harus bertipe Beban dan aktif.',
            'keperluan.required' => 'Keperluan wajib diisi.',
            'nominal.required' => 'Nominal wajib diisi.',
            'nominal.gt' => 'Nominal harus lebih dari nol.',
            'nominal.decimal' => 'Nominal maksimal 2 angka di belakang koma.',
            'lampiran.mimes' => 'Lampiran harus berupa JPG, PNG, atau PDF.',
            'lampiran.max' => 'Ukuran lampiran maksimal 2 MB.',
        ];
    }

    public function tambah(): void
    {
        abort_unless(auth()->user()->can('pengeluaran.ajukan'), 403);

        $this->reset(['anggaran_id', 'akun_beban_id', 'keperluan', 'nominal', 'lampiran']);
        $this->metode = 'Transfer Bank';
        $this->tanggal = now()->format('Y-m-d');
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function simpan(PengeluaranService $service): void
    {
        $user = auth()->user();
        abort_unless($user->can('pengeluaran.ajukan'), 403);

        $data = $this->validate();
        $path = $this->lampiran ? $this->lampiran->store('lampiran-pengeluaran', 'local') : null;

        try {
            $p = $service->ajukan($data, $user, $path);
        } catch (DomainException $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            $this->addError('form', $e->getMessage());
            return;
        }

        session()->flash('sukses', "Pengajuan {$p->nomor} dikirim dan menunggu persetujuan.");
        $this->tutupForm();
    }

    public function setujui(int $id, PengeluaranService $service): void
    {
        try {
            $r = $service->setujui($id, auth()->user());
        } catch (DomainException $e) {
            session()->flash('gagal', $e->getMessage());
            return;
        }

        $pesan = "Pengeluaran {$r['pengeluaran']->nomor} disetujui, jurnal otomatis dibuat.";
        if ($r['melebihi']) {
            $pesan .= ' Perhatian: nominal ini melebihi sisa anggaran pada kodenya (tercatat di audit log).';
        }
        session()->flash($r['melebihi'] ? 'peringatan' : 'sukses', $pesan);
    }

    public function konfirmasiTolak(int $id): void
    {
        abort_unless(auth()->user()->can('pengeluaran.approve'), 403);

        $this->tolakId = $id;
        $this->alasanTolak = '';
        $this->resetValidation();
    }

    public function simpanTolak(PengeluaranService $service): void
    {
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

        session()->flash('sukses', 'Pengeluaran ditolak.');
        $this->tutupTolak();
    }

    public function tutupTolak(): void
    {
        $this->reset(['tolakId', 'alasanTolak']);
        $this->resetValidation();
    }

    public function batalkan(int $id, PengeluaranService $service): void
    {
        try {
            $service->batalkan($id, auth()->user());
        } catch (DomainException $e) {
            session()->flash('gagal', $e->getMessage());
            return;
        }

        session()->flash('sukses', 'Pengajuan dibatalkan.');
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->reset(['lampiran']);
        $this->resetValidation();
    }

    protected function dasar()
    {
        return Pengeluaran::query()
            ->when($this->filterAnggaran !== '', fn ($q) => $q->where('anggaran_id', $this->filterAnggaran))
            ->when($this->filterBulan !== '', fn ($q) => $q->whereMonth('tanggal', $this->filterBulan))
            ->when($this->filterTahun !== '', fn ($q) => $q->whereYear('tanggal', $this->filterTahun))
            ->when($this->cari !== '', fn ($q) => $q->where(function ($q) {
                $q->where('nomor', 'like', "%{$this->cari}%")
                  ->orWhere('keperluan', 'like', "%{$this->cari}%");
            }));
    }

    public function render(PengeluaranService $service)
    {
        $user = auth()->user();

        // FR-065: panel ringkasan (mengikuti filter, kecuali status)
        $ringkasan = (clone $this->dasar())
            ->selectRaw('status, COUNT(*) as jumlah, SUM(nominal) as total')
            ->groupBy('status')->get()->keyBy('status');

        $data = (clone $this->dasar())
            ->with(['anggaran', 'akunBeban', 'pengaju', 'pemroses'])
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            ->orderByDesc('id')
            ->paginate(20);

        // siapkan info wewenang dan peringatan per baris
        $data->getCollection()->each(function ($row) use ($service, $user) {
            $row->alasan_blokir = $service->alasanTidakBisaSetujui($row, $user);
            $row->melebihi = $row->status === Pengeluaran::PENDING
                && BigDecimal::of($row->nominal)->isGreaterThan($service->sisaAnggaran($row->anggaran));
        });

        // peringatan di form (FR-024)
        $peringatan = null;
        if ($this->tampilForm && $this->anggaran_id !== '' && is_numeric($this->nominal)) {
            $a = Anggaran::find($this->anggaran_id);
            if ($a && $a->tipe === 'K') {
                $sisa = $service->sisaAnggaran($a);
                $peringatan = [
                    'sisa' => (string) $sisa,
                    'melebihi' => BigDecimal::of($this->nominal)->isGreaterThan($sisa),
                ];
            }
        }

        $semuaAnggaran = Anggaran::with('tahun')->where('tipe', 'K')->where('status', 'approved')->orderBy('kode')->get();

        return view('livewire.pengeluaran.index', [
            'data' => $data,
            'ringkasan' => $ringkasan,
            'peringatan' => $peringatan,
            'daftarFilterAnggaran' => $semuaAnggaran,
            'daftarAnggaran' => $semuaAnggaran->filter(fn ($a) => ! $a->tahun->isDitutup()),
            'daftarAkun' => ChartOfAccount::where('tipe', 'beban')->where('is_active', true)
                ->whereDoesntHave('children')->orderBy('kode')->get(),
            'daftarTahun' => DB::table('pengeluaran')->whereNull('deleted_at')
                ->selectRaw('DISTINCT YEAR(tanggal) as tahun')->orderByDesc('tahun')->pluck('tahun'),
        ]);
    }
}
