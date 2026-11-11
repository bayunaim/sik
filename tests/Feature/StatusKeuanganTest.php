<?php

use App\Models\Mahasiswa;
use App\Models\Tagihan;
use App\Services\LaporanService;
use App\Services\PembayaranService;
use App\Services\StatusKeuanganService;
use App\Services\TagihanService;

function statusKeu(array $filter = []): array
{
    $svc = app(LaporanService::class);

    return $svc->susun('status-keuangan', $svc->normalisasi('status-keuangan', $filter));
}

function lunasi(Tagihan $t): void
{
    $p = catatBayar($t, (string) $t->nominal);
    app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));
}

function barisNim(array $lap, string $nim): array
{
    return $lap['baris']->firstWhere('nim', $nim);
}

// ------------------------------------------------------------------ StatusKeuanganService / surat lunas

it('mahasiswa tanpa tagihan tidak bisa mendapat surat lunas', function () {
    $m = buatMahasiswa();

    expect(app(StatusKeuanganService::class)->alasanTidakBisaSuratLunas($m))->toContain('Belum ada tagihan');
});

it('surat lunas ditolak selama ada tagihan belum lunas, termasuk yang masih pending', function () {
    $m = buatMahasiswa();
    $t = buatTagihan($m, '3000000');
    $svc = app(StatusKeuanganService::class);

    expect($svc->alasanTidakBisaSuratLunas($m))->toContain('1 tagihan');

    catatBayar($t, '3000000');   // pending, belum diverifikasi
    expect($svc->alasanTidakBisaSuratLunas($m))->not->toBeNull();
});

it('surat lunas bisa diterbitkan setelah semua tagihan lunas dan tagihan dibatalkan tidak dihitung', function () {
    $m = buatMahasiswa();
    $svc = app(StatusKeuanganService::class);

    $t1 = buatTagihan($m, '3000000');
    $t2 = buatTagihan($m, '1000000', null, null, ['semester' => 'Genap']);
    app(TagihanService::class)->batalkan($t2, 'salah input', userDengan('Admin Keuangan'));

    lunasi($t1);

    expect($svc->alasanTidakBisaSuratLunas($m))->toBeNull()
        ->and($svc->ringkas($m)['tunggakan'])->toBe('0.00')
        ->and($svc->ringkas($m)['jumlah'])->toBe(1);
});

it('cicilan sebagian belum membuat mahasiswa lunas', function () {
    $m = buatMahasiswa();
    $t = buatTagihan($m, '3000000');
    $p = catatBayar($t, '1000000');
    app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));

    $r = app(StatusKeuanganService::class)->ringkas($m);

    expect($r['lunas'])->toBeFalse()
        ->and($r['tunggakan'])->toBe('2000000.00')
        ->and($r['terbayar'])->toBe('1000000.00');
});

// ------------------------------------------------------------------ laporan status keuangan (FR-150)

it('laporan status keuangan menghitung total, terbayar, tunggakan dan status per mahasiswa', function () {
    $lunas = buatMahasiswa();
    $sebagian = buatMahasiswa();
    $belum = buatMahasiswa();
    $tanpa = buatMahasiswa();

    lunasi(buatTagihan($lunas, '3000000'));
    $t = buatTagihan($sebagian, '3000000');
    $p = catatBayar($t, '1000000');
    app(PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));
    buatTagihan($belum, '3000000');

    $lap = statusKeu();

    expect(barisNim($lap, $lunas->nim))->toMatchArray(['total' => '3000000.00', 'terbayar' => '3000000.00', 'tunggakan' => '0.00', 'status_lunas' => 'Lunas'])
        ->and(barisNim($lap, $sebagian->nim))->toMatchArray(['terbayar' => '1000000.00', 'tunggakan' => '2000000.00', 'status_lunas' => 'Belum lunas'])
        ->and(barisNim($lap, $belum->nim))->toMatchArray(['terbayar' => '0.00', 'tunggakan' => '3000000.00', 'status_lunas' => 'Belum lunas'])
        ->and(barisNim($lap, $tanpa->nim))->toMatchArray(['total' => '0.00', 'status_lunas' => 'Tidak ada tagihan'])
        ->and($lap['total'][0]['nilai']['total'])->toBe('9000000.00')
        ->and($lap['total'][0]['nilai']['tunggakan'])->toBe('5000000.00');
});

