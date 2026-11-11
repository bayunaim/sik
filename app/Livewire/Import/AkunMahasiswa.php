<?php

namespace App\Livewire\Import;

use App\Services\AkunMahasiswaService;
use Livewire\Component;

class AkunMahasiswa extends Component
{
    public ?array $hasil = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('import.sia'), 403);
    }

    public function buat(AkunMahasiswaService $svc): void
    {
        abort_unless(auth()->user()->can('import.sia'), 403);
        if (! app()->runningInConsole()) {
            set_time_limit(180);
        }

        $this->hasil = $svc->buat(AkunMahasiswaService::BATCH, auth()->user());
    }

    public function render(AkunMahasiswaService $svc)
    {
        return view('livewire.import.akun-mahasiswa', ['sisa' => $svc->sisa()]);
    }
}
