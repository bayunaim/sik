<?php

use App\Livewire\Anggaran\Index as AnggaranIndex;
use App\Livewire\Jurnal\Index as JurnalIndex;
use App\Livewire\Laporan\Tampil as LaporanTampil;
use App\Livewire\Master\ChartOfAccount\Index as CoaIndex;
use App\Livewire\Master\KomponenBiaya\Index as KomponenIndex;
use App\Livewire\Master\Mahasiswa\Index as MahasiswaIndex;
use App\Livewire\Master\ProgramStudi\Index as ProdiIndex;
use App\Livewire\Master\TahunAnggaran\Index as TahunIndex;
use App\Livewire\Master\TarifBiaya\Index as TarifIndex;
use App\Livewire\Pembayaran\Index as PembayaranIndex;
use App\Livewire\Penerimaan\Index as PenerimaanIndex;
use App\Livewire\Pengeluaran\Index as PengeluaranIndex;
use App\Livewire\Tagihan\Index as TagihanIndex;
use App\Livewire\Tagihan\Massal as TagihanMassal;
use App\Livewire\User\Index as UserIndex;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

const SEMUA_PERAN = ['SuperAdmin', 'Admin Keuangan', 'Operator', 'Pimpinan', 'Mahasiswa'];

/** Status HTTP saat sebuah komponen Livewire dibuka (mount): 200 atau 403. */
function statusBuka(string $komponen, array $params = []): int
{
    try {
        $t = Livewire::test($komponen, $params);
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }

    try {
        $t->assertForbidden();

        return 403;
    } catch (Throwable) {
        return 200;
    }
}

// ------------------------------------------------------------------ Matriks akses halaman per peran

