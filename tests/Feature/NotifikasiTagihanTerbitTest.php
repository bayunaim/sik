<?php

use App\Jobs\KirimNotifikasiTagihanTerbit;
use App\Services\NotifikasiService;
use App\Services\PengeluaranService;
use App\Services\TagihanService;
use Illuminate\Support\Facades\Queue;

// FR-037: mahasiswa diberi tahu saat tagihan terbit.

it('tagihan satuan memberi tahu mahasiswa yang punya akun', function () {
    $akun = userDengan('Mahasiswa');
    $t = buatTagihan(buatMahasiswa(['user_id' => $akun->id]), '3000000');

    $n = $akun->notifications()->get();

    expect($n)->toHaveCount(1)
        ->and($n[0]->data['jenis'])->toBe('tagihan_terbit')
        ->and($n[0]->data['judul'])->toBe('Tagihan baru terbit')
        ->and($n[0]->data['pesan'])->toContain($t->nomor)->toContain('Rp 3.000.000')->toContain('31/08/2026')
        ->and($n[0]->data['url'])->toBe('/portal');
});

it('mahasiswa tanpa akun atau dengan akun nonaktif dilewati tanpa error', function () {
    $nonaktif = userDengan('Mahasiswa', ['is_active' => false]);

    buatTagihan(buatMahasiswa(), '3000000'); // belum punya akun login
    buatTagihan(buatMahasiswa(['user_id' => $nonaktif->id]), '3000000');

    expect($nonaktif->notifications()->count())->toBe(0);
});

it('mengirim dua kali tidak membuat notifikasi ganda', function () {
    $akun = userDengan('Mahasiswa');
    $t = buatTagihan(buatMahasiswa(['user_id' => $akun->id]), '3000000');
    $akun->notifications()->delete();

    $svc = app(NotifikasiService::class);

    expect($svc->tagihanTerbit([$t->id]))->toBe(1)
        ->and($svc->tagihanTerbit([$t->id]))->toBe(0)
        ->and($akun->notifications()->count())->toBe(1);
});

it('tagihan yang sudah dibatalkan tidak diberitahukan', function () {
    $akun = userDengan('Mahasiswa');
    $admin = userDengan('Admin Keuangan');
    $t = buatTagihan(buatMahasiswa(['user_id' => $akun->id]), '3000000');
    app(TagihanService::class)->batalkan($t, 'Salah input tagihan', $admin);
    $akun->notifications()->delete();

    expect(app(NotifikasiService::class)->tagihanTerbit([$t->id]))->toBe(0)
        ->and($akun->notifications()->count())->toBe(0);
});

it('tagihan massal mengantrekan notifikasi dan baru terkirim saat job dijalankan', function () {
    Queue::fake();
    buatTarif('3000000');
    $admin = userDengan('Admin Keuangan');
    $akunA = userDengan('Mahasiswa');
    $akunB = userDengan('Mahasiswa');
    $ids = [
        buatMahasiswa(['user_id' => $akunA->id])->id,
        buatMahasiswa(['user_id' => $akunB->id])->id,
        buatMahasiswa()->id, // tanpa akun login
    ];

    $hasil = app(TagihanService::class)->buatMassal(paramMassal(), $ids, $admin);

    expect($hasil['dibuat'])->toBe(3);
    Queue::assertPushed(KirimNotifikasiTagihanTerbit::class, fn ($j) => count($j->tagihanIds) === 3);
    expect($akunA->notifications()->count())->toBe(0); // belum: masih di antrean

    // worker mengambil job
    Queue::pushed(KirimNotifikasiTagihanTerbit::class)->each(
        fn ($j) => $j->handle(app(NotifikasiService::class))
    );

    expect($akunA->notifications()->count())->toBe(1)
        ->and($akunB->notifications()->count())->toBe(1);
});

it('gagal mengantrekan notifikasi tidak membatalkan tagihan massal yang sudah terbit', function () {
    buatTarif('3000000');
    $admin = userDengan('Admin Keuangan');
    $ids = [buatMahasiswa()->id];

    Queue::shouldReceive('connection')->andThrow(new RuntimeException('antrean mati'));

    $hasil = app(TagihanService::class)->buatMassal(paramMassal(), $ids, $admin);

    expect($hasil['dibuat'])->toBe(1);
});

it('kegagalan notifikasi tidak menggagalkan penerbitan tagihan satuan', function () {
    $this->app->bind(NotifikasiService::class, fn () => new class(app(PengeluaranService::class)) extends NotifikasiService {
        public function tagihanTerbit(array $tagihanIds): int
        {
            $this->aman(fn () => throw new RuntimeException('gagal kirim'));

            return 0;
        }
    });

    $t = buatTagihan(null, '3000000');

    expect($t->exists)->toBeTrue()->and($t->nomor)->not->toBeNull();
});
