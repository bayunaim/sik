<?php

use App\Models\ChartOfAccount;
use App\Services\JurnalService;
use App\Services\LaporanAkuntansi;
use App\Services\LaporanService;

/** @param list<array{0:string,1:string|int,2:string|int}> $baris [kode akun, debit, kredit] */
function jurnalAkun(string $tanggal, array $baris, string $ket = 'Tes'): \App\Models\Jurnal
{
    return app(JurnalService::class)->buat(
        ['tanggal' => $tanggal, 'tahun_anggaran_id' => tahunAktif()->id, 'keterangan' => $ket],
        array_map(fn ($b) => ['akun_id' => akunId($b[0]), 'debit' => $b[1], 'kredit' => $b[2]], $baris),
        userDengan('Admin Keuangan')
    );
}

function lapAkuntansi(string $jenis, array $filter = []): array
{
    $svc = app(LaporanService::class);

    return $svc->susun($jenis, $svc->normalisasi($jenis, $filter));
}

function totalLap(array $lap, string $label, string $key = 'jumlah'): ?string
{
    return collect($lap['total'])->firstWhere('label', $label)['nilai'][$key] ?? null;
}

// ------------------------------------------------------------------ Laba Rugi

it('laba rugi: pendapatan dikurangi beban, hanya pada rentang tanggal terpilih', function () {
    jurnalAkun('2026-03-01', [['1120', 3000000, 0], ['4120', 0, 3000000]]);
    jurnalAkun('2026-03-05', [['5130', 2000000, 0], ['1120', 0, 2000000]]);
    jurnalAkun('2026-09-01', [['1120', 9000000, 0], ['4120', 0, 9000000]]);   // di luar rentang

    $lap = lapAkuntansi('laba-rugi', ['dari' => '2026-01-01', 'sampai' => '2026-06-30']);

    expect(totalLap($lap, 'TOTAL PENDAPATAN'))->toBe('3000000.00')
        ->and(totalLap($lap, 'TOTAL BEBAN'))->toBe('2000000.00')
        ->and(totalLap($lap, 'LABA BERSIH'))->toBe('1000000.00')
        ->and($lap['baris'])->toHaveCount(2)
        ->and($lap['baris'][0])->toMatchArray(['kelompok' => 'Pendapatan', 'kode' => '4120'])
        ->and($lap['baris'][1])->toMatchArray(['kelompok' => 'Beban', 'kode' => '5130']);
});

it('laba rugi menyebut RUGI BERSIH jika beban lebih besar dan nilainya negatif', function () {
    jurnalAkun('2026-03-01', [['1120', 1000000, 0], ['4120', 0, 1000000]]);
    jurnalAkun('2026-03-02', [['5130', 4000000, 0], ['1120', 0, 4000000]]);

    $lap = lapAkuntansi('laba-rugi', ['dari' => '2026-01-01', 'sampai' => '2026-12-31']);

    expect(totalLap($lap, 'RUGI BERSIH'))->toBe('-3000000.00')
        ->and(totalLap($lap, 'LABA BERSIH'))->toBeNull();
});

it('laba rugi ikut terkoreksi oleh jurnal pembalik', function () {
    $j = jurnalAkun('2026-03-01', [['1120', 3000000, 0], ['4120', 0, 3000000]]);
    app(JurnalService::class)->balik($j, 'salah input', userDengan('Admin Keuangan'));

    $lap = lapAkuntansi('laba-rugi', ['dari' => '2026-01-01', 'sampai' => '2026-12-31']);

    expect(totalLap($lap, 'TOTAL PENDAPATAN'))->toBe('0.00');
});

it('laba rugi kosong tidak error dan labanya nol', function () {
    $lap = lapAkuntansi('laba-rugi', ['dari' => '2026-01-01', 'sampai' => '2026-12-31']);

    expect($lap['baris'])->toHaveCount(0)->and(totalLap($lap, 'LABA BERSIH'))->toBe('0.00');
});

it('laba rugi dari pembayaran terverifikasi dan pengeluaran disetujui sama dengan jurnalnya', function () {
    $t = buatTagihan(buatMahasiswa(), '3000000');
    app(\App\Services\PembayaranService::class)->verifikasi(catatBayar($t, '3000000')->id, userDengan('Admin Keuangan'));

    $lap = lapAkuntansi('laba-rugi', ['dari' => '2026-01-01', 'sampai' => '2026-12-31']);

    expect(totalLap($lap, 'TOTAL PENDAPATAN'))->toBe('3000000.00');
});

