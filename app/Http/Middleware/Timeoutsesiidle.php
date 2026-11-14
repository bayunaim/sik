<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-004: sesi berakhir bila pengguna tidak beraktivitas lebih dari session.idle_timeout menit
 * (0 = nonaktif). Aktivitas = request apa pun KECUALI polling Livewire (lonceng, status import),
 * karena polling jalan sendiri tiap beberapa detik dan akan menjaga sesi hidup selamanya
 * selama tab terbuka.
 *
 * Didaftarkan di grup middleware "web" (bootstrap/app.php) supaya ikut berlaku untuk
 * /livewire/update, bukan hanya untuk halaman biasa.
 */
class TimeoutSesiIdle
{
    private const KUNCI = 'aktivitas_terakhir';

    /** Method Livewire yang hanya menyegarkan tampilan, bukan aksi pengguna. */
    public const METHOD_PASIF = ['$refresh', 'periksaStatus'];

    public function handle(Request $request, Closure $next): Response
    {
        $menit = (int) config('session.idle_timeout', 0);

        if ($menit <= 0 || ! $request->hasSession() || ! $request->user()) {
            return $next($request);
        }

        $session = $request->session();
        $terakhir = $session->get(self::KUNCI);
        $sekarang = now()->getTimestamp();

        if ($terakhir !== null && ($sekarang - (int) $terakhir) > $menit * 60) {
            Auth::logout(); // sekaligus menghapus cookie "ingat saya"
            $session->invalidate();
            $session->regenerateToken();

            if ($this->permintaanLivewire($request) || $request->expectsJson()) {
                abort(419, 'Sesi berakhir karena tidak ada aktivitas.');
            }

            return redirect()->route('login')->withErrors([
                'login' => "Sesi berakhir karena tidak ada aktivitas selama {$menit} menit. Silakan masuk kembali.",
            ]);
        }

        // Polling tidak menyegarkan penanda; request pertama setelah login membuat penanda awal.
        if ($terakhir === null || ! $this->polling($request)) {
            $session->put(self::KUNCI, $sekarang);
        }

        return $next($request);
    }

    private function permintaanLivewire(Request $request): bool
    {
        return $request->hasHeader('X-Livewire') || $request->routeIs('livewire.update');
    }

    /** Permintaan Livewire yang seluruh isinya hanya penyegaran: tanpa input pengguna dan tanpa aksi. */
    private function polling(Request $request): bool
    {
        if (! $this->permintaanLivewire($request)) {
            return false;
        }

        $komponen = (array) $request->input('components', []);
        if ($komponen === []) {
            return false;
        }

        foreach ($komponen as $k) {
            if (! empty($k['updates'])) {
                return false; // pengguna mengetik atau mengubah isian
            }

            $panggilan = (array) ($k['calls'] ?? []);
            if ($panggilan === []) {
                return false;
            }

            foreach ($panggilan as $c) {
                if (! in_array($c['method'] ?? null, self::METHOD_PASIF, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
