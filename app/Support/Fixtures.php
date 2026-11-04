<?php

use App\Models\Anggaran;
use App\Models\ChartOfAccount;
use App\Models\KomponenBiaya;
use App\Models\Mahasiswa;
use App\Models\Pengeluaran;
use App\Models\ProgramStudi;
use App\Models\TahunAnggaran;
use App\Models\TarifBiaya;
use App\Models\User;
use App\Services\TagihanService;
use Spatie\Permission\Models\Permission;

/*
 * !! Nama kolom di file ini DITEBAK dari PRD dan service.
 * Kalau ada error "Unknown column" / "field doesn't have a default value",
 * betulkan di SINI saja; semua tes lain ikut benar.
 * (Kalau factory sudah ada, ganti ::create() dengan ::factory()->create().)
 */
function siapkanData(): object
{
    $d = new stdClass;

    $d->user = User::factory()->create();

    $d->admin = User::factory()->create();
    Permission::findOrCreate('tagihan.ubah-nominal');
    $d->admin->givePermissionTo('tagihan.ubah-nominal');

    $d->tahun = TahunAnggaran::create(['tahun' => 2026, 'status' => 'aktif']);

    $akun = fn (string $kode, string $nama, string $tipe) => ChartOfAccount::create([
        'kode' => $kode, 'nama' => $nama, 'tipe' => $tipe, 'is_active' => true,
    ]);
    $d->kas = $akun('1110', 'Kas', 'aset');
    $d->bank = $akun('1120', 'Bank', 'aset');
    $d->pendapatan = $akun('4110', 'Pendapatan SPP', 'pendapatan');
    $d->beban = $akun('5110', 'Beban Operasional', 'beban');

    $prodi = ProgramStudi::create(['kode' => 'TI', 'nama' => 'Teknik Informatika']);
    $d->prodi = $prodi;

    $d->mhs = buatMahasiswa($d, '2401001');

    $d->anggaran = Anggaran::create([
        'kode' => 'D1.1', 'nama' => 'SPP', 'tipe' => 'D', 'jumlah' => '1000000000.00',
        'tahun_anggaran_id' => $d->tahun->id, 'status' => 'Approved',
    ]);

    $d->komponen = KomponenBiaya::create([
        'nama' => 'SPP', 'kode' => 'SPP', 'is_active' => true,
        'anggaran_id' => $d->anggaran->id,
        'akun_pendapatan_id' => $d->pendapatan->id,
    ]);

    return $d;
}

function buatMahasiswa(object $d, string $nim): Mahasiswa
{
    return Mahasiswa::create([
        'nim' => $nim, 'nama' => "Mahasiswa {$nim}",
        'program_studi_id' => $d->prodi->id, 'angkatan' => 2024, 'status_akademik' => 'Aktif',
    ]);
}

function buatTarif(object $d, string $nominal = '5000000.00'): void
{
    TarifBiaya::create([
        'komponen_biaya_id' => $d->komponen->id,
        'program_studi_id' => null, 'angkatan' => null, 'nominal' => $nominal,
    ]);
}

function paramTagihan(object $d, array $o = []): array
{
    return array_merge([
        'mahasiswa_id' => $d->mhs->id,
        'komponen_biaya_id' => $d->komponen->id,
        'anggaran_id' => $d->anggaran->id,
        'semester' => 'Ganjil', 'tahun_ajaran' => '2026/2027',
        'tanggal_tagihan' => '2026-03-01', 'jatuh_tempo' => '2026-03-31',
        'metode_bayar' => 'Transfer', 'keterangan' => null,
    ], $o);
}

function tagihanSiap(object $d, string $nominal = '1000000.00')
{
    buatTarif($d, $nominal);

    return app(TagihanService::class)->buat(paramTagihan($d), $d->user);
}

function buatPengeluaran(object $d, string $nominal = '250000.00'): Pengeluaran
{
    return Pengeluaran::create([
        'nomor' => 'PGL/2026/000001', 'tanggal' => '2026-03-10',
        'tahun_anggaran_id' => $d->tahun->id, 'akun_beban_id' => $d->beban->id,
        'keperluan' => 'ATK', 'nominal' => $nominal, 'metode' => 'Transfer',
        'status' => 'Pending', 'diajukan_oleh' => $d->user->id,
    ]);
}
