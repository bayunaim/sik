<?php

use App\Livewire\Jurnal\Index as JurnalIndex;
use App\Models\Jurnal;
use App\Models\TahunAnggaran;
use App\Services\JurnalService;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function barisSesuai(string $nominal = '250000'): array
{
    return [
        ['akun_id' => akunId('5130'), 'debit' => $nominal, 'kredit' => '0'],
        ['akun_id' => akunId('1110'), 'debit' => '0', 'kredit' => $nominal],
    ];
}

// ------------------------------------------------------------------ JurnalService::penyesuaian (FR-073)

it('penyesuaian: membuat jurnal balance berjenis penyesuaian dengan alasan dan audit log', function () {
    tahunAktif();
    $admin = userDengan('Admin Keuangan');

    $j = app(JurnalService::class)->penyesuaian('2026-03-01', 'Koreksi salah klasifikasi beban', barisSesuai(), $admin);

    expect($j->jenis)->toBe(Jurnal::PENYESUAIAN)
        ->and($j->nomor)->toStartWith('JRN/2026/')
        ->and($j->keterangan)->toContain('Koreksi salah klasifikasi beban')
        ->and((string) $j->total_debit)->toBe('250000.00')
        ->and((string) $j->total_kredit)->toBe('250000.00')
        ->and($j->dibuat_oleh)->toBe($admin->id)
        ->and($j->detail)->toHaveCount(2);

    $log = Activity::where('log_name', 'jurnal')->where('event', 'penyesuaian')->first();
    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($j->id)
        ->and($log->properties['alasan'])->toBe('Koreksi salah klasifikasi beban');
});

it('penyesuaian: alasan wajib diisi dan tidak ada jurnal yang tersimpan', function () {
    tahunAktif();

    expect(fn () => app(JurnalService::class)->penyesuaian('2026-03-01', '  ab ', barisSesuai(), userDengan('Admin Keuangan')))
        ->toThrow(DomainException::class, 'Alasan');

    expect(Jurnal::count())->toBe(0);
});

it('penyesuaian: jurnal tidak balance ditolak dan tidak menyimpan apa pun', function () {
    tahunAktif();
    $baris = [
        ['akun_id' => akunId('5130'), 'debit' => '250000', 'kredit' => '0'],
        ['akun_id' => akunId('1110'), 'debit' => '0', 'kredit' => '200000'],
    ];

    expect(fn () => app(JurnalService::class)->penyesuaian('2026-03-01', 'Koreksi uji', $baris, userDengan('Admin Keuangan')))
        ->toThrow(DomainException::class, 'tidak balance');

    expect(Jurnal::count())->toBe(0)
        ->and(Activity::where('event', 'penyesuaian')->count())->toBe(0);
});

it('penyesuaian: hanya yang punya hak jurnal.penyesuaian', function (string $role) {
    tahunAktif();

    expect(fn () => app(JurnalService::class)->penyesuaian('2026-03-01', 'Koreksi uji', barisSesuai(), userDengan($role)))
        ->toThrow(DomainException::class, 'tidak berhak');

    expect(Jurnal::count())->toBe(0);
})->with(['Operator', 'Pimpinan', 'Mahasiswa']);

it('penyesuaian: akun induk ditolak, harus akun detail', function () {
    tahunAktif();
    $baris = [
        ['akun_id' => akunId('4100'), 'debit' => '100000', 'kredit' => '0'],
        ['akun_id' => akunId('1110'), 'debit' => '0', 'kredit' => '100000'],
    ];

    expect(fn () => app(JurnalService::class)->penyesuaian('2026-03-01', 'Koreksi uji', $baris, userDengan('Admin Keuangan')))
        ->toThrow(DomainException::class, 'Akun induk');

    expect(Jurnal::count())->toBe(0);
});

it('penyesuaian: tanggal di luar semua tahun anggaran ditolak', function () {
    tahunAktif();

    expect(fn () => app(JurnalService::class)->penyesuaian('2019-03-01', 'Koreksi uji', barisSesuai(), userDengan('Admin Keuangan')))
        ->toThrow(DomainException::class, 'Tidak ada tahun anggaran');
});

it('penyesuaian: tahun anggaran yang sudah ditutup menolak jurnal', function () {
    TahunAnggaran::create(['nama' => '2025', 'tanggal_mulai' => '2025-01-01', 'tanggal_selesai' => '2025-12-31', 'status' => 'ditutup']);

    expect(fn () => app(JurnalService::class)->penyesuaian('2025-06-01', 'Koreksi uji', barisSesuai(), userDengan('Admin Keuangan')))
        ->toThrow(DomainException::class, 'sudah ditutup');

    expect(Jurnal::count())->toBe(0);
});

