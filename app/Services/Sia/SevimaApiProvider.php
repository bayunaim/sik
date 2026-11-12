<?php

namespace App\Services\Sia;

use App\Contracts\SiaProvider;
use App\Models\ImportLog;
use App\Models\User;
use DomainException;

/**
 * Kerangka untuk integrasi API SEVIMA. Belum aktif: dokumentasi API resmi belum tersedia (PRD bagian 17, no. 1).
 * Isi method di bawah ketika kontrak API sudah jelas; pemanggil tidak perlu diubah.
 */
class SevimaApiProvider implements SiaProvider
{
    public function nama(): string
    {
        return 'sevima';
    }

    public function field(): array
    {
        return $this->belumAktif();
    }

    public function baca(string $path): array
    {
        return $this->belumAktif();
    }

    public function tebakPemetaan(array $header, ?array $template): array
    {
        return $this->belumAktif();
    }

    public function analisis(array $baca, array $mapping): array
    {
        return $this->belumAktif();
    }

    public function simpan(int $logId, array $baca, array $mapping, bool $tandaiHilang, User $user): ImportLog
    {
        return $this->belumAktif();
    }

    public function csvError(array $error): string
    {
        return $this->belumAktif();
    }

    private function belumAktif(): never
    {
        throw new DomainException('Integrasi API SEVIMA belum diaktifkan. Gunakan import file (SIA_PROVIDER=csv).');
    }
}
