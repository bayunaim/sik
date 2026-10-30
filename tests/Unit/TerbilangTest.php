<?php

use App\Support\Terbilang;

it('mengubah angka menjadi terbilang rupiah', function (string $angka, string $hasil) {
    expect(Terbilang::rupiah($angka))->toBe($hasil);
})->with([
    ['0', 'Nol rupiah'],
    ['11', 'Sebelas rupiah'],
    ['115', 'Seratus lima belas rupiah'],
    ['1000', 'Seribu rupiah'],
    ['2000', 'Dua ribu rupiah'],
    ['3500000.00', 'Tiga juta lima ratus ribu rupiah'],
    ['1250000000', 'Satu miliar dua ratus lima puluh juta rupiah'],
]);
