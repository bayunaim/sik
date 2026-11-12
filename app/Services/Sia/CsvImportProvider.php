<?php

namespace App\Services\Sia;

use App\Contracts\SiaProvider;
use App\Models\ImportLog;
use App\Models\User;
use App\Services\ImportMahasiswaService;

/** Versi 1: data mahasiswa dari file Excel/CSV hasil ekspor GoFeeder. */
class CsvImportProvider implements SiaProvider
{
    public function __construct(private ImportMahasiswaService $svc)
    {
    }

    public function nama(): string
    {
        return 'csv';
    }

    public function field(): array
    {
        return ImportMahasiswaService::FIELD;
    }

    public function baca(string $path): array
    {
        return $this->svc->baca($path);
    }

    public function tebakPemetaan(array $header, ?array $template): array
    {
        return $this->svc->tebakPemetaan($header, $template);
    }

    public function analisis(array $baca, array $mapping): array
    {
        return $this->svc->analisis($baca, $mapping);
    }

    public function simpan(int $logId, array $baca, array $mapping, bool $tandaiHilang, User $user): ImportLog
    {
        return $this->svc->simpan($logId, $baca, $mapping, $tandaiHilang, $user);
    }

    public function csvError(array $error): string
    {
        return $this->svc->csvError($error);
    }
}
