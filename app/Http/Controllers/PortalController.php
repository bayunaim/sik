<?php

namespace App\Http\Controllers;

use App\Models\Mahasiswa;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Services\StatusKeuanganService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PortalController extends Controller
{
    /** Mahasiswa hanya boleh membuka pembayaran miliknya sendiri. */
    private function izin(Request $request, Pembayaran $pembayaran): void
    {
        abort_unless($request->user()->can('portal.lihat'), 403);

        $mhs = Mahasiswa::where('user_id', $request->user()->id)->first();
        abort_unless($mhs && $pembayaran->mahasiswa_id === $mhs->id, 404);
    }

    public function bukti(Request $request, Pembayaran $pembayaran)
    {
        $this->izin($request, $pembayaran);
        abort_unless($pembayaran->bukti_path && Storage::disk('local')->exists($pembayaran->bukti_path), 404);

        return response()->file(Storage::disk('local')->path($pembayaran->bukti_path), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function kwitansi(Request $request, Pembayaran $pembayaran)
    {
        $this->izin($request, $pembayaran);
        abort_unless($pembayaran->status === Pembayaran::TERVERIFIKASI, 404);

        $pembayaran->load(['mahasiswa', 'tagihan.komponen', 'pemverifikasi']);

        return Pdf::loadView('pdf.kwitansi', ['p' => $pembayaran])
            ->setPaper('a5', 'landscape')
            ->stream('Kwitansi-'.str_replace('/', '-', $pembayaran->nomor_kwitansi).'.pdf');
    }

    private function saya(Request $request): Mahasiswa
    {
        abort_unless($request->user()->can('portal.lihat'), 403);

        return Mahasiswa::where('user_id', $request->user()->id)->firstOrFail();
    }

    public function tagihan(Request $request, Tagihan $tagihan)
    {
        $mhs = $this->saya($request);
        abort_unless($tagihan->mahasiswa_id === $mhs->id, 404);

        return BerkasController::pdfTagihanUntuk($tagihan);
    }

    public function suratLunas(Request $request, StatusKeuanganService $svc)
    {
        return BerkasController::pdfSuratLunasUntuk($request, $this->saya($request), $svc);
    }
}
