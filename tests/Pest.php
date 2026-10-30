
<?php

use App\Models\Anggaran;
use App\Models\ChartOfAccount;
use App\Models\Jurnal;
use App\Models\KomponenBiaya;
use App\Models\Mahasiswa;
use App\Models\Pembayaran;
use App\Models\Pengeluaran;
use App\Models\ProgramStudi;
use App\Models\Tagihan;
use App\Models\TahunAnggaran;
use App\Models\TarifBiaya;
use App\Models\User;
use App\Services\JurnalService;
use App\Services\PembayaranService;
use App\Services\PengeluaranService;
use App\Services\TagihanService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\KomponenBiayaSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| DATABASE TESTING
|--------------------------------------------------------------------------
| Semua pengujian wajib menggunakan database sik_test.
| Jangan sampai migrate:fresh mengenai database utama.
*/

// Tetapkan environment sebelum aplikasi Laravel dijalankan.
putenv('DB_CONNECTION=mysql');
putenv('DB_DATABASE=sik_test');
putenv('DB_URL=');

$_ENV['DB_CONNECTION'] = 'mysql';
$_ENV['DB_DATABASE'] = 'sik_test';
$_ENV['DB_URL'] = '';

$_SERVER['DB_CONNECTION'] = 'mysql';
$_SERVER['DB_DATABASE'] = 'sik_test';
$_SERVER['DB_URL'] = '';

// Pengaman database.
if (
    getenv('DB_CONNECTION') !== 'mysql' ||
    getenv('DB_DATABASE') !== 'sik_test'
) {
    throw new RuntimeException(
        'Tes wajib menggunakan database MySQL sik_test.'
    );
}

/*
|--------------------------------------------------------------------------
| PEST CONFIGURATION
|--------------------------------------------------------------------------
*/

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {

        // Pengaman tambahan sebelum proses pengujian.
        if (config('database.default') !== 'mysql') {
            throw new RuntimeException(
                'Koneksi database testing harus MySQL.'
            );
        }

        if (
            config('database.connections.mysql.database')
            !== 'sik_test'
        ) {
            throw new RuntimeException(
                'Database testing harus sik_test.'
            );
        }

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(KomponenBiayaSeeder::class);
    })
    ->in('Feature');

/* ------------------------------------------------------------------
| HELPER DATA
|-------------------------------------------------------------------*/

function userDengan(string $role, array $attr = []): User
{
    $user = User::create(array_merge([
        'name' => $role.' Tes',
        'email' => Str::uuid().'@tes.test',
        'password' => 'rahasia123',
        'is_active' => true,
    ], $attr));

    $user->assignRole($role);

    return $user;
}

function tahunAktif(): TahunAnggaran
{
    return TahunAnggaran::firstOrCreate(
        ['nama' => '2026'],
        [
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status' => 'aktif',
        ]
    );
}

function akunId(string $kode): int
{
    return ChartOfAccount::where('kode', $kode)->value('id');
}

function buatAnggaran(
    string $kode = 'D1.1',
    string $jumlah = '100000000',
    string $status = 'approved'
): Anggaran {
    $tahun = tahunAktif();

    return Anggaran::firstOrCreate(
        [
            'tahun_anggaran_id' => $tahun->id,
            'kode' => $kode,
        ],
        [
            'tipe' => substr($kode, 0, 1),
            'nama' => "Anggaran {$kode}",
            'jumlah' => $jumlah,
            'status' => $status,
        ]
    );
}

function buatMahasiswa(array $o = []): Mahasiswa
{
    static $n = 0;
    $n++;

    $prodi = ProgramStudi::firstOrCreate(
        ['kode' => 'TI'],
        [
            'nama' => 'Teknik Informatika',
            'jenjang' => 'S1',
            'is_active' => true,
        ]
    );

    return Mahasiswa::create(array_merge([
        'nim' => '2026'.str_pad(
            (string) $n,
            5,
            '0',
            STR_PAD_LEFT
        ),
        'nama' => "Mahasiswa {$n}",
        'program_studi_id' => $prodi->id,
        'angkatan' => 2026,
        'status_akademik' => 'Aktif',
        'semester_aktif' => '1',
    ], $o));
}

function komponenSpp(): KomponenBiaya
{
    return KomponenBiaya::where('kode', 'SPP')->firstOrFail();
}

function buatTarif(string $nominal = '3000000'): TarifBiaya
{
    return TarifBiaya::create([
        'komponen_biaya_id' => komponenSpp()->id,
        'nominal' => $nominal,
    ]);
}

/**
 * Nominal '' = pakai tarif.
 * Nominal terisi = manual (hanya berlaku untuk yang berhak).
 */
function buatTagihan(
    ?Mahasiswa $m = null,
    string $nominal = '3000000',
    ?Anggaran $a = null,
    ?User $oleh = null,
    array $o = []
): Tagihan {
    $m ??= buatMahasiswa();
    $a ??= buatAnggaran();
    $oleh ??= userDengan('Admin Keuangan');

    return app(TagihanService::class)->buat(array_merge([
        'mahasiswa_id' => $m->id,
        'komponen_biaya_id' => komponenSpp()->id,
        'anggaran_id' => $a->id,
        'semester' => 'Ganjil',
        'tahun_ajaran' => '2026/2027',
        'nominal' => $nominal,
        'tanggal_tagihan' => '2026-08-01',
        'jatuh_tempo' => '2026-08-31',
    ], $o), $oleh);
}

function paramMassal(array $o = []): array
{
    return array_merge([
        'komponen_biaya_id' => komponenSpp()->id,
        'anggaran_id' => buatAnggaran()->id,
        'semester' => 'Ganjil',
        'tahun_ajaran' => '2026/2027',
        'nominal' => '',
        'tanggal_tagihan' => '2026-08-01',
        'jatuh_tempo' => '2026-08-31',
        'metode_bayar' => '',
        'keterangan' => '',
    ], $o);
}

function catatBayar(
    Tagihan $t,
    string $nominal,
    string $metode = 'Transfer Bank'
): Pembayaran {
    return app(PembayaranService::class)->catat([
        'tagihan_id' => $t->id,
        'tanggal_bayar' => '2026-03-01',
        'nominal' => $nominal,
        'metode' => $metode,
    ], userDengan('Operator'));
}

function jurnalContoh(string $nominal = '100000'): Jurnal
{
    return app(JurnalService::class)->buat(
        [
            'tanggal' => '2026-03-01',
            'tahun_anggaran_id' => tahunAktif()->id,
            'keterangan' => 'Jurnal contoh',
        ],
        [
            [
                'akun_id' => akunId('1110'),
                'debit' => $nominal,
                'kredit' => 0,
            ],
            [
                'akun_id' => akunId('4120'),
                'debit' => 0,
                'kredit' => $nominal,
            ],
        ],
        userDengan('Admin Keuangan')
    );
}

function ajukanPengeluaran(
    User $pengaju,
    string $nominal = '2000000',
    string $kodeAkun = '5130',
    ?Anggaran $a = null
): Pengeluaran {
    $a ??= buatAnggaran('K1.1', '10000000');

    return app(PengeluaranService::class)->ajukan([
        'tanggal' => '2026-03-01',
        'anggaran_id' => $a->id,
        'akun_beban_id' => akunId($kodeAkun),
        'keperluan' => 'Pembelian ATK',
        'nominal' => $nominal,
        'metode' => 'Transfer Bank',
    ], $pengaju);
}
