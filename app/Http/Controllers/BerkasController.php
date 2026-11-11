<?php

namespace App\Http\Controllers;

use App\Models\Mahasiswa;
use App\Models\Pembayaran;
use App\Models\Pengeluaran;
use App\Models\Tagihan;
use App\Services\StatusKeuanganService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BerkasController extends Controller
{
    public function buktiPembayaran(Request $request, Pembayaran $pembayaran)
    {
        abort_unless($request->user()->can('pembayaran.lihat'), 403);
        abort_unless($pembayaran->bukti_path && Storage::disk('local')->exists($pembayaran->bukti_path), 404);

        return response()->file(Storage::disk('local')->path($pembayaran->bukti_path), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function kwitansi(Request $request, Pembayaran $pembayaran)
    {
        abort_unless($request->user()->can('pembayaran.lihat'), 403);
        abort_unless($pembayaran->status === Pembayaran::TERVERIFIKASI, 404);

        $pembayaran->load(['mahasiswa', 'tagihan.komponen', 'pemverifikasi']);

        return Pdf::loadView('pdf.kwitansi', ['p' => $pembayaran])
            ->setPaper('a5', 'landscape')
            ->stream('Kwitansi-'.str_replace('/', '-', $pembayaran->nomor_kwitansi).'.pdf');
    }

    public function lampiranPengeluaran(Request $request, Pengeluaran $pengeluaran)
    {
        abort_unless($request->user()->can('pengeluaran.lihat'), 403);
        abort_unless($pengeluaran->lampiran_path && Storage::disk('local')->exists($pengeluaran->lampiran_path), 404);

        return response()->file(Storage::disk('local')->path($pengeluaran->lampiran_path), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function tagihan(Request $request, Tagihan $tagihan)
    {
        abort_unless($request->user()->can('tagihan.lihat'), 403);

        return self::pdfTagihanUntuk($tagihan);
    }

    public function suratLunas(Request $request, Mahasiswa $mahasiswa, StatusKeuanganService $svc)
    {
        abort_unless($request->user()->can('tagihan.lihat'), 403);

        return self::pdfSuratLunasUntuk($request, $mahasiswa, $svc);
    }

    public static function pdfTagihanUntuk(Tagihan $tagihan)
    {
        $tagihan->load(['mahasiswa.programStudi', 'komponen']);

        return Pdf::loadView('pdf.tagihan', ['t' => $tagihan])
            ->setPaper('a5', 'landscape')
            ->stream('Tagihan-'.str_replace('/', '-', $tagihan->nomor).'.pdf');
    }

    public static function pdfSuratLunasUntuk(Request $request, Mahasiswa $mahasiswa, StatusKeuanganService $svc)
    {
        $alasan = $svc->alasanTidakBisaSuratLunas($mahasiswa);
        abort_if($alasan !== null, 422, "Surat keterangan lunas belum dapat diterbitkan. {$alasan}");

        $mahasiswa->load('programStudi');
        $ringkas = $svc->ringkas($mahasiswa);

        activity('mahasiswa')
            ->causedBy($request->user())
            ->performedOn($mahasiswa)
            ->log("cetak surat keterangan lunas {$mahasiswa->nim}");

        return Pdf::loadView('pdf.surat-lunas', ['m' => $mahasiswa, 'r' => $ringkas, 'tanggal' => now()])
            ->setPaper('a4', 'portrait')
            ->stream('Surat-Lunas-'.preg_replace('/[^A-Za-z0-9._-]/', '-', $mahasiswa->nim).'.pdf');
    }
}
