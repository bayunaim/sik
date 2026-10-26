<?php

namespace App\Livewire\Jurnal;

use App\Models\Jurnal;
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

        return view('livewire.jurnal.index', ['data' => $data]);
    }
}
