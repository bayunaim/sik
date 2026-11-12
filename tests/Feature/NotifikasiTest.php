<?php

use App\Livewire\Lonceng;
use App\Services\NotifikasiService;
use App\Services\PembayaranService;
use App\Services\PengeluaranService;
use Livewire\Livewire;

function jenisNotifikasi(App\Models\User $u, bool $belumDibaca = true): array
{
    $q = $belumDibaca ? $u->unreadNotifications() : $u->notifications();

    return $q->get()->map(fn ($n) => $n->data['jenis'])->all();
}

it('pembayaran baru memberi notifikasi ke yang berhak verifikasi, bukan ke operator', function () {
    $admin = userDengan('Admin Keuangan');
    $operator = userDengan('Operator');
    $pimpinan = userDengan('Pimpinan');

    catatBayar(buatTagihan(null, '3000000'), '1000000');

    expect(jenisNotifikasi($admin))->toBe(['pembayaran_menunggu'])
        ->and(jenisNotifikasi($operator))->toBe([])
        ->and(jenisNotifikasi($pimpinan))->toBe([]);
});

it('verifikasi menandai dibaca untuk staf dan memberi tahu mahasiswa', function () {
    $admin = userDengan('Admin Keuangan');
    $akunMhs = userDengan('Mahasiswa');
    $t = buatTagihan(buatMahasiswa(['user_id' => $akunMhs->id]), '3000000');

    $p = catatBayar($t, '1000000');
    expect(jenisNotifikasi($admin))->toBe(['pembayaran_menunggu']);

    app(PembayaranService::class)->verifikasi($p->id, $admin);

    expect(jenisNotifikasi($admin))->toBe([])
        ->and(jenisNotifikasi($akunMhs))->toBe(['pembayaran_hasil'])
        ->and($akunMhs->notifications()->first()->data['judul'])->toBe('Pembayaran diverifikasi');
});

it('penolakan memberi tahu mahasiswa beserta alasannya', function () {
    $admin = userDengan('Admin Keuangan');
    $akunMhs = userDengan('Mahasiswa');
    $t = buatTagihan(buatMahasiswa(['user_id' => $akunMhs->id]), '3000000');

    $p = catatBayar($t, '1000000');
    app(PembayaranService::class)->tolak($p->id, 'Bukti tidak terbaca', $admin);

    $n = $akunMhs->notifications()->first();
    expect($n->data['judul'])->toBe('Pembayaran ditolak')
        ->and($n->data['pesan'])->toContain('Bukti tidak terbaca');
});

it('pengeluaran hanya diberitahukan ke approver yang batasnya cukup dan bukan pengaju', function () {
    $operator = userDengan('Operator');
    $bendahara = userDengan('Admin Keuangan', ['batas_persetujuan' => '5000000']);
    $pimpinan = userDengan('Pimpinan', ['batas_persetujuan' => '50000000']);

    ajukanPengeluaran($operator, '10000000');

    expect(jenisNotifikasi($pimpinan))->toBe(['pengeluaran_menunggu'])
        ->and(jenisNotifikasi($bendahara))->toBe([])   // nominal melebihi batasnya
        ->and(jenisNotifikasi($operator))->toBe([]);
});

it('pengeluaran yang diproses ditandai dibaca dan pengaju diberi tahu', function () {
    $operator = userDengan('Operator');
    $bendahara = userDengan('Admin Keuangan', ['batas_persetujuan' => '5000000']);

    $p = ajukanPengeluaran($operator, '1000000');
    expect(jenisNotifikasi($bendahara))->toBe(['pengeluaran_menunggu']);

    app(PengeluaranService::class)->setujui($p->id, $bendahara);

    expect(jenisNotifikasi($bendahara))->toBe([])
        ->and(jenisNotifikasi($operator))->toBe(['pengeluaran_hasil'])
        ->and($operator->notifications()->first()->data['judul'])->toBe('Pengeluaran disetujui');
});

it('pengaju membatalkan pengajuan: notifikasi approver ditandai dibaca', function () {
    $operator = userDengan('Operator');
    $bendahara = userDengan('Admin Keuangan', ['batas_persetujuan' => '5000000']);

    $p = ajukanPengeluaran($operator, '1000000');
    app(PengeluaranService::class)->batalkan($p->id, $operator);

    expect(jenisNotifikasi($bendahara))->toBe([]);
});

