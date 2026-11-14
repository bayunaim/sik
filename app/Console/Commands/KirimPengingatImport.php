<?php

namespace App\Console\Commands;

use App\Services\NotifikasiService;
use Illuminate\Console\Command;

class KirimPengingatImport extends Command
{
    protected $signature = 'notifikasi:pengingat-import';

    protected $description = 'Ingatkan Admin untuk import data mahasiswa dari GoFeeder: mingguan dan awal semester (FR-145)';

    public function handle(NotifikasiService $notifikasi): int
    {
        $jumlah = $notifikasi->kirimPengingatImport();
        $this->info("{$jumlah} pengingat import terkirim.");

        return self::SUCCESS;
    }
}
