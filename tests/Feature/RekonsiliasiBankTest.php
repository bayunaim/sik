<?php

use App\Models\MutasiBank;
use App\Models\MutasiImport;
use App\Models\Pembayaran;
use App\Models\PenerimaanDana;
use App\Models\Pengeluaran;
use App\Models\RekeningBank;
use App\Models\User;
use App\Services\ImportMutasiBankService;
use App\Services\PembayaranService;
use App\Services\PenerimaanService;
use App\Services\PengeluaranService;
use App\Services\RekonsiliasiService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->svc = app(RekonsiliasiService::class);
    $this->admin = userDengan('Admin Keuangan');
    $this->rek = rekeningTes();
});

/* ------------------------------------------------------------------ helper */

function rekeningTes(string $bank = 'BCA', string $nomor = '1234567890'): RekeningBank
{
    return RekeningBank::create(['nama_bank' => $bank, 'nomor' => $nomor, 'atas_nama' => 'Yayasan Tes', 'is_active' => true]);
}

function mutasiTes(RekeningBank $r, string $tgl, string $kredit = '0', string $debit = '0', string $ket = 'TRF', ?string $ref = null): MutasiBank
{
    return MutasiBank::create([
        'rekening_bank_id' => $r->id, 'tanggal' => $tgl, 'keterangan' => $ket, 'referensi' => $ref,
        'debit' => $debit, 'kredit' => $kredit, 'kunci' => sha1(uniqid('', true)),
    ]);
}

function bayarTransfer(string $nominal = '3000000', ?string $ref = null, string $tgl = '2026-03-01', string $metode = 'Transfer Bank'): Pembayaran
{
    $t = buatTagihan(null, $nominal);
    $svc = app(PembayaranService::class);
    $p = $svc->catat([
        'tagihan_id' => $t->id, 'tanggal_bayar' => $tgl, 'nominal' => $nominal,
        'metode' => $metode, 'referensi' => $ref,
    ], userDengan('Operator'));

    return $svc->verifikasi($p->id, userDengan('Admin Keuangan'));
}

function bacaTes(array $rows): array
{
    return [
        'header' => [0 => 'Tanggal', 1 => 'Keterangan', 2 => 'Debit', 3 => 'Kredit', 4 => 'Saldo'],
        'data' => array_map(fn ($r, $i) => ['baris' => $i + 2, 'sel' => $r], $rows, array_keys($rows)),
    ];
}

function petaTes(): array
{
    return ['tanggal' => 'Tanggal', 'keterangan' => 'Keterangan', 'debit' => 'Debit', 'kredit' => 'Kredit',
        'jumlah' => '', 'jenis' => '', 'saldo' => 'Saldo', 'referensi' => ''];
}

function simpanImportTes(RekeningBank $r, array $rows, ?User $oleh = null): MutasiImport
{
    $oleh ??= userDengan('Admin Keuangan');
    $log = MutasiImport::create(['rekening_bank_id' => $r->id, 'user_id' => $oleh->id, 'nama_file' => 'tes.csv', 'file_path' => 'x']);

    return app(ImportMutasiBankService::class)->simpan($log->id, bacaTes($rows), petaTes(), $oleh);
}

/* ------------------------------------------------------- pencocokan otomatis */

it('mencocokkan otomatis jika nominal dan tanggal sama dan kandidatnya hanya satu', function () {
    $p = bayarTransfer('3000000', null, '2026-03-01');
    $m = mutasiTes($this->rek, '2026-03-02', '3000000');

    $hasil = $this->svc->cocokOtomatis($this->admin);

    expect($hasil['cocok'])->toBe(1)->and($hasil['ambigu'])->toBe(0);

    $m->refresh();
    expect($m->status)->toBe('cocok')
        ->and($m->cocok_type)->toBe(Pembayaran::class)
        ->and($m->cocok_id)->toBe($p->id)
        ->and($m->cara_cocok)->toBe('otomatis')
        ->and($m->dicocokkan_oleh)->toBe($this->admin->id);
});

