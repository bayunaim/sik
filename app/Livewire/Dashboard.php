<?php

namespace App\Livewire;

use App\Models\Anggaran;
use App\Models\Pembayaran;
use App\Models\Pengeluaran;
use App\Models\Tagihan;
use App\Models\TahunAnggaran;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    public function render()
    {
        $user = auth()->user();
        $tahun = TahunAnggaran::aktifSekarang();
        $bulan = now()->month;
        $thn = now()->year;

        $d = ['tahun' => $tahun, 'bulanIni' => now()->translatedFormat('F Y')];

        if ($user->can('pembayaran.lihat')) {
            $bayar = Pembayaran::query()
                ->whereMonth('tanggal_bayar', $bulan)->whereYear('tanggal_bayar', $thn)
                ->selectRaw('status, COUNT(*) as jumlah, COALESCE(SUM(nominal),0) as total')
                ->groupBy('status')->get()->keyBy('status');
            $d['bayarLunas'] = $bayar['terverifikasi'] ?? null;
            $d['bayarPending'] = $bayar['pending'] ?? null;
        }

        if ($user->can('pengeluaran.lihat')) {
            $d['keluarBulanIni'] = (float) Pengeluaran::where('status', 'disetujui')
                ->whereMonth('tanggal', $bulan)->whereYear('tanggal', $thn)->sum('nominal');
        }

        if ($user->can('anggaran.lihat') && $tahun) {
            $totalAnggaran = Anggaran::where('tahun_anggaran_id', $tahun->id)->where('status', 'approved')
                ->selectRaw('tipe, COALESCE(SUM(jumlah),0) as total')->groupBy('tipe')->pluck('total', 'tipe');
            $anggaranK = (float) ($totalAnggaran['K'] ?? 0);
            $terpakai = (float) Pengeluaran::where('status', 'disetujui')
                ->where('tahun_anggaran_id', $tahun->id)->sum('nominal');

            $d['anggaranD'] = (float) ($totalAnggaran['D'] ?? 0);
            $d['anggaranK'] = $anggaranK;
            $d['terpakai'] = $terpakai;
            $d['persenPakai'] = $anggaranK > 0 ? min(100, $terpakai / $anggaranK * 100) : 0;
        }

        if ($user->can('tagihan.lihat')) {
            $d['totalTagihan'] = $tahun
                ? (float) Tagihan::where('tahun_anggaran_id', $tahun->id)->sum('nominal')
                : 0;
            $d['totalTunggakan'] = (float) Tagihan::where('status', '!=', Tagihan::LUNAS)
                ->selectRaw('COALESCE(SUM(nominal - terbayar),0) as t')->value('t');
            $d['jumlahTunggakan'] = Tagihan::where('status', '!=', Tagihan::LUNAS)->count();
        }

        // FR-101
        if ($user->can('pembayaran.verifikasi')) {
            $d['menungguVerifikasi'] = Pembayaran::with(['mahasiswa', 'tagihan.komponen'])
                ->where('status', 'pending')->orderBy('id')->limit(5)->get();
            $d['totalMenungguVerifikasi'] = Pembayaran::where('status', 'pending')->count();
        }
        if ($user->can('pengeluaran.approve')) {
            $d['menungguApproval'] = Pengeluaran::with(['pengaju', 'anggaran'])
                ->where('status', 'pending')->orderBy('id')->limit(5)->get();
            $d['totalMenungguApproval'] = Pengeluaran::where('status', 'pending')->count();
        }

        return view('livewire.dashboard', $d);
    }
}
