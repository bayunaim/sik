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

    /* Batas panggilan API status keuangan per menit (FR-152). */
    'api_per_menit' => (int) env('SIA_API_PER_MENIT', 60),
];
