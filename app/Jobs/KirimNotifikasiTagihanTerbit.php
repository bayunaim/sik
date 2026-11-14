<?php

namespace App\Jobs;

use App\Services\NotifikasiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * FR-037: kabari mahasiswa bahwa tagihan terbit. Dipakai oleh penerbitan massal supaya
 * ribuan notifikasi tidak menahan request web. Aman dijalankan ulang: NotifikasiService
 * memakai ref per tagihan sehingga tidak pernah mengirim ganda.
 */
class KirimNotifikasiTagihanTerbit implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    /** @param array<int,int> $tagihanIds */
    public function __construct(public array $tagihanIds)
    {
    }

    public function handle(NotifikasiService $notifikasi): void
    {
        $notifikasi->tagihanTerbit($this->tagihanIds);
    }
}