dataset('halaman', [
    'anggaran' => [AnggaranIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator', 'Pimpinan']],
    'tagihan' => [TagihanIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator', 'Pimpinan']],
    'tagihan massal' => [TagihanMassal::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator']],
    'pembayaran' => [PembayaranIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator', 'Pimpinan']],
    'penerimaan' => [PenerimaanIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator', 'Pimpinan']],
    'pengeluaran' => [PengeluaranIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator', 'Pimpinan']],
    'jurnal' => [JurnalIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Pimpinan']],
    'laporan jurnal' => [LaporanTampil::class, ['jenis' => 'jurnal'], ['SuperAdmin', 'Admin Keuangan', 'Pimpinan']],
    'laporan tunggakan' => [LaporanTampil::class, ['jenis' => 'tunggakan'], ['SuperAdmin', 'Admin Keuangan', 'Pimpinan']],
    'laporan capaian' => [LaporanTampil::class, ['jenis' => 'capaian'], ['SuperAdmin', 'Admin Keuangan', 'Pimpinan']],
    'laporan status keuangan' => [LaporanTampil::class, ['jenis' => 'status-keuangan'], ['SuperAdmin', 'Admin Keuangan', 'Pimpinan']],
    'laporan laba-rugi' => [LaporanTampil::class, ['jenis' => 'laba-rugi'], ['SuperAdmin', 'Admin Keuangan', 'Pimpinan']],
    'laporan arus-kas' => [LaporanTampil::class, ['jenis' => 'arus-kas'], ['SuperAdmin', 'Admin Keuangan', 'Pimpinan']],
    'laporan buku-besar' => [LaporanTampil::class, ['jenis' => 'buku-besar'], ['SuperAdmin', 'Admin Keuangan', 'Pimpinan']],
    'laporan neraca-saldo' => [LaporanTampil::class, ['jenis' => 'neraca-saldo'], ['SuperAdmin', 'Admin Keuangan', 'Pimpinan']],
    'master prodi' => [ProdiIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator']],
    'master tahun anggaran' => [TahunIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator']],
    'master chart of accounts' => [CoaIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator']],
    'master komponen biaya' => [KomponenIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator']],
    'master tarif biaya' => [TarifIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator']],
    'master mahasiswa' => [MahasiswaIndex::class, [], ['SuperAdmin', 'Admin Keuangan', 'Operator']],
    'pengguna' => [UserIndex::class, [], ['SuperAdmin']],
]);

it('halaman hanya terbuka untuk peran yang berhak', function (string $komponen, array $params, array $boleh) {
    $hasil = [];
    foreach (SEMUA_PERAN as $peran) {
        $this->actingAs(userDengan($peran));
        $hasil[$peran] = statusBuka($komponen, $params);
    }

    $harapan = collect(SEMUA_PERAN)->mapWithKeys(fn ($p) => [$p => in_array($p, $boleh, true) ? 200 : 403])->all();

    expect($hasil)->toBe($harapan);
})->with('halaman');

// ------------------------------------------------------------------ Aksi sensitif per peran

it('operator tidak bisa membuka form yang bukan haknya', function () {
    $this->actingAs(userDengan('Operator'));

    Livewire::test(PenerimaanIndex::class)->call('tambah')->assertForbidden();
});

it('pimpinan hanya melihat dan menyetujui: tidak bisa mencatat apa pun', function () {
    $this->actingAs(userDengan('Pimpinan'));

    Livewire::test(PembayaranIndex::class)->call('tambah')->assertForbidden();
    Livewire::test(PengeluaranIndex::class)->call('tambah')->assertForbidden();
    Livewire::test(PenerimaanIndex::class)->call('tambah')->assertForbidden();
    Livewire::test(TagihanIndex::class)->call('konfirmasiBatal', 1)->assertForbidden();
});

it('pimpinan tidak bisa membatalkan pembayaran karena tidak punya hak verifikasi', function () {
    $p = catatBayar(buatTagihan(null, '3000000'), '3000000');
    app(\App\Services\PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));
    $this->actingAs(userDengan('Pimpinan'));

    Livewire::test(PembayaranIndex::class)->call('konfirmasiBatal', $p->id)->assertForbidden();
});

it('operator tidak bisa memverifikasi atau menolak pembayaran', function () {
    $p = catatBayar(buatTagihan(null, '3000000'), '1000000');
    $this->actingAs(userDengan('Operator'));

    Livewire::test(PembayaranIndex::class)->call('verifikasi', $p->id)->assertForbidden();
    Livewire::test(PembayaranIndex::class)->call('konfirmasiTolak', $p->id)->assertForbidden();

    expect($p->refresh()->status)->toBe('pending');
});

// ------------------------------------------------------------------ Isi peran di seeder (spesifikasi)

it('pimpinan hanya punya hak lihat, laporan, dan approval pengeluaran', function () {
    $hak = Role::findByName('Pimpinan', 'web')->permissions->pluck('name');

    $terlarang = $hak->filter(fn ($n) => preg_match('/\.(input|buat|ajukan|verifikasi|kelola|batalkan|ubah-nominal|penyesuaian|tutup)$/', $n));

    expect($terlarang)->toBeEmpty()
        ->and($hak->contains('pengeluaran.approve'))->toBeTrue();
});

it('operator tidak punya hak yang menyangkut uang keluar, koreksi, atau pengaturan', function () {
    $hak = Role::findByName('Operator', 'web')->permissions->pluck('name');

    foreach (['pembayaran.verifikasi', 'pengeluaran.approve', 'anggaran.approve', 'tagihan.ubah-nominal',
        'tagihan.batalkan', 'penerimaan.input', 'jurnal.lihat', 'jurnal.penyesuaian', 'master.kelola',
        'user.kelola', 'tahun.tutup', 'laporan.ekspor'] as $p) {
        expect($hak->contains($p))->toBeFalse("Operator tidak boleh punya {$p}");
    }
});

it('admin keuangan tidak punya hak pengguna dan tutup tahun', function () {
    $hak = Role::findByName('Admin Keuangan', 'web')->permissions->pluck('name');

    expect($hak->contains('user.kelola'))->toBeFalse()
        ->and($hak->contains('tahun.tutup'))->toBeFalse()
        ->and($hak->contains('pembayaran.verifikasi'))->toBeTrue();
});

it('mahasiswa hanya punya hak portal', function () {
    $hak = Role::findByName('Mahasiswa', 'web')->permissions->pluck('name');

    expect($hak->reject(fn ($n) => str_starts_with($n, 'portal.')))->toBeEmpty();
});

it('SuperAdmin lolos semua hak lewat Gate', function () {
    $su = userDengan('SuperAdmin');

    expect($su->can('user.kelola'))->toBeTrue()
        ->and($su->can('tahun.tutup'))->toBeTrue()
        ->and($su->can('hak.yang.tidak.ada'))->toBeTrue();
});

// ------------------------------------------------------------------ Route biasa dan middleware

it('tamu diarahkan ke halaman login', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->get('/pembayaran')->assertRedirect(route('login'));
});

it('akun nonaktif langsung dikeluarkan dan diarahkan ke login', function () {
    $user = userDengan('Admin Keuangan', ['is_active' => false]);

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));

    $this->assertGuest();
});

it('bukti bayar tidak bisa dibuka tanpa hak lihat pembayaran', function () {
    $p = catatBayar(buatTagihan(null, '3000000'), '1000000');

    $this->actingAs(userDengan('Mahasiswa'))->get(route('pembayaran.bukti', $p->id))->assertForbidden();
    $this->actingAs(userDengan('Operator'))->get(route('pembayaran.bukti', $p->id))->assertNotFound();   // tidak ada berkas
});

it('kwitansi tidak bisa dibuka tanpa hak, dan hanya untuk pembayaran terverifikasi', function () {
    $p = catatBayar(buatTagihan(null, '3000000'), '1000000');

    $this->actingAs(userDengan('Mahasiswa'))->get(route('pembayaran.kwitansi', $p->id))->assertForbidden();
    $this->actingAs(userDengan('Operator'))->get(route('pembayaran.kwitansi', $p->id))->assertNotFound();
});

it('kwitansi pembayaran terverifikasi menghasilkan PDF', function () {
    $p = catatBayar(buatTagihan(null, '3000000'), '3000000');
    app(\App\Services\PembayaranService::class)->verifikasi($p->id, userDengan('Admin Keuangan'));

    $r = $this->actingAs(userDengan('Operator'))->get(route('pembayaran.kwitansi', $p->id));

    $r->assertOk();
    expect($r->headers->get('content-type'))->toContain('application/pdf');
});

it('ekspor laporan hanya untuk yang punya hak ekspor', function () {
    $this->actingAs(userDengan('Operator'))->get(route('laporan.ekspor', ['jurnal', 'xlsx']))->assertForbidden();
    $this->actingAs(userDengan('Mahasiswa'))->get(route('laporan.ekspor', ['jurnal', 'pdf']))->assertForbidden();
});

it('ekspor laporan ke Excel dan PDF berhasil untuk admin keuangan dan tercatat di audit log', function () {
    $this->actingAs(userDengan('Admin Keuangan'));

    $this->get(route('laporan.ekspor', ['jurnal', 'xlsx']))->assertOk();
    $this->get(route('laporan.ekspor', ['tunggakan', 'pdf']))->assertOk();

    expect(\Spatie\Activitylog\Models\Activity::where('log_name', 'laporan')->count())->toBe(2);
});