// ------------------------------------------------------------------ Arus Kas

it('kelompok arus kas ditentukan dari akun lawan', function (string $tipe, string $kode, string $harapan) {
    expect(LaporanAkuntansi::kelompokKas($tipe, $kode))->toBe($harapan);
})->with([
    'pendapatan' => ['pendapatan', '4120', 'Aktivitas Operasional'],
    'beban' => ['beban', '5130', 'Aktivitas Operasional'],
    'piutang' => ['aset', '1200', 'Aktivitas Operasional'],
    'pendapatan diterima dimuka' => ['kewajiban', '2100', 'Aktivitas Operasional'],
    'aset lain' => ['aset', '1300', 'Aktivitas Investasi'],
    'ekuitas' => ['ekuitas', '3100', 'Aktivitas Pendanaan'],
    'kewajiban lain' => ['kewajiban', '2200', 'Aktivitas Pendanaan'],
]);

it('arus kas: masuk dan keluar per kelompok, saldo awal dan akhir cocok', function () {
    jurnalAkun('2026-01-15', [['1120', 10000000, 0], ['3100', 0, 10000000]]);   // sebelum periode: saldo awal
    jurnalAkun('2026-03-01', [['1120', 3000000, 0], ['4120', 0, 3000000]]);     // operasional masuk
    jurnalAkun('2026-03-05', [['5130', 2000000, 0], ['1110', 0, 2000000]]);     // operasional keluar
    jurnalAkun('2026-03-10', [['1120', 5000000, 0], ['3100', 0, 5000000]]);     // pendanaan masuk
    jurnalAkun('2026-03-12', [['1110', 1000000, 0], ['1120', 0, 1000000]]);     // pindah kas-bank: tidak berpengaruh

    $lap = lapAkuntansi('arus-kas', ['dari' => '2026-02-01', 'sampai' => '2026-12-31']);

    expect(totalLap($lap, 'Kas bersih dari aktivitas operasional', 'neto'))->toBe('1000000.00')
        ->and(totalLap($lap, 'Kas bersih dari aktivitas investasi', 'neto'))->toBe('0.00')
        ->and(totalLap($lap, 'Kas bersih dari aktivitas pendanaan', 'neto'))->toBe('5000000.00')
        ->and(totalLap($lap, 'KENAIKAN (PENURUNAN) KAS BERSIH', 'neto'))->toBe('6000000.00')
        ->and(totalLap($lap, 'KENAIKAN (PENURUNAN) KAS BERSIH', 'masuk'))->toBe('8000000.00')
        ->and(totalLap($lap, 'KENAIKAN (PENURUNAN) KAS BERSIH', 'keluar'))->toBe('2000000.00')
        ->and(totalLap($lap, 'SALDO KAS AWAL PERIODE', 'neto'))->toBe('10000000.00')
        ->and(totalLap($lap, 'SALDO KAS AKHIR PERIODE', 'neto'))->toBe('16000000.00')
        ->and($lap['info'])->not->toStartWith('PERINGATAN')
        ->and($lap['baris']->pluck('kode')->all())->toBe(['4120', '5130', '3100']);   // operasional dulu, pendanaan terakhir
});

it('arus kas: jurnal tanpa kas tidak ikut, dan jurnal pembalik membatalkan efeknya', function () {
    jurnalAkun('2026-03-01', [['1200', 4000000, 0], ['4120', 0, 4000000]]);   // piutang: bukan kas
    $j = jurnalAkun('2026-03-02', [['1120', 3000000, 0], ['4120', 0, 3000000]]);
    app(JurnalService::class)->balik($j, 'salah', userDengan('Admin Keuangan'));

    $lap = lapAkuntansi('arus-kas', ['dari' => '2026-01-01', 'sampai' => '2026-12-31']);

    expect(totalLap($lap, 'KENAIKAN (PENURUNAN) KAS BERSIH', 'neto'))->toBe('0.00')
        ->and($lap['baris'])->toHaveCount(0);
});

