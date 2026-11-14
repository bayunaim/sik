<?php

use App\Services\Sia\CsvImportProvider;
use App\Services\Sia\SevimaApiProvider;

return [
    /*
    | Provider SIA yang dipakai (FR-153): "csv" (import file GoFeeder, versi 1) atau "sevima" (API, kemudian).
    */
    'provider' => env('SIA_PROVIDER', 'csv'),

    'providers' => [
        'csv' => CsvImportProvider::class,
        'sevima' => SevimaApiProvider::class,
    ],

    /*
    | Pengingat import GoFeeder ke Admin (FR-145).
    | - hari_maksimal: pengingat mingguan bila import berhasil terakhir lebih tua dari sekian hari.
    | - awal_semester: tanggal mulai semester (BB-HH, dipisah koma), mis. "02-01,08-01".
    | - jendela_awal_semester: berapa hari setelah tanggal itu pengingat masih dikirim bila belum ada import.
    */
    'pengingat_import' => [
        'hari_maksimal' => (int) env('SIA_IMPORT_MAKS_HARI', 7),
        'awal_semester' => array_values(array_filter(array_map('trim', explode(',', (string) env('SIA_AWAL_SEMESTER', '02-01,08-01'))))),
        'jendela_awal_semester' => (int) env('SIA_JENDELA_AWAL_SEMESTER', 14),
    ],

    /* Batas panggilan API status keuangan per menit (FR-152). */
    'api_per_menit' => (int) env('SIA_API_PER_MENIT', 60),
];
