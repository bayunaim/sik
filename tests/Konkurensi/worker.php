<?php

/*
| Proses pekerja untuk uji konkurensi. Dijalankan paralel oleh KonkurensiTest.php, JANGAN dijalankan manual.
| Pemakaian: php worker.php <aksi> <json-argumen> <waktu-mulai-unix-mikro>
| Semua proses menunggu sampai waktu mulai yang sama supaya benar-benar berbenturan.
*/

use App\Models\User;
use App\Services\JurnalService;
use App\Services\PembayaranService;
use App\Services\PengeluaranService;
use App\Services\TagihanService;
use Illuminate\Contracts\Console\Kernel;
use Spatie\Permission\PermissionRegistrar;

[, $aksi, $json, $mulai] = $argv + [null, null, '{}', '0'];

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function keluar(array $data): never
{
    echo PHP_EOL.json_encode($data, JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}

// Pengaman: hanya boleh menyentuh database uji.
if (app()->configurationIsCached()) {
    keluar(['ok' => false, 'jenis' => 'pengaman', 'pesan' => 'Konfigurasi ter-cache. Jalankan php artisan config:clear.']);
}
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'sik_test') {
    keluar(['ok' => false, 'jenis' => 'pengaman', 'pesan' => 'Pekerja hanya boleh memakai database sik_test.']);
}

app(PermissionRegistrar::class)->forgetCachedPermissions();

$arg = json_decode($json, true) ?: [];

while (microtime(true) < (float) $mulai) {
    usleep(100);
}

try {
    $user = User::findOrFail($arg['user_id']);

    match ($aksi) {
        'verifikasi' => app(PembayaranService::class)->verifikasi($arg['id'], $user),
        'catat' => app(PembayaranService::class)->catat([
            'tagihan_id' => $arg['tagihan_id'],
            'tanggal_bayar' => '2026-03-01',
            'nominal' => $arg['nominal'],
            'metode' => 'Transfer Bank',
        ], $user),
        'batalkan_pembayaran' => app(PembayaranService::class)->batalkan($arg['id'], 'Uji konkurensi', $user),
        'setujui' => app(PengeluaranService::class)->setujui($arg['id'], $user),
        'batalkan_pengeluaran' => app(PengeluaranService::class)->batalkanDisetujui($arg['id'], 'Uji konkurensi', $user),
        'tagihan_massal' => app(TagihanService::class)->buatMassal($arg['param'], $arg['ids'], $user),
        'jurnal' => app(JurnalService::class)->buat(
            ['tanggal' => '2026-03-01', 'tahun_anggaran_id' => $arg['tahun_id'], 'keterangan' => 'Jurnal uji konkurensi'],
            [
                ['akun_id' => $arg['akun_debit'], 'debit' => $arg['nominal'], 'kredit' => 0],
                ['akun_id' => $arg['akun_kredit'], 'debit' => 0, 'kredit' => $arg['nominal']],
            ],
            $user
        ),
        default => throw new InvalidArgumentException("Aksi tidak dikenal: {$aksi}"),
    };

    keluar(['ok' => true]);
} catch (DomainException $e) {
    keluar(['ok' => false, 'jenis' => 'domain', 'pesan' => $e->getMessage()]);
} catch (Throwable $e) {
    keluar(['ok' => false, 'jenis' => 'lain', 'pesan' => get_class($e).': '.$e->getMessage()]);
}
