<?php

/*
| Uji konkurensi: beberapa proses PHP berbenturan sungguhan pada database sik_test.
| Tidak ikut `php artisan test` biasa (lambat dan memakai database tanpa rollback). Jalankan terpisah:
|
|     php artisan config:clear
|     php artisan test tests/Konkurensi
|
| Setiap tes memakai migrate:fresh, jadi database sik_test dikosongkan. Jangan arahkan ke database lain.
| Lock Cache::lock memakai store `file` agar dipakai bersama oleh semua proses (di produksi: database/redis).
*/

use App\Models\Jurnal;
use App\Models\PenerimaanDana;
use App\Models\Pembayaran;
use App\Models\Pengeluaran;
use App\Models\Tagihan;
use App\Services\PembayaranService;
use App\Services\PengeluaranService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\KomponenBiayaSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, DatabaseMigrations::class)
    ->beforeEach(function () {
        if (config('database.connections.mysql.database') !== 'sik_test' || config('database.default') !== 'mysql') {
            throw new RuntimeException('Uji konkurensi hanya boleh memakai database MySQL sik_test.');
        }

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(KomponenBiayaSeeder::class);

        $this->admin = userDengan('Admin Keuangan', ['batas_persetujuan' => '50000000']);
    });

/**
 * Jalankan semua pekerjaan sebagai proses terpisah yang mulai pada detik yang sama.
 *
 * @param  array<int, array{0: string, 1: array}>  $pekerjaan  [[aksi, argumen], ...]
 * @return array<int, array>  hasil per pekerja: ['ok' => bool, 'jenis' => ..., 'pesan' => ...]
 */
function jalankanParalel(array $pekerjaan, float $jeda = 3.0): array
{
    $mulai = microtime(true) + $jeda;
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => 'sik_test',
        'DB_URL' => '',
        'CACHE_STORE' => 'file',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ]);

    $proses = [];
    foreach ($pekerjaan as $i => [$aksi, $arg]) {
        $cmd = [PHP_BINARY, __DIR__.'/worker.php', $aksi, json_encode($arg), sprintf('%.6F', $mulai)];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        if (! is_resource($p)) {
            throw new RuntimeException('Gagal menjalankan proses pekerja.');
        }
        $proses[$i] = [$p, $pipes];
    }

    $hasil = [];
    foreach ($proses as $i => [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);

        $baris = array_values(array_filter(array_map('trim', explode("\n", $out))));
        $terakhir = $baris ? end($baris) : '';
        $hasil[$i] = json_decode($terakhir, true)
            ?? ['ok' => false, 'jenis' => 'crash', 'pesan' => substr(trim($out.' '.$err), 0, 600)];
    }

    return $hasil;
}

/** Ringkas: jumlah berhasil, ditolak wajar (DomainException), dan error tak terduga (500). */
function ringkas(array $hasil): array
{
    $ok = array_filter($hasil, fn ($h) => $h['ok']);
    $domain = array_filter($hasil, fn ($h) => ! $h['ok'] && $h['jenis'] === 'domain');
    $lain = array_filter($hasil, fn ($h) => ! $h['ok'] && $h['jenis'] !== 'domain');

    return [
        'ok' => count($ok),
        'domain' => count($domain),
        'pesan_domain' => array_column($domain, 'pesan'),
        'lain' => array_column($lain, 'pesan'),
    ];
}

function pekerja(string $aksi, array $arg, int $jumlah): array
{
    return array_fill(0, $jumlah, [$aksi, $arg]);
}

// ------------------------------------------------------------------ Pembayaran

it('verifikasi bersamaan pada pembayaran yang sama: hanya satu yang berhasil', function () {
    $t = buatTagihan(null, '3000000');
    $p = catatBayar($t, '3000000');

    $r = ringkas(jalankanParalel(pekerja('verifikasi', ['id' => $p->id, 'user_id' => $this->admin->id], 5)));

    expect($r['lain'])->toBeEmpty()
        ->and($r['ok'])->toBe(1)
        ->and($r['domain'])->toBe(4);

    expect(PenerimaanDana::where('pembayaran_id', $p->id)->count())->toBe(1)
        ->and(Jurnal::where('sumber_type', Pembayaran::class)->where('sumber_id', $p->id)->count())->toBe(1);

    $t->refresh();
    expect($t->terbayar)->toBe('3000000.00')->and($t->status)->toBe('lunas');
    expect(Pembayaran::find($p->id)->status)->toBe('terverifikasi');
});

it('dua cicilan berbeda pada satu tagihan diverifikasi bersamaan: keduanya masuk, total benar', function () {
    $t = buatTagihan(null, '3000000');
    $p1 = catatBayar($t, '1000000');
    $p2 = catatBayar($t, '2000000');

    $r = ringkas(jalankanParalel([
        ['verifikasi', ['id' => $p1->id, 'user_id' => $this->admin->id]],
        ['verifikasi', ['id' => $p2->id, 'user_id' => $this->admin->id]],
    ]));

    expect($r['lain'])->toBeEmpty()->and($r['ok'])->toBe(2);

    $t->refresh();
    expect($t->terbayar)->toBe('3000000.00')
        ->and($t->status)->toBe('lunas')
        ->and(PenerimaanDana::count())->toBe(2)
        ->and(Jurnal::count())->toBe(2);
});