it('kegagalan notifikasi tidak menggagalkan pembayaran', function () {
    // NotifikasiService diganti versi yang selalu error saat mengirim
    $this->app->bind(NotifikasiService::class, fn () => new class(app(PengeluaranService::class)) extends NotifikasiService {
        public function pembayaranMenunggu($p): void
        {
            $this->aman(fn () => throw new RuntimeException('gagal kirim'));
        }
    });

    $p = catatBayar(buatTagihan(null, '3000000'), '1000000');

    expect($p->exists)->toBeTrue()->and($p->status)->toBe('pending');
});

it('pengingat jatuh tempo dikirim sekali per tahap dan melewati tagihan lunas', function () {
    $akunA = userDengan('Mahasiswa');
    $akunB = userDengan('Mahasiswa');
    $admin = userDengan('Admin Keuangan');

    buatTagihan(buatMahasiswa(['user_id' => $akunA->id]), '3000000', null, null,
        ['jatuh_tempo' => today()->addDays(2)->toDateString()]);

    $lunas = buatTagihan(buatMahasiswa(['user_id' => $akunB->id]), '3000000', null, null,
        ['jatuh_tempo' => today()->addDays(2)->toDateString()]);
    $p = catatBayar($lunas, '3000000');
    app(PembayaranService::class)->verifikasi($p->id, $admin);
    $akunB->notifications()->delete();

    $this->artisan('notifikasi:jatuh-tempo')->assertSuccessful();
    $this->artisan('notifikasi:jatuh-tempo')->assertSuccessful(); // jalan kedua: tidak dobel

    expect($akunA->notifications()->count())->toBe(1)
        ->and($akunA->notifications()->first()->data['jenis'])->toBe('tagihan_jatuh_tempo')
        ->and($akunB->notifications()->count())->toBe(0);
});

it('tagihan yang sudah lewat jatuh tempo mendapat satu notifikasi tahap lewat', function () {
    $akun = userDengan('Mahasiswa');
    buatTagihan(buatMahasiswa(['user_id' => $akun->id]), '3000000', null, null,
        ['jatuh_tempo' => today()->subDays(5)->toDateString()]);

    expect(app(NotifikasiService::class)->kirimJatuhTempo())->toBe(1)
        ->and(app(NotifikasiService::class)->kirimJatuhTempo())->toBe(0)
        ->and($akun->notifications()->first()->data['judul'])->toBe('Tagihan melewati jatuh tempo');
});

it('lonceng menampilkan jumlah belum dibaca, membuka notifikasi, dan menandai dibaca', function () {
    $admin = userDengan('Admin Keuangan');
    catatBayar(buatTagihan(null, '3000000'), '1000000');
    $n = $admin->unreadNotifications()->first();

    $this->actingAs($admin);

    Livewire::test(Lonceng::class)
        ->assertSee('Pembayaran menunggu verifikasi')
        ->call('buka', $n->id)
        ->assertRedirect('/pembayaran');

    expect($admin->unreadNotifications()->count())->toBe(0);
});

it('lonceng tidak bisa membuka notifikasi milik orang lain', function () {
    $admin = userDengan('Admin Keuangan');
    $lain = userDengan('Admin Keuangan');
    catatBayar(buatTagihan(null, '3000000'), '1000000');
    $milikLain = $lain->unreadNotifications()->first();

    $this->actingAs($admin);

    Livewire::test(Lonceng::class)
        ->call('buka', $milikLain->id)
        ->assertNoRedirect();

    expect($lain->unreadNotifications()->count())->toBe(1);
});

it('tandai semua dibaca hanya memengaruhi notifikasi sendiri', function () {
    $admin = userDengan('Admin Keuangan');
    $lain = userDengan('Admin Keuangan');
    catatBayar(buatTagihan(null, '3000000'), '1000000');

    $this->actingAs($admin);
    Livewire::test(Lonceng::class)->call('tandaiSemua');

    expect($admin->unreadNotifications()->count())->toBe(0)
        ->and($lain->unreadNotifications()->count())->toBe(1);
});
