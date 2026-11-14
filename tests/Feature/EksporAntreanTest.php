<?php

use App\Jobs\EksporLaporanJob;
use App\Services\JurnalService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/* Ekspor laporan besar lewat antrean: tautan unduh lewat notifikasi, hanya pemilik yang boleh membuka. */

function siapkanJurnalEkspor(): void
{
    app(JurnalService::class)->buat(
        ['tanggal' => '2026-03-05', 'tahun_anggaran_id' => tahunAktif()->id, 'keterangan' => 'Jurnal ekspor'],
        [
            ['akun_id' => akunId('1110'), 'debit' => '1000000', 'kredit' => 0],
            ['akun_id' => akunId('4120'), 'debit' => 0, 'kredit' => '1000000'],
        ],
        userDengan('Admin Keuangan')
    );
}

function urlEkspor(string $format): string
{
    return route('laporan.ekspor', ['jenis' => 'jurnal', 'format' => $format, 'dari' => '2026-03-01', 'sampai' => '2026-03-31']);
}

beforeEach(function () {
    Storage::fake('local');
    $this->admin = userDengan('Admin Keuangan');
    siapkanJurnalEkspor();
});

it('ekspor kecil tetap langsung diunduh tanpa antrean', function () {
    Bus::fake();

    $this->actingAs($this->admin)->get(urlEkspor('csv'))->assertOk();

    Bus::assertNotDispatched(EksporLaporanJob::class);
});

it('ekspor di atas batas dikirim ke antrean', function () {
    Bus::fake();
    config(['laporan.batas_antrean' => 0]);

    $this->actingAs($this->admin)->get(urlEkspor('xlsx'))
        ->assertRedirect()
        ->assertSessionHas('sukses');

    Bus::assertDispatched(EksporLaporanJob::class, fn ($j) => $j->userId === $this->admin->id
        && $j->jenis === 'jurnal' && $j->format === 'xlsx');
});

it('job membuat berkas, mengirim notifikasi, dan berkas bisa diunduh pemiliknya', function () {
    config(['laporan.batas_antrean' => 0]);

    $this->actingAs($this->admin)->get(urlEkspor('csv'))->assertRedirect();

    $berkas = Storage::disk('local')->allFiles('ekspor-laporan/'.$this->admin->id);
    expect($berkas)->toHaveCount(1);

    $nama = basename($berkas[0]);
    $notif = $this->admin->notifications()->first();
    expect($notif)->not->toBeNull()
        ->and($notif->data['jenis'])->toBe('ekspor')
        ->and($notif->data['url'])->toContain($nama);

    $this->actingAs($this->admin)->get(route('laporan.unduh', ['berkas' => $nama]))->assertOk();
});

it('pengguna lain tidak bisa mengunduh berkas ekspor milik orang lain', function () {
    config(['laporan.batas_antrean' => 0]);
    $this->actingAs($this->admin)->get(urlEkspor('csv'));
    $nama = basename(Storage::disk('local')->allFiles('ekspor-laporan/'.$this->admin->id)[0]);

    $lain = userDengan('Admin Keuangan');
    $this->actingAs($lain)->get(route('laporan.unduh', ['berkas' => $nama]))->assertNotFound();
});

it('nama berkas yang tidak sesuai pola ditolak', function () {
    $this->actingAs($this->admin)->get('/laporan/unduh/..%2F..%2F.env')->assertNotFound();
    $this->actingAs($this->admin)->get(route('laporan.unduh', ['berkas' => 'rahasia.csv']))->assertNotFound();
});

it('PDF yang melewati batas antrean juga diproses lewat antrean', function () {
    Bus::fake();
    config(['laporan.batas_antrean' => 0]);

    $this->actingAs($this->admin)->get(urlEkspor('pdf'))->assertRedirect();

    Bus::assertDispatched(EksporLaporanJob::class, fn ($j) => $j->format === 'pdf');
});
