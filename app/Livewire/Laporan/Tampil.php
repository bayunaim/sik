<?php

namespace App\Livewire\Laporan;

use App\Models\ProgramStudi;
use App\Models\Mahasiswa;
use App\Models\Tagihan;
use App\Models\TahunAnggaran;
use App\Services\LaporanService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Tampil extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Locked]
    public string $jenis = 'jurnal';

    #[Url(except: '')] public string $dari = '';
    #[Url(except: '')] public string $sampai = '';
    #[Url(except: '')] public string $tahun_id = '';
    #[Url(except: '')] public string $tipe = '';
    #[Url(except: '')] public string $prodi = '';
    #[Url(except: '')] public string $angkatan = '';
    #[Url(except: '')] public string $semester = '';
    #[Url(except: '')] public string $tahun_ajaran = '';

    public function mount(string $jenis): void
    {
        abort_unless(auth()->user()->can('laporan.lihat'), 403);
        abort_unless(in_array($jenis, LaporanService::JENIS, true), 404);

        $this->jenis = $jenis;

        // isi default hanya jika kosong (nilai dari URL tetap dipakai)
        $f = app(LaporanService::class)->normalisasi($jenis, $this->filter());
        $this->dari = $this->dari !== '' ? $this->dari : (string) $f['dari'];
        $this->sampai = $this->sampai !== '' ? $this->sampai : (string) $f['sampai'];
        $this->tahun_id = $this->tahun_id !== '' ? $this->tahun_id : $f['tahun_id'];
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    // Ganti tahun anggaran: rentang tanggal ikut tahun yang baru
    public function updatedTahunId(): void
    {
        $this->dari = '';
        $this->sampai = '';
        $f = app(LaporanService::class)->normalisasi($this->jenis, $this->filter());
        $this->dari = (string) $f['dari'];
        $this->sampai = (string) $f['sampai'];
    }

    protected function filter(): array
    {
        return [
            'dari' => $this->dari, 'sampai' => $this->sampai, 'tahun_id' => $this->tahun_id,
            'tipe' => $this->tipe, 'prodi' => $this->prodi, 'angkatan' => $this->angkatan,
            'semester' => $this->semester, 'tahun_ajaran' => $this->tahun_ajaran,
        ];
    }

    public function render(LaporanService $service)
    {
        abort_unless(auth()->user()->can('laporan.lihat'), 403);

        $filter = $service->normalisasi($this->jenis, $this->filter());
        $lap = $service->susun($this->jenis, $filter);

        $per = 25;
        $halamanKe = LengthAwarePaginator::resolveCurrentPage();
        $halaman = new LengthAwarePaginator(
            $lap['baris']->forPage($halamanKe, $per)->values(),
            $lap['baris']->count(),
            $per,
            $halamanKe,
            ['path' => request()->url()]
        );

        return view('livewire.laporan.tampil', [
            'lap' => $lap,
            'halaman' => $halaman,
            'filter' => $filter,
            'daftarTahun' => TahunAnggaran::orderByDesc('tanggal_mulai')->get(),
            'daftarProdi' => ProgramStudi::orderBy('kode')->get(),
            'daftarAngkatan' => Mahasiswa::query()->distinct()->orderByDesc('angkatan')->pluck('angkatan'),
            'daftarTahunAjaran' => Tagihan::withTrashed()->distinct()->orderByDesc('tahun_ajaran')->pluck('tahun_ajaran'),
        ]);
    }
}