it('pencatatan pembayaran bersamaan tidak boleh melebihi sisa tagihan', function () {
    $t = buatTagihan(null, '3000000');

    $r = ringkas(jalankanParalel(pekerja('catat', ['tagihan_id' => $t->id, 'nominal' => '2000000', 'user_id' => $this->admin->id], 4)));

    expect($r['lain'])->toBeEmpty()
        ->and($r['ok'])->toBe(1)
        ->and($r['domain'])->toBe(3);

    expect((float) Pembayaran::where('tagihan_id', $t->id)->sum('nominal'))->toBe(2000000.0);
});

it('pembatalan pembayaran bersamaan: hanya satu jurnal pembalik dan terbayar kembali nol', function () {
    $t = buatTagihan(null, '3000000');
    $p = catatBayar($t, '3000000');
    app(PembayaranService::class)->verifikasi($p->id, $this->admin);

    $r = ringkas(jalankanParalel(pekerja('batalkan_pembayaran', ['id' => $p->id, 'user_id' => $this->admin->id], 4)));

    expect($r['lain'])->toBeEmpty()->and($r['ok'])->toBe(1)->and($r['domain'])->toBe(3);

    expect(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(1)
        ->and($t->refresh()->terbayar)->toBe('0.00')
        ->and($t->status)->toBe('belum_bayar')
        ->and(PenerimaanDana::count())->toBe(0);
});

// ------------------------------------------------------------------ Pengeluaran

it('persetujuan pengeluaran bersamaan: hanya satu yang berhasil dan satu jurnal', function () {
    $p = ajukanPengeluaran(userDengan('Operator'), '2000000', '5130', buatAnggaran('K1.1', '10000000'));

    $r = ringkas(jalankanParalel(pekerja('setujui', ['id' => $p->id, 'user_id' => $this->admin->id], 4)));

    expect($r['lain'])->toBeEmpty()->and($r['ok'])->toBe(1)->and($r['domain'])->toBe(3);

    expect(Jurnal::where('sumber_type', Pengeluaran::class)->where('sumber_id', $p->id)->count())->toBe(1)
        ->and(Pengeluaran::find($p->id)->status)->toBe('disetujui');
});

it('pembatalan pengeluaran bersamaan: hanya satu jurnal pembalik', function () {
    $p = ajukanPengeluaran(userDengan('Operator'), '2000000', '5130', buatAnggaran('K1.1', '10000000'));
    app(PengeluaranService::class)->setujui($p->id, $this->admin);

    $r = ringkas(jalankanParalel(pekerja('batalkan_pengeluaran', ['id' => $p->id, 'user_id' => $this->admin->id], 4)));

    expect($r['lain'])->toBeEmpty()->and($r['ok'])->toBe(1)->and($r['domain'])->toBe(3);

    expect(Jurnal::where('jenis', Jurnal::PEMBALIK)->count())->toBe(1)
        ->and(Pengeluaran::find($p->id)->status)->toBe('dibatalkan');
});

// ------------------------------------------------------------------ Tagihan massal dan jurnal

it('penerbitan tagihan massal bersamaan untuk periode yang sama: tidak ada tagihan ganda atau tanpa nomor', function () {
    buatTarif('3000000');
    $ids = collect(range(1, 40))->map(fn () => buatMahasiswa()->id)->all();
    $param = paramMassal();

    $r = ringkas(jalankanParalel(pekerja('tagihan_massal', ['param' => $param, 'ids' => $ids, 'user_id' => $this->admin->id], 3)));

    expect($r['lain'])->toBeEmpty()->and($r['ok'])->toBe(1)->and($r['domain'])->toBe(2);

    expect(Tagihan::count())->toBe(40)
        ->and(Tagihan::whereNull('nomor')->count())->toBe(0)
        ->and(Tagihan::distinct()->count('nomor'))->toBe(40)
        ->and(Tagihan::distinct()->count('kunci_unik'))->toBe(40);
});

it('pembuatan jurnal bersamaan: nomor unik dan semuanya seimbang', function () {
    $arg = [
        'user_id' => $this->admin->id,
        'tahun_id' => tahunAktif()->id,
        'akun_debit' => akunId('1110'),
        'akun_kredit' => akunId('4120'),
        'nominal' => '100000',
    ];

    $r = ringkas(jalankanParalel(pekerja('jurnal', $arg, 6)));

    expect($r['lain'])->toBeEmpty()->and($r['ok'])->toBe(6);

    expect(Jurnal::count())->toBe(6)
        ->and(Jurnal::whereNull('nomor')->count())->toBe(0)
        ->and(Jurnal::distinct()->count('nomor'))->toBe(6);

    $total = DB::table('jurnal_detail')->selectRaw('SUM(debit) as d, SUM(kredit) as k')->first();
    expect((string) $total->d)->toBe((string) $total->k)->and((float) $total->d)->toBe(600000.0);
});