it('tidak asal cocok jika ada dua kandidat yang sama persis', function () {
    bayarTransfer('3000000');
    bayarTransfer('3000000');
    $m1 = mutasiTes($this->rek, '2026-03-01', '3000000', '0', 'TRF SPP');
    $m2 = mutasiTes($this->rek, '2026-03-01', '3000000', '0', 'TRF SPP');

    $hasil = $this->svc->cocokOtomatis($this->admin);

    expect($hasil['cocok'])->toBe(0)->and($hasil['ambigu'])->toBe(2);
    expect($m1->refresh()->status)->toBe('belum_cocok')->and($m2->refresh()->status)->toBe('belum_cocok');
});

it('berita transfer yang memuat referensi memilah kandidat yang kembar', function () {
    $p1 = bayarTransfer('3000000', 'INV-AAA1');
    $p2 = bayarTransfer('3000000', 'INV-BBB2');
    $m1 = mutasiTes($this->rek, '2026-03-01', '3000000', '0', 'TRF SPP INV-AAA1');
    $m2 = mutasiTes($this->rek, '2026-03-01', '3000000', '0', 'TRF SPP inv bbb2');

    $hasil = $this->svc->cocokOtomatis($this->admin);

    expect($hasil['cocok'])->toBe(2);
    expect($m1->refresh()->cocok_id)->toBe($p1->id)->and($m2->refresh()->cocok_id)->toBe($p2->id);
});

it('tanggal di luar jendela tidak dicocokkan otomatis', function () {
    bayarTransfer('3000000', null, '2026-03-01');
    $m = mutasiTes($this->rek, '2026-03-10', '3000000');

    $hasil = $this->svc->cocokOtomatis($this->admin);

    expect($hasil['cocok'])->toBe(0)->and($hasil['tanpa_kandidat'])->toBe(1);
    expect($m->refresh()->status)->toBe('belum_cocok');
});

it('pembayaran tunai tidak pernah menjadi kandidat', function () {
    bayarTransfer('3000000', null, '2026-03-01', 'Tunai');
    mutasiTes($this->rek, '2026-03-01', '3000000');

    expect($this->svc->cocokOtomatis($this->admin)['tanpa_kandidat'])->toBe(1);
});

it('uang keluar dicocokkan dengan pengeluaran disetujui', function () {
    $bendahara = userDengan('Admin Keuangan', ['batas_persetujuan' => '5000000']);
    $png = ajukanPengeluaran(userDengan('Operator'), '2000000');
    app(PengeluaranService::class)->setujui($png->id, $bendahara);
    $m = mutasiTes($this->rek, '2026-03-01', '0', '2000000', 'TRF KELUAR ATK');

    expect($this->svc->cocokOtomatis($this->admin)['cocok'])->toBe(1);
    expect($m->refresh()->cocok_type)->toBe(Pengeluaran::class)->and($m->cocok_id)->toBe($png->id);
});

it('pengeluaran yang masih Pending tidak bisa dicocokkan', function () {
    $png = ajukanPengeluaran(userDengan('Operator'), '2000000');
    $m = mutasiTes($this->rek, '2026-03-01', '0', '2000000');

    expect(fn () => $this->svc->cocokkan($m->id, Pengeluaran::class, $png->id, $this->admin))
        ->toThrow(DomainException::class);
});

it('penerimaan non-mahasiswa dicocokkan dengan uang masuk', function () {
    $a = buatAnggaran('D2.1');
    $pen = app(PenerimaanService::class)->catatManual([
        'sumber' => 'hibah', 'anggaran_id' => $a->id, 'tanggal' => '2026-03-01',
        'nominal' => '1000000', 'metode' => 'Transfer Bank', 'keterangan' => 'Hibah tes',
    ], $this->admin);
    $m = mutasiTes($this->rek, '2026-03-01', '1000000', '0', 'HIBAH');

    expect($this->svc->cocokOtomatis($this->admin)['cocok'])->toBe(1);
    expect($m->refresh()->cocok_type)->toBe(PenerimaanDana::class)->and($m->cocok_id)->toBe($pen->id);
});

