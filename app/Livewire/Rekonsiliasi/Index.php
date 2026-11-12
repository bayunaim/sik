<?php

namespace App\Livewire\Rekonsiliasi;

use App\Models\MutasiBank;
use App\Models\Pembayaran;
use App\Models\RekeningBank;
use App\Services\RekonsiliasiService;
use DomainException;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
    public string $rekeningId = '';
    #[Url(except: 'belum_cocok')]
    public string $filterStatus = 'belum_cocok';
    #[Url(except: '')]
    public string $dari = '';
    #[Url(except: '')]
    public string $sampai = '';
    #[Url(except: '')]
    public string $cari = '';

    // cocokkan manual
    public ?int $cocokId = null;
    public string $cariKandidat = '';

    // abaikan
    public ?int $abaikanId = null;
    public string $alasanAbaikan = '';

    public ?array $hasilOtomatis = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);
    }

    public function updatingRekeningId(): void { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }
    public function updatingDari(): void { $this->resetPage(); }
    public function updatingSampai(): void { $this->resetPage(); }
    public function updatingCari(): void { $this->resetPage(); }

    protected function rekening(): ?int
    {
        return ctype_digit($this->rekeningId) && (int) $this->rekeningId > 0 ? (int) $this->rekeningId : null;
    }

    protected function tanggal(string $s): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $s : null;
    }

    public function cocokOtomatis(RekonsiliasiService $svc): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        try {
            $this->hasilOtomatis = $svc->cocokOtomatis(auth()->user(), $this->rekening(), $this->tanggal($this->dari), $this->tanggal($this->sampai));
        } catch (DomainException $e) {
            session()->flash('gagal', $e->getMessage());
        }
    }

    public function bukaCocok(int $id): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        $m = MutasiBank::findOrFail($id);
        if ($m->status !== MutasiBank::BELUM_COCOK) {
            session()->flash('gagal', 'Mutasi ini sudah dicocokkan atau diabaikan.');
            return;
        }

        $this->cocokId = $m->id;
        $this->cariKandidat = '';
        $this->resetErrorBag('cocok');
    }

    public function tutupCocok(): void
    {
        $this->reset(['cocokId', 'cariKandidat']);
        $this->resetErrorBag('cocok');
    }

    public function pilih(string $type, int $id, RekonsiliasiService $svc): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        try {
            $svc->cocokkan((int) $this->cocokId, $type, $id, auth()->user());
        } catch (DomainException $e) {
            $this->addError('cocok', $e->getMessage());
            return;
        }

        session()->flash('sukses', 'Mutasi dicocokkan.');
        $this->tutupCocok();
    }

    public function lepas(int $id, RekonsiliasiService $svc): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        try {
            $svc->lepas($id, auth()->user());
        } catch (DomainException $e) {
            session()->flash('gagal', $e->getMessage());
            return;
        }

        session()->flash('sukses', 'Pencocokan dilepas. Mutasi kembali ke Belum Cocok.');
    }

    public function konfirmasiAbaikan(int $id): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        $this->abaikanId = $id;
        $this->alasanAbaikan = '';
        $this->resetValidation();
    }

    public function simpanAbaikan(RekonsiliasiService $svc): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        $this->validate(
            ['alasanAbaikan' => ['required', 'string', 'min:5', 'max:255']],
            ['alasanAbaikan.required' => 'Alasan wajib diisi (misalnya biaya admin atau bunga bank).', 'alasanAbaikan.min' => 'Alasan minimal 5 karakter.']
        );

        try {
            $svc->abaikan((int) $this->abaikanId, $this->alasanAbaikan, auth()->user());
        } catch (DomainException $e) {
            $this->addError('alasanAbaikan', $e->getMessage());
            return;
        }

        session()->flash('sukses', 'Mutasi diabaikan.');
        $this->tutupAbaikan();
    }

    public function tutupAbaikan(): void
    {
        $this->reset(['abaikanId', 'alasanAbaikan']);
        $this->resetValidation();
    }

    public function batalAbaikan(int $id, RekonsiliasiService $svc): void
    {
        abort_unless(auth()->user()->can('rekonsiliasi.kelola'), 403);

        try {
            $svc->batalAbaikan($id, auth()->user());
        } catch (DomainException $e) {
            session()->flash('gagal', $e->getMessage());
        }
    }

    public function render()
    {
        $svc = app(RekonsiliasiService::class);
        $rek = $this->rekening();
        $dari = $this->tanggal($this->dari);
        $sampai = $this->tanggal($this->sampai);

        $data = MutasiBank::query()
            ->with([
                'rekening', 'pencocok',
                'cocok' => fn (MorphTo $m) => $m->morphWith([Pembayaran::class => ['mahasiswa']]),
            ])
            ->when($rek, fn ($q) => $q->where('rekening_bank_id', $rek))
            ->when($dari, fn ($q) => $q->whereDate('tanggal', '>=', $dari))
            ->when($sampai, fn ($q) => $q->whereDate('tanggal', '<=', $sampai))
            ->when(
                $this->filterStatus === 'bermasalah',
                fn ($q) => $q->bermasalah(),
                fn ($q) => $q->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            )
            ->when($this->cari !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('keterangan', 'like', "%{$this->cari}%")
                ->orWhere('referensi', 'like', "%{$this->cari}%")))
            ->orderByDesc('tanggal')->orderByDesc('id')
            ->paginate(25);

        $mutasiCocok = $this->cocokId ? MutasiBank::with('rekening')->find($this->cocokId) : null;

        return view('livewire.rekonsiliasi.index', [
            'svc' => $svc,
            'data' => $data,
            'daftarRekening' => RekeningBank::orderByDesc('is_active')->orderBy('nama_bank')->get(),
            'ringkasan' => $svc->ringkasan($rek, $dari, $sampai),
            'sistem' => $svc->sistemBelumDiBank($dari, $sampai),
            'mutasiCocok' => $mutasiCocok,
            'kandidat' => $mutasiCocok ? $svc->kandidatManual($mutasiCocok, $this->cariKandidat) : collect(),
        ]);
    }
}
