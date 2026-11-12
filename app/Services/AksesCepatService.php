<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/** FR-104: menyaring tombol akses cepat (config/akses_cepat.php) menurut izin pengguna. */
class AksesCepatService
{
    /**
     * @param  array<string,int>  $antrean  jumlah antrean per jenis badge, mis. ['verifikasi' => 3, 'approval' => 1]
     * @return list<array{label:string,icon:string,url:string,badge:int}>
     */
    public function untuk(User $user, array $antrean = []): array
    {
        return collect(config('akses_cepat', []))
            ->filter(fn (array $i) => Route::has($i['route']) && $user->can($i['can']))
            ->map(fn (array $i) => [
                'label' => $i['label'],
                'icon' => $i['icon'],
                'url' => route($i['route'], $i['params'] ?? []),
                'badge' => isset($i['badge']) ? max(0, (int) ($antrean[$i['badge']] ?? 0)) : 0,
            ])
            ->values()
            ->all();
    }
}