it('status lunas dihitung per semester berjalan, tunggakan lama tetap tampil di kolom semua periode', function () {
    $m = buatMahasiswa();
    buatTagihan($m, '2000000', null, null, [
        'semester' => 'Genap', 'tahun_ajaran' => '2025/2026', 'tanggal_tagihan' => '2026-02-01', 'jatuh_tempo' => '2026-02-28',
    ]);
    lunasi(buatTagihan($m, '3000000'));   // Ganjil 2026/2027, tagihan terbaru

    $baris = barisNim(statusKeu(), $m->nim);

    expect($baris['status_lunas'])->toBe('Lunas')
        ->and($baris['total'])->toBe('3000000.00')
        ->and($baris['tunggakan_semua'])->toBe('2000000.00');

    $lama = barisNim(statusKeu(['semester' => 'Genap', 'tahun_ajaran' => '2025/2026']), $m->nim);
    expect($lama['status_lunas'])->toBe('Belum lunas')->and($lama['tunggakan'])->toBe('2000000.00');
});

it('laporan status keuangan bisa difilter per prodi dan angkatan dan mengabaikan tagihan dibatalkan', function () {
    $a = buatMahasiswa(['angkatan' => 2024]);
    $b = buatMahasiswa(['angkatan' => 2025]);
    $t = buatTagihan($a, '3000000');
    buatTagihan($b, '1000000');
    app(TagihanService::class)->batalkan($t, 'salah', userDengan('Admin Keuangan'));

    $lap = statusKeu(['angkatan' => '2024']);

    expect($lap['baris'])->toHaveCount(1)
        ->and($lap['baris'][0]['nim'])->toBe($a->nim)
        ->and($lap['baris'][0]['total'])->toBe('0.00');
});

it('laporan status keuangan tidak error saat belum ada tagihan sama sekali', function () {
    buatMahasiswa();

    $lap = statusKeu();

    expect($lap['baris'])->toHaveCount(1)->and($lap['baris'][0]['status_lunas'])->toBe('Tidak ada tagihan');
});

// ------------------------------------------------------------------ ekspor

it('CSV status keuangan: header di baris pertama, tanpa judul dan total, nilai uang mentah', function () {
    $m = buatMahasiswa();
    buatTagihan($m, '3000000');

    $rows = (new \App\Exports\LaporanCsv(statusKeu()))->array();

    expect($rows)->toHaveCount(2)
        ->and($rows[0][0])->toBe('NIM')
        ->and($rows[0])->toContain('Tunggakan (Rp)')
        ->and($rows[1][0])->toBe($m->nim)
        ->and($rows[1])->toContain('3000000.00');
});

it('CSV menetralkan sel teks yang diawali karakter rumus', function () {
    $lap = ['kolom' => [['key' => 'nama', 'label' => 'Nama', 'tipe' => 'teks']], 'baris' => collect([['nama' => '=HYPERLINK("x")']])];

    expect((new \App\Exports\LaporanCsv($lap))->array()[1][0])->toBe("'=HYPERLINK(\"x\")");
});

it('ekspor status keuangan ke CSV berhasil untuk admin keuangan', function () {
    buatTagihan(buatMahasiswa(), '3000000');
    $this->actingAs(userDengan('Admin Keuangan'))->get(route('laporan.ekspor', ['status-keuangan', 'csv']))->assertOk();
});

it('ekspor status keuangan ke Excel dan PDF berhasil', function () {
    buatTagihan(buatMahasiswa(), '3000000');
    $this->actingAs(userDengan('Admin Keuangan'));

    $this->get(route('laporan.ekspor', ['status-keuangan', 'xlsx']))->assertOk();
    expect($this->get(route('laporan.ekspor', ['status-keuangan', 'pdf']))->headers->get('content-type'))
        ->toContain('application/pdf');
});