/* ---------------------------------------------------------- pencocokan manual */

it('manual: nominal harus sama persis dan arah harus sesuai', function () {
    $p = bayarTransfer('3000000');
    $kurang = mutasiTes($this->rek, '2026-03-01', '2993500');
    $keluar = mutasiTes($this->rek, '2026-03-01', '0', '3000000');

    expect(fn () => $this->svc->cocokkan($kurang->id, Pembayaran::class, $p->id, $this->admin))
        ->toThrow(DomainException::class, 'tidak sama');
    expect(fn () => $this->svc->cocokkan($keluar->id, Pembayaran::class, $p->id, $this->admin))
        ->toThrow(DomainException::class, 'Uang keluar');
});

it('satu transaksi tidak bisa dicocokkan ke dua mutasi', function () {
    $p = bayarTransfer('3000000');
    $m1 = mutasiTes($this->rek, '2026-03-01', '3000000');
    $m2 = mutasiTes($this->rek, '2026-03-01', '3000000');

    $this->svc->cocokkan($m1->id, Pembayaran::class, $p->id, $this->admin);

    expect(fn () => $this->svc->cocokkan($m2->id, Pembayaran::class, $p->id, $this->admin))
        ->toThrow(DomainException::class, 'dicocokkan');
    expect($m1->refresh()->cara_cocok)->toBe('manual')->and($m2->refresh()->status)->toBe('belum_cocok');
});

it('hanya tipe transaksi yang dikenal yang boleh dicocokkan', function () {
    $m = mutasiTes($this->rek, '2026-03-01', '3000000');

    expect(fn () => $this->svc->cocokkan($m->id, User::class, 1, $this->admin))
        ->toThrow(DomainException::class, 'Uang masuk hanya');
});

it('kandidat manual hanya memuat transaksi dengan nominal sama dan bisa dicari', function () {
    $p = bayarTransfer('3000000');
    bayarTransfer('1500000');
    $m = mutasiTes($this->rek, '2026-03-20', '3000000');

    $k = $this->svc->kandidatManual($m);
    expect($k)->toHaveCount(1)->and($k->first()['id'])->toBe($p->id);
    expect($this->svc->kandidatManual($m, 'tidak-ada-nama-ini'))->toHaveCount(0);
});

it('lepas, abaikan, dan batalkan abaikan mengembalikan status dengan benar', function () {
    $p = bayarTransfer('3000000');
    $m = mutasiTes($this->rek, '2026-03-01', '3000000');
    $this->svc->cocokkan($m->id, Pembayaran::class, $p->id, $this->admin);

    $this->svc->lepas($m->id, $this->admin);
    $m->refresh();
    expect($m->status)->toBe('belum_cocok')->and($m->cocok_id)->toBeNull()->and($m->cara_cocok)->toBeNull();

    expect(fn () => $this->svc->abaikan($m->id, '  ', $this->admin))->toThrow(DomainException::class, 'Alasan');
    $this->svc->abaikan($m->id, 'Biaya admin bank', $this->admin);
    expect($m->refresh()->status)->toBe('diabaikan')->and($m->alasan_abaikan)->toBe('Biaya admin bank');
    expect(fn () => $this->svc->abaikan($m->id, 'Lagi', $this->admin))->toThrow(DomainException::class);

    $this->svc->batalAbaikan($m->id, $this->admin);
    expect($m->refresh()->status)->toBe('belum_cocok')->and($m->alasan_abaikan)->toBeNull();

    // transaksi yang sudah dilepas bisa dipakai lagi
    $m2 = mutasiTes($this->rek, '2026-03-01', '3000000');
    $this->svc->cocokkan($m2->id, Pembayaran::class, $p->id, $this->admin);
    expect($m2->refresh()->status)->toBe('cocok');
});