it('arus kas satu jurnal dengan beberapa akun lawan dibagi per akun', function () {
    jurnalAkun('2026-03-01', [['1120', 5000000, 0], ['4120', 0, 3000000], ['4150', 0, 2000000]]);

    $lap = lapAkuntansi('arus-kas', ['dari' => '2026-01-01', 'sampai' => '2026-12-31']);

    expect($lap['baris']->pluck('masuk', 'kode')->all())->toBe(['4120' => '3000000.00', '4150' => '2000000.00'])
        ->and(totalLap($lap, 'SALDO KAS AKHIR PERIODE', 'neto'))->toBe('5000000.00');
});

// ------------------------------------------------------------------ Buku Besar

it('buku besar tanpa akun terpilih menghasilkan laporan kosong dengan petunjuk', function () {
    jurnalAkun('2026-03-01', [['1120', 1000, 0], ['4120', 0, 1000]]);

    $lap = lapAkuntansi('buku-besar');

    expect($lap['baris'])->toHaveCount(0)->and($lap['info'])->toContain('Pilih satu akun');
});

it('buku besar akun debit-normal: saldo awal dan saldo berjalan', function () {
    jurnalAkun('2026-01-15', [['1120', 10000000, 0], ['3100', 0, 10000000]]);
    jurnalAkun('2026-03-01', [['1120', 3000000, 0], ['4120', 0, 3000000]]);
    jurnalAkun('2026-03-05', [['5130', 2000000, 0], ['1120', 0, 2000000]]);

    $lap = lapAkuntansi('buku-besar', ['akun' => (string) akunId('1120'), 'dari' => '2026-02-01', 'sampai' => '2026-12-31']);

    expect($lap['baris'])->toHaveCount(3)
        ->and($lap['baris'][0])->toMatchArray(['keterangan' => 'Saldo awal', 'saldo' => '10000000.00'])
        ->and($lap['baris'][1])->toMatchArray(['debit' => '3000000.00', 'kredit' => null, 'saldo' => '13000000.00'])
        ->and($lap['baris'][2])->toMatchArray(['debit' => null, 'kredit' => '2000000.00', 'saldo' => '11000000.00'])
        ->and(totalLap($lap, 'TOTAL MUTASI', 'debit'))->toBe('3000000.00')
        ->and(totalLap($lap, 'TOTAL MUTASI', 'kredit'))->toBe('2000000.00')
        ->and(totalLap($lap, 'SALDO AKHIR', 'saldo'))->toBe('11000000.00');
});

it('buku besar akun kredit-normal: saldo bertambah di kredit', function () {
    jurnalAkun('2026-03-01', [['1120', 3000000, 0], ['4120', 0, 3000000]]);
    jurnalAkun('2026-03-02', [['1120', 1000000, 0], ['4120', 0, 1000000]]);

    $lap = lapAkuntansi('buku-besar', ['akun' => (string) akunId('4120'), 'dari' => '2026-01-01', 'sampai' => '2026-12-31']);

    expect(totalLap($lap, 'SALDO AKHIR', 'saldo'))->toBe('4000000.00')
        ->and($lap['baris']->last()['saldo'])->toBe('4000000.00');
});

it('buku besar: akun tidak dikenal menghasilkan laporan kosong, bukan error', function () {
    $lap = lapAkuntansi('buku-besar', ['akun' => '999999']);

    expect($lap['baris'])->toHaveCount(0);
});

it('saldo akhir buku besar sama dengan baris akun yang sama di neraca saldo', function () {
    jurnalAkun('2026-03-01', [['1120', 3000000, 0], ['4120', 0, 3000000]]);
    jurnalAkun('2026-03-05', [['5130', 2000000, 0], ['1120', 0, 2000000]]);

    $bb = lapAkuntansi('buku-besar', ['akun' => (string) akunId('1120'), 'dari' => '2026-01-01', 'sampai' => '2026-12-31']);
    $ns = lapAkuntansi('neraca-saldo', ['sampai' => '2026-12-31']);

    expect(totalLap($bb, 'SALDO AKHIR', 'saldo'))->toBe('1000000.00')
        ->and($ns['baris']->firstWhere('kode', '1120')['debit'])->toBe('1000000.00');
});

// ------------------------------------------------------------------ Neraca Saldo