it('ekspor CSV tetap hanya untuk yang berhak dan format lain tetap 404', function () {
    $this->actingAs(userDengan('Operator'))->get(route('laporan.ekspor', ['status-keuangan', 'csv']))->assertForbidden();
    $this->actingAs(userDengan('Admin Keuangan'))->get('/laporan/status-keuangan/ekspor/docx')->assertNotFound();
});

// ------------------------------------------------------------------ PDF tagihan dan surat lunas

it('staf dapat mencetak tagihan, tagihan dibatalkan tidak dapat', function () {
    $t = buatTagihan(buatMahasiswa(), '3000000');
    $this->actingAs(userDengan('Operator'));

    $r = $this->get(route('tagihan.cetak', $t->id));
    $r->assertOk();
    expect($r->headers->get('content-type'))->toContain('application/pdf');

    app(TagihanService::class)->batalkan($t, 'salah', userDengan('Admin Keuangan'));
    $this->get(route('tagihan.cetak', $t->id))->assertNotFound();
});

it('mahasiswa hanya dapat mencetak tagihan miliknya sendiri', function () {
    $user = userDengan('Mahasiswa');
    $saya = buatMahasiswa(['user_id' => $user->id]);
    $lain = buatMahasiswa();
    $tSaya = buatTagihan($saya, '3000000');
    $tLain = buatTagihan($lain, '3000000');

    $this->actingAs($user);

    $this->get(route('portal.tagihan', $tSaya->id))->assertOk();
    $this->get(route('portal.tagihan', $tLain->id))->assertNotFound();
});

it('portal surat lunas: ditolak saat masih ada tunggakan, berhasil setelah lunas, dan tercatat di audit log', function () {
    $user = userDengan('Mahasiswa');
    $m = buatMahasiswa(['user_id' => $user->id]);
    $t = buatTagihan($m, '3000000');
    $this->actingAs($user);

    $this->get(route('portal.surat-lunas'))->assertStatus(422);

    lunasi($t);

    $r = $this->get(route('portal.surat-lunas'));
    $r->assertOk();
    expect($r->headers->get('content-type'))->toContain('application/pdf')
        ->and(\Spatie\Activitylog\Models\Activity::where('description', 'like', 'cetak surat keterangan lunas%')->count())->toBe(1);
});

it('akun yang bukan mahasiswa tidak bisa membuka surat lunas portal, mahasiswa tanpa data mahasiswa 404', function () {
    $this->actingAs(userDengan('Admin Keuangan'))->get(route('portal.surat-lunas'))->assertForbidden();
    $this->actingAs(userDengan('Mahasiswa'))->get(route('portal.surat-lunas'))->assertNotFound();
});

it('staf yang berhak dapat mencetak surat lunas mahasiswa, mahasiswa lewat rute staf tidak', function () {
    $m = buatMahasiswa();
    lunasi(buatTagihan($m, '3000000'));

    $this->actingAs(userDengan('Admin Keuangan'))->get(route('mahasiswa.surat-lunas', $m->id))->assertOk();
    $this->actingAs(userDengan('Mahasiswa'))->get(route('mahasiswa.surat-lunas', $m->id))->assertForbidden();
});

it('tombol surat lunas di portal hanya muncul saat semua tagihan lunas', function () {
    $user = userDengan('Mahasiswa');
    $m = buatMahasiswa(['user_id' => $user->id]);
    $t = buatTagihan($m, '3000000');
    $this->actingAs($user);

    \Livewire\Livewire::test(\App\Livewire\Portal\Index::class)->assertDontSee('Surat Keterangan Lunas');

    lunasi($t);

    \Livewire\Livewire::test(\App\Livewire\Portal\Index::class)->assertSee('Unduh Surat');
});

it('halaman laporan status keuangan terbuka untuk admin keuangan dan pimpinan saja', function () {
    $this->actingAs(userDengan('Operator'))->get(route('laporan.status-keuangan'))->assertForbidden();
    $this->actingAs(userDengan('Pimpinan'))->get(route('laporan.status-keuangan'))->assertOk();
    $this->actingAs(userDengan('Admin Keuangan'))->get(route('laporan.status-keuangan'))->assertOk();
});
