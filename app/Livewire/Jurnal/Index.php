<?php

namespace App\Livewire\Jurnal;

use App\Models\ChartOfAccount;
use App\Models\Jurnal;
use App\Services\JurnalService;
use Brick\Math\BigDecimal;
use DomainException;
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
    public string $dari = '';
    #[Url(except: '')]
    public string $sampai = '';

    public ?int $bukaId = null;

    // Form jurnal penyesuaian (FR-073)
    public bool $tampilForm = false;
    public string $tanggal = '';
    public string $alasan = '';
    /** @var array<int,array{akun_id:string,debit:string,kredit:string}> */
    public array $baris = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('jurnal.lihat'), 403);
    }

    public function updatingCari(): void { $this->resetPage(); }
    public function updatingDari(): void { $this->resetPage(); }
    public function updatingSampai(): void { $this->resetPage(); }

    public function toggle(int $id): void
    {
        $this->bukaId = $this->bukaId === $id ? null : $id;
    }

    public function tambah(): void
    {
        abort_unless(auth()->user()->can('jurnal.penyesuaian'), 403);

        $this->reset(['alasan']);
        $this->tanggal = now()->format('Y-m-d');
        $this->baris = [$this->barisKosong(), $this->barisKosong()];
        $this->resetValidation();
        $this->tampilForm = true;
    }

    public function tambahBaris(): void
    {
        $this->baris[] = $this->barisKosong();
    }

    public function hapusBaris(int $i): void
    {
        if (count($this->baris) > 2) {
            unset($this->baris[$i]);
            $this->baris = array_values($this->baris);
        }
    }

    public function simpan(JurnalService $service): void
    {
        $user = auth()->user();
        abort_unless($user->can('jurnal.penyesuaian'), 403);

        $this->validate([
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'alasan' => ['required', 'string', 'min:5', 'max:200'],
            'baris' => ['required', 'array', 'min:2'],
            'baris.*.akun_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'baris.*.debit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'baris.*.kredit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ], [
            'tanggal.required' => 'Tanggal wajib diisi.',
            'tanggal.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'alasan.required' => 'Alasan penyesuaian wajib diisi.',
            'alasan.min' => 'Alasan minimal 5 karakter.',
            'baris.*.akun_id.required' => 'Akun wajib dipilih di setiap baris.',
            'baris.*.akun_id.exists' => 'Akun tidak dikenal.',
            'baris.*.debit.numeric' => 'Debit harus berupa angka.',
            'baris.*.kredit.numeric' => 'Kredit harus berupa angka.',
            'baris.*.debit.min' => 'Debit tidak boleh negatif.',
            'baris.*.kredit.min' => 'Kredit tidak boleh negatif.',
            'baris.*.debit.decimal' => 'Debit maksimal 2 angka di belakang koma.',
            'baris.*.kredit.decimal' => 'Kredit maksimal 2 angka di belakang koma.',
        ]);

        $baris = collect($this->baris)->map(fn ($b) => [
            'akun_id' => (int) $b['akun_id'],
            'debit' => ($b['debit'] ?? '') === '' ? '0' : $b['debit'],
            'kredit' => ($b['kredit'] ?? '') === '' ? '0' : $b['kredit'],
        ])->all();

        try {
            $j = $service->penyesuaian($this->tanggal, $this->alasan, $baris, $user);
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('sukses', "Jurnal penyesuaian {$j->nomor} dibuat.");
        $this->tampilForm = false;
    }

    public function tutupForm(): void
    {
        $this->tampilForm = false;
        $this->resetValidation();
    }

    /** Total debit, kredit, dan selisih untuk ditampilkan di form (hanya tampilan; pemeriksaan sebenarnya di service). */
    public function total(): array
    {
        $d = BigDecimal::zero();
        $k = BigDecimal::zero();
        foreach ($this->baris as $b) {
            if (is_numeric($b['debit'] ?? null)) {
                $d = $d->plus(BigDecimal::of($b['debit']));
            }
            if (is_numeric($b['kredit'] ?? null)) {
                $k = $k->plus(BigDecimal::of($b['kredit']));
            }
        }

        return ['debit' => $d, 'kredit' => $k, 'balance' => $d->isEqualTo($k) && ! $d->isZero()];
    }

    private function barisKosong(): array
    {
        return ['akun_id' => '', 'debit' => '', 'kredit' => ''];
    }

    public function render()
    {
        $data = Jurnal::query()
            ->with('detail.akun')
            ->when($this->dari !== '', fn ($q) => $q->whereDate('tanggal', '>=', $this->dari))
            ->when($this->sampai !== '', fn ($q) => $q->whereDate('tanggal', '<=', $this->sampai))
            ->when($this->cari !== '', fn ($q) => $q->where(function ($q) {
                $q->where('nomor', 'like', "%{$this->cari}%")
                  ->orWhere('keterangan', 'like', "%{$this->cari}%");
            }))
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate(15);

        $akun = $this->tampilForm
            ? ChartOfAccount::where('is_active', true)->whereDoesntHave('children')->orderBy('kode')->get(['id', 'kode', 'nama'])
            : collect();

        return view('livewire.jurnal.index', [
            'data' => $data,
            'akun' => $akun,
            'total' => $this->tampilForm ? $this->total() : null,
        ]);
    }
}