it('pembayaran yang dibatalkan setelah dicocokkan ditandai bermasalah sampai dilepas', function () {
    $p = bayarTransfer('3000000');
    $m = mutasiTes($this->rek, '2026-03-01', '3000000');
    $this->svc->cocokkan($m->id, Pembayaran::class, $p->id, $this->admin);

    expect($this->svc->ringkasan(null, null, null)['bermasalah'])->toBe(0);

    app(PembayaranService::class)->batalkan($p->id, 'Salah input', $this->admin);

    expect($this->svc->ringkasan(null, null, null)['bermasalah'])->toBe(1);
    expect(MutasiBank::bermasalah()->count())->toBe(1);
    expect($m->refresh()->cocokBermasalah())->toBeTrue();

    $this->svc->lepas($m->id, $this->admin);

    $r = $this->svc->ringkasan(null, null, null);
    expect($r['bermasalah'])->toBe(0)->and($r['belum_cocok']['n'])->toBe(1)->and($r['cocok']['n'])->toBe(0);
});

it('ringkasan dan transaksi sistem yang belum ada di bank', function () {
    $p = bayarTransfer('3000000');
    mutasiTes($this->rek, '2026-03-05', '0', '6500', 'BIAYA ADM');

    $sistem = $this->svc->sistemBelumDiBank(null, null);
    expect($sistem['masuk']['n'])->toBe(1)->and($sistem['masuk']['total'])->toBe('3000000.00')->and($sistem['keluar']['n'])->toBe(0);

    $m = mutasiTes($this->rek, '2026-03-01', '3000000');
    $this->svc->cocokkan($m->id, Pembayaran::class, $p->id, $this->admin);

    expect($this->svc->sistemBelumDiBank(null, null)['masuk']['n'])->toBe(0);

    $r = $this->svc->ringkasan($this->rek->id, null, null);
    expect($r['cocok']['n'])->toBe(1)->and($r['cocok']['kredit'])->toBe('3000000.00')
        ->and($r['belum_cocok']['n'])->toBe(1)->and($r['belum_cocok']['debit'])->toBe('6500.00');
});

it('operator tidak boleh melakukan rekonsiliasi', function () {
    $operator = userDengan('Operator');
    $m = mutasiTes($this->rek, '2026-03-01', '3000000');

    expect(fn () => $this->svc->cocokOtomatis($operator))->toThrow(DomainException::class, 'hak');
    expect(fn () => $this->svc->abaikan($m->id, 'Biaya admin', $operator))->toThrow(DomainException::class, 'hak');
});

/* ------------------------------------------------------------------- import */

it('membaca angka dari berbagai format', function () {
    $svc = app(ImportMutasiBankService::class);

    foreach ([
        ['3.000.000,00', '3000000.00'], ['3,000,000.00', '3000000.00'], ['3000000', '3000000.00'],
        ['Rp 1.250.000', '1250000.00'], ['1.250', '1250.00'], ['1250.5', '1250.50'],
        ['(500.000,00)', '-500000.00'], ['-1.000,50', '-1000.50'],
    ] as [$masuk, $keluar]) {
        expect((string) $svc->uang($masuk))->toBe($keluar);
    }

    expect($svc->uang(''))->toBeNull();
    expect((string) $svc->uang(1500000))->toBe('1500000.00');
    expect(fn () => $svc->uang('abc'))->toThrow(DomainException::class);
});

it('membaca tanggal dari berbagai format', function () {
    $svc = app(ImportMutasiBankService::class);

    foreach (['01/03/2026', '2026-03-01', '1 Mar 2026', '01-Mar-2026', '1/3/26', '20260301', 46082] as $v) {
        expect($svc->tanggal($v))->toBe('2026-03-01');
    }
    expect($svc->tanggal('31/02/2026'))->toBeNull()->and($svc->tanggal('abc'))->toBeNull()->and($svc->tanggal(''))->toBeNull();
});

