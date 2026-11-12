<?php

namespace App\Console\Commands;

use App\Services\NotifikasiService;
use Illuminate\Console\Command;

class KirimNotifikasiJatuhTempo extends Command
{
    protected $signature = 'notifikasi:jatuh-tempo {--hari=3 : Kirim untuk tagihan yang jatuh tempo dalam sekian hari ke depan}';

    protected $description = 'Kirim pengingat tagihan yang akan atau sudah jatuh tempo ke mahasiswa (FR-130)';

    public function handle(NotifikasiService $notifikasi): int
    {
        $jumlah = $notifikasi->kirimJatuhTempo(max(0, (int) $this->option('hari')));
        $this->info("{$jumlah} notifikasi jatuh tempo terkirim.");

        return self::SUCCESS;
    }
}
