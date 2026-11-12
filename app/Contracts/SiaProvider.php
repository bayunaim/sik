<?php

namespace App\Contracts;

use App\Models\ImportLog;
use App\Models\User;

/**
 * FR-153: pintu tunggal antara SIK dan SIA. Modul tagihan dan pembayaran tidak tahu data mahasiswa
 * datang dari file GoFeeder atau API SEVIMA, jadi mengganti integrasi cukup mengganti provider (config/sia.php).
 */
interface SiaProvider
{
    /** Nama pendek provider, untuk log dan tampilan. */
    public function nama(): string;

    /** Definisi field yang dipetakan: [kunci => ['label', 'wajib', 'sinonim']]. */
    public function field(): array;

    /** @return array{header: array<int,string>, data: array<int,array{baris:int,sel:array}>} */
    public function baca(string $path): array;

    public function tebakPemetaan(array $header, ?array $template): array;

    /** Analisis tanpa menyimpan apa pun. */
    public function analisis(array $baca, array $mapping): array;

    public function simpan(int $logId, array $baca, array $mapping, bool $tandaiHilang, User $user): ImportLog;

    /** Isi CSV baris yang gagal, untuk diunduh. */
    public function csvError(array $error): string;
}