it('neraca saldo seimbang dan menaruh saldo di sisi yang benar', function () {
    jurnalAkun('2026-03-01', [['1120', 3000000, 0], ['4120', 0, 3000000]]);
    jurnalAkun('2026-03-05', [['5130', 2000000, 0], ['1110', 0, 2000000]]);

    $lap = lapAkuntansi('neraca-saldo', ['sampai' => '2026-12-31']);
    $b = $lap['baris']->keyBy('kode');

    expect($b['1120']['debit'])->toBe('3000000.00')
        ->and($b['1110']['kredit'])->toBe('2000000.00')      // kas kredit karena minus
        ->and($b['4120']['kredit'])->toBe('3000000.00')
        ->and($b['5130']['debit'])->toBe('2000000.00')
        ->and(totalLap($lap, 'TOTAL', 'debit'))->toBe(totalLap($lap, 'TOTAL', 'kredit'))
        ->and($lap['info'])->toContain('seimbang')
        ->and($lap['info'])->not->toStartWith('PERINGATAN');
});

it('neraca saldo kumulatif sampai tanggal terpilih saja', function () {
    jurnalAkun('2026-03-01', [['1120', 3000000, 0], ['4120', 0, 3000000]]);
    jurnalAkun('2026-09-01', [['1120', 9000000, 0], ['4120', 0, 9000000]]);

    $lap = lapAkuntansi('neraca-saldo', ['sampai' => '2026-06-30']);

    expect(totalLap($lap, 'TOTAL', 'debit'))->toBe('3000000.00');
});

it('neraca saldo tanpa jurnal kosong dan nol', function () {
    $lap = lapAkuntansi('neraca-saldo', ['sampai' => '2026-12-31']);

    expect($lap['baris'])->toHaveCount(0)->and(totalLap($lap, 'TOTAL', 'debit'))->toBe('0.00');
});

// ------------------------------------------------------------------ Ekspor dan akses

it('keempat laporan akuntansi bisa diekspor ke Excel, CSV, dan PDF oleh admin keuangan', function (string $jenis) {
    jurnalAkun('2026-03-01', [['1120', 3000000, 0], ['4120', 0, 3000000]]);
    $this->actingAs(userDengan('Admin Keuangan'));
    $q = ['akun' => (string) akunId('1120'), 'dari' => '2026-01-01', 'sampai' => '2026-12-31'];

    $this->get(route('laporan.ekspor', ['jenis' => $jenis, 'format' => 'xlsx'] + $q))->assertOk();
    $this->get(route('laporan.ekspor', ['jenis' => $jenis, 'format' => 'csv'] + $q))->assertOk();
    expect($this->get(route('laporan.ekspor', ['jenis' => $jenis, 'format' => 'pdf'] + $q))->headers->get('content-type'))
        ->toContain('application/pdf');
})->with(['laba-rugi', 'arus-kas', 'buku-besar', 'neraca-saldo']);

it('layar laporan akuntansi menampilkan angka dan akun buku besar bisa dipilih', function () {
    jurnalAkun('2026-03-01', [['1120', 3000000, 0], ['4120', 0, 3000000]]);
    $this->actingAs(userDengan('Pimpinan'));

    \Livewire\Livewire::withQueryParams(['dari' => '2026-01-01', 'sampai' => '2026-12-31'])
        ->test(\App\Livewire\Laporan\Tampil::class, ['jenis' => 'laba-rugi'])
        ->assertSee('LABA BERSIH')->assertSee('3.000.000');

    \Livewire\Livewire::test(\App\Livewire\Laporan\Tampil::class, ['jenis' => 'buku-besar'])
        ->assertSee('Pilih akun...')->assertSee('1120 - Bank')->assertSee('Dari tanggal');

    \Livewire\Livewire::test(\App\Livewire\Laporan\Tampil::class, ['jenis' => 'neraca-saldo'])
        ->assertSee('Per tanggal')->assertDontSee('Dari tanggal')->assertDontSee('Pilih akun...');
});

it('operator tidak bisa mengekspor laporan akuntansi', function () {
    $this->actingAs(userDengan('Operator'))->get(route('laporan.ekspor', ['laba-rugi', 'xlsx']))->assertForbidden();
    $this->actingAs(userDengan('Operator'))->get(route('laporan.laba-rugi'))->assertForbidden();
});