it('mengimpor mutasi, melewati yang sudah ada saat file tumpang tindih, dan menandai baris rusak', function () {
    $baris = [
        ['01/03/2026', 'TRF SPP 202600001', '', '3.000.000,00', '10.000.000,00'],
        ['02/03/2026', 'BIAYA ADM', '6.500,00', '', '9.993.500,00'],
        ['xx', 'rusak', '', '1.000,00', ''],
    ];

    $a = simpanImportTes($this->rek, $baris);
    expect($a->status)->toBe('selesai')->and($a->jumlah_baru)->toBe(2)->and($a->jumlah_gagal)->toBe(1)
        ->and($a->tanggal_awal->toDateString())->toBe('2026-03-01')->and($a->tanggal_akhir->toDateString())->toBe('2026-03-02');
    expect(Storage::disk('local')->exists($a->error_path))->toBeTrue();

    $b = simpanImportTes($this->rek, [...$baris, ['03/03/2026', 'TRF LAIN', '', '500.000,00', '']]);
    expect($b->jumlah_baru)->toBe(1)->and($b->jumlah_duplikat)->toBe(2);
    expect(MutasiBank::count())->toBe(3);

    $biaya = MutasiBank::where('keterangan', 'BIAYA ADM')->first();
    expect($biaya->isMasuk())->toBeFalse()->and($biaya->debit)->toBe('6500.00')->and($biaya->saldo)->toBe('9993500.00');
});

it('baris kembar dalam satu file tetap dihitung, dan tidak dobel saat diimpor ulang', function () {
    $dua = [['01/03/2026', 'SETORAN', '', '100.000,00', ''], ['01/03/2026', 'SETORAN', '', '100.000,00', '']];

    expect(simpanImportTes($this->rek, $dua)->jumlah_baru)->toBe(2);

    $ulang = simpanImportTes($this->rek, $dua);
    expect($ulang->jumlah_baru)->toBe(0)->and($ulang->jumlah_duplikat)->toBe(2);

    // file lain memuat tiga baris kembar: hanya satu yang baru
    $tiga = [...$dua, ['01/03/2026', 'SETORAN', '', '100.000,00', '']];
    expect(simpanImportTes($this->rek, $tiga)->jumlah_baru)->toBe(1);
    expect(MutasiBank::count())->toBe(3);
});

it('rekening berbeda tidak saling menganggap duplikat', function () {
    $baris = [['01/03/2026', 'SETORAN', '', '100.000,00', '']];
    $lain = rekeningTes('Mandiri', '9876543210');

    expect(simpanImportTes($this->rek, $baris)->jumlah_baru)->toBe(1);
    expect(simpanImportTes($lain, $baris)->jumlah_baru)->toBe(1);
});

it('mendukung satu kolom jumlah dengan kolom jenis DB/CR', function () {
    $svc = app(ImportMutasiBankService::class);
    $baca = [
        'header' => [0 => 'Tgl', 1 => 'Uraian', 2 => 'Nominal', 3 => 'DB/CR'],
        'data' => [
            ['baris' => 2, 'sel' => ['01/03/2026', 'Masuk', '1.000.000,00', 'CR']],
            ['baris' => 3, 'sel' => ['01/03/2026', 'Keluar', '250.000,00', 'DB']],
            ['baris' => 4, 'sel' => ['01/03/2026', 'Tanpa jenis', '10.000,00', '']],
        ],
    ];
    $peta = $svc->tebakPemetaan($baca['header'], null);
    expect($peta['tanggal'])->toBe('Tgl')->and($peta['keterangan'])->toBe('Uraian')
        ->and($peta['jumlah'])->toBe('Nominal')->and($peta['jenis'])->toBe('DB/CR');

    $a = $svc->analisis($baca, $peta, $this->rek->id);

    expect($a['baru'])->toBe(2)->and($a['error'])->toHaveCount(1)
        ->and($a['total_kredit'])->toBe('1000000.00')->and($a['total_debit'])->toBe('250000.00');
});