it('penyesuaian: tidak bisa diubah atau dihapus, hanya dibalik', function () {
    tahunAktif();
    $admin = userDengan('Admin Keuangan');
    $j = app(JurnalService::class)->penyesuaian('2026-03-01', 'Koreksi uji', barisSesuai(), $admin);

    expect(fn () => $j->update(['keterangan' => 'x']))->toThrow(LogicException::class);

    $balik = app(JurnalService::class)->balik($j, 'Salah input', $admin);
    expect($balik->jenis)->toBe(Jurnal::PEMBALIK);
});

// ------------------------------------------------------------------ Layar

it('penyesuaian UI: tombol hanya tampil untuk yang berhak', function () {
    $this->actingAs(userDengan('Admin Keuangan'));
    Livewire::test(JurnalIndex::class)->assertSee('Jurnal Penyesuaian');

    $this->actingAs(userDengan('Pimpinan'));
    Livewire::test(JurnalIndex::class)->assertDontSee('Jurnal Penyesuaian');
});

it('penyesuaian UI: pimpinan tidak boleh membuka form maupun menyimpan langsung', function () {
    tahunAktif();
    $this->actingAs(userDengan('Pimpinan'));

    Livewire::test(JurnalIndex::class)->call('tambah')->assertForbidden();

    Livewire::test(JurnalIndex::class)
        ->set('tanggal', '2026-03-01')->set('alasan', 'Koreksi uji')
        ->set('baris', [
            ['akun_id' => akunId('5130'), 'debit' => '100', 'kredit' => ''],
            ['akun_id' => akunId('1110'), 'debit' => '', 'kredit' => '100'],
        ])
        ->call('simpan')->assertForbidden();

    expect(Jurnal::count())->toBe(0);
});

it('penyesuaian UI: admin keuangan membuat jurnal lewat layar', function () {
    tahunAktif();
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(JurnalIndex::class)
        ->call('tambah')
        ->set('tanggal', '2026-03-01')
        ->set('alasan', 'Koreksi salah klasifikasi beban')
        ->set('baris.0.akun_id', akunId('5130'))->set('baris.0.debit', '250000')
        ->set('baris.1.akun_id', akunId('1110'))->set('baris.1.kredit', '250000')
        ->call('simpan')
        ->assertHasNoErrors()
        ->assertSet('tampilForm', false);

    $j = Jurnal::first();
    expect($j->jenis)->toBe(Jurnal::PENYESUAIAN)
        ->and((string) $j->total_debit)->toBe('250000.00');
});

it('penyesuaian UI: kesalahan dari service tampil di form dan tidak ada jurnal tersimpan', function () {
    tahunAktif();
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(JurnalIndex::class)
        ->call('tambah')
        ->set('tanggal', '2026-03-01')
        ->set('alasan', 'Koreksi uji')
        ->set('baris.0.akun_id', akunId('5130'))->set('baris.0.debit', '250000')
        ->set('baris.1.akun_id', akunId('1110'))->set('baris.1.kredit', '100000')
        ->call('simpan')
        ->assertHasErrors('form')
        ->assertSet('tampilForm', true);

    expect(Jurnal::count())->toBe(0);
});

it('penyesuaian UI: input tidak valid ditolak sebelum sampai ke service', function () {
    tahunAktif();
    $this->actingAs(userDengan('Admin Keuangan'));

    Livewire::test(JurnalIndex::class)
        ->call('tambah')
        ->set('tanggal', '2026-03-01')
        ->set('alasan', 'ab')
        ->set('baris.0.akun_id', akunId('5130'))->set('baris.0.debit', 'abc')
        ->set('baris.1.akun_id', '')->set('baris.1.kredit', '-5')
        ->call('simpan')
        ->assertHasErrors(['alasan', 'baris.0.debit', 'baris.1.akun_id', 'baris.1.kredit']);

    expect(Jurnal::count())->toBe(0);
});

it('penyesuaian UI: baris tidak bisa dikurangi di bawah dua dan akun induk tidak ditawarkan', function () {
    $this->actingAs(userDengan('Admin Keuangan'));

    $c = Livewire::test(JurnalIndex::class)->call('tambah')->call('tambahBaris');
    expect($c->get('baris'))->toHaveCount(3);

    $c->call('hapusBaris', 0)->call('hapusBaris', 0)->call('hapusBaris', 0);
    expect($c->get('baris'))->toHaveCount(2);

    // daftar akun yang ditawarkan di form: hanya akun detail yang aktif
    $kode = $c->assertSet('tampilForm', true)->viewData('akun')->pluck('kode');
    expect($kode)->toContain('4120', '5130', '1110')
        ->and($kode)->not->toContain('4100')   // induk
        ->and($kode)->not->toContain('4000');  // induk
});

it('penyesuaian UI: akun nonaktif tidak ditawarkan di form', function () {
    $this->actingAs(userDengan('Admin Keuangan'));
    App\Models\ChartOfAccount::where('kode', '4120')->update(['is_active' => false]);

    $kode = Livewire::test(JurnalIndex::class)->call('tambah')->viewData('akun')->pluck('kode');

    expect($kode)->not->toContain('4120')->and($kode)->toContain('4130');
});