it('menolak pemetaan tanpa kolom nominal', function () {
    $svc = app(ImportMutasiBankService::class);
    $peta = array_merge(petaTes(), ['debit' => '', 'kredit' => '']);

    expect(fn () => $svc->analisis(bacaTes([]), $peta, $this->rek->id))->toThrow(DomainException::class, 'Debit');
});

it('membaca file CSV yang diawali baris info rekening', function () {
    Storage::disk('local')->put('tes/mutasi.csv',
        "Info Rekening,1234567890\nPeriode,01/03/2026 - 31/03/2026\nTanggal,Keterangan,Debit,Kredit,Saldo\n"
        ."01/03/2026,TRF SPP,,\"3.000.000,00\",\"10.000.000,00\"\n");

    $baca = app(ImportMutasiBankService::class)->baca('tes/mutasi.csv');
    Storage::disk('local')->delete('tes/mutasi.csv');

    expect($baca['header'])->toBe([0 => 'Tanggal', 1 => 'Keterangan', 2 => 'Debit', 3 => 'Kredit', 4 => 'Saldo'])
        ->and($baca['data'])->toHaveCount(1);
});

it('membatalkan import: mutasi dihapus lalu file yang sama bisa diimpor ulang', function () {
    $baris = [['01/03/2026', 'SETORAN A', '', '100.000,00', ''], ['02/03/2026', 'SETORAN B', '', '200.000,00', '']];
    $imp = simpanImportTes($this->rek, $baris);
    $svc = app(ImportMutasiBankService::class);

    expect(fn () => $svc->batalkan($imp->id, '  ', $this->admin))->toThrow(DomainException::class, 'Alasan');

    $svc->batalkan($imp->id, 'Salah pilih rekening', $this->admin);

    expect($imp->refresh()->status)->toBe('dibatalkan');
    expect(MutasiBank::count())->toBe(0)->and(MutasiBank::withTrashed()->count())->toBe(2);

    expect(simpanImportTes($this->rek, $baris)->jumlah_baru)->toBe(2);
    expect(fn () => $svc->batalkan($imp->id, 'Lagi', $this->admin))->toThrow(DomainException::class, 'sudah selesai');
});

it('import yang mutasinya sudah dicocokkan tidak bisa dibatalkan', function () {
    $p = bayarTransfer('100000');
    $imp = simpanImportTes($this->rek, [['01/03/2026', 'SETORAN A', '', '100.000,00', '']]);
    $m = MutasiBank::firstOrFail();
    $this->svc->cocokkan($m->id, Pembayaran::class, $p->id, $this->admin);

    expect(fn () => app(ImportMutasiBankService::class)->batalkan($imp->id, 'Salah rekening', $this->admin))
        ->toThrow(DomainException::class, 'dicocokkan');
    expect(MutasiBank::count())->toBe(1);
});

it('operator tidak boleh mengimpor atau membatalkan import', function () {
    $operator = userDengan('Operator');
    $imp = simpanImportTes($this->rek, [['01/03/2026', 'SETORAN A', '', '100.000,00', '']]);
    $log = MutasiImport::create(['rekening_bank_id' => $this->rek->id, 'nama_file' => 'x.csv', 'file_path' => 'x']);
    $svc = app(ImportMutasiBankService::class);

    expect(fn () => $svc->simpan($log->id, bacaTes([]), petaTes(), $operator))->toThrow(DomainException::class, 'hak');
    expect(fn () => $svc->batalkan($imp->id, 'Alasan apa saja', $operator))->toThrow(DomainException::class, 'hak');
});
