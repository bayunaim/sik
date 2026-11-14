<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\NotifikasiAplikasi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class UjiRestoreBackup extends Command
{
    protected $signature = 'db:uji-restore {berkas? : Nama berkas di storage/app/private/backup (bawaan: yang terbaru)}';

    protected $description = 'Uji restore: pulihkan backup ke database sementara, periksa isinya, lalu hapus (tidak menyentuh database utama)';

    public function handle(): int
    {
        $k = config('database.connections.'.config('database.default'));
        $sementara = ($k['database'] ?? 'sik').'_uji_restore';

        try {
            if (($k['driver'] ?? null) !== 'mysql' || preg_match('/^[A-Za-z0-9_]+$/', $sementara) !== 1) {
                throw new RuntimeException('Koneksi bukan MySQL atau nama database tidak valid.');
            }

            $berkas = $this->cariBerkas();
            $this->sql($k, "DROP DATABASE IF EXISTS `{$sementara}`; CREATE DATABASE `{$sementara}` CHARACTER SET utf8mb4;");

            try {
                $this->impor($k, $sementara, $berkas);
                $dipulihkan = (int) trim($this->sql($k, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$sementara}'", true));
            } finally {
                $this->sql($k, "DROP DATABASE IF EXISTS `{$sementara}`");
            }

            $asli = (int) (DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ?', [$k['database']])->n ?? 0);

            if ($dipulihkan === 0) {
                throw new RuntimeException('Backup '.basename($berkas).' dipulihkan tetapi tidak berisi tabel.');
            }
        } catch (Throwable $e) {
            report($e);
            $this->laporkanGagal($e->getMessage());
            $this->error('Uji restore gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $catatan = $dipulihkan === $asli ? '' : " Perhatian: jumlah tabel berbeda dari database utama ({$asli}); mungkin ada migrasi setelah backup dibuat.";
        $this->info('Uji restore berhasil: '.basename($berkas)." berisi {$dipulihkan} tabel.".$catatan);

        return self::SUCCESS;
    }

    private function cariBerkas(): string
    {
        $disk = Storage::disk('local');
        $nama = $this->argument('berkas');

        if ($nama) {
            if (preg_match('/^sik-\d{8}-\d{6}\.sql\.gz$/', $nama) !== 1 || ! $disk->exists('backup/'.$nama)) {
                throw new RuntimeException('Berkas backup tidak ditemukan atau namanya tidak valid.');
            }

            return $disk->path('backup/'.$nama);
        }

        $terbaru = collect($disk->files('backup'))
            ->filter(fn (string $f) => preg_match('/^sik-\d{8}-\d{6}\.sql\.gz$/', basename($f)) === 1)
            ->sortByDesc(fn (string $f) => $disk->lastModified($f))
            ->first();

        if (! $terbaru) {
            throw new RuntimeException('Belum ada berkas backup. Jalankan db:backup dulu.');
        }

        return $disk->path($terbaru);
    }

    /** Jalankan perintah SQL lewat klien mysql. $keluaran true: kembalikan hasil (mode tanpa header). */
    private function sql(array $k, string $perintah, bool $keluaran = false): string
    {
        $p = $this->mysql($k, null, ['-N', '-e', $perintah]);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new RuntimeException('mysql gagal: '.trim($p->getErrorOutput() ?: 'tidak ada pesan (cek MYSQL_PATH)'));
        }

        return $keluaran ? $p->getOutput() : '';
    }

    private function impor(array $k, string $db, string $berkas): void
    {
        $gz = gzopen($berkas, 'rb');
        if ($gz === false) {
            throw new RuntimeException('Berkas backup tidak bisa dibuka.');
        }

        $baca = function () use ($gz) {
            while (! gzeof($gz)) {
                yield gzread($gz, 1024 * 256);
            }
        };

        try {
            $p = $this->mysql($k, $db, []);
            $p->setInput($baca());
            $p->run();
        } finally {
            gzclose($gz);
        }

        if (! $p->isSuccessful()) {
            throw new RuntimeException('Impor backup gagal: '.trim($p->getErrorOutput()));
        }
    }

    private function mysql(array $k, ?string $db, array $argumen): Process
    {
        $cmd = [config('backup.mysql', 'mysql'), '--user='.$k['username'], '--default-character-set=utf8mb4'];
        if (! empty($k['unix_socket'])) {
            $cmd[] = '--socket='.$k['unix_socket'];
        } else {
            $cmd[] = '--host='.$k['host'];
            $cmd[] = '--port='.$k['port'];
        }
        if ($db !== null) {
            $cmd[] = $db;
        }

        $p = new Process([...$cmd, ...$argumen], null, ['MYSQL_PWD' => (string) ($k['password'] ?? '')]);
        $p->setTimeout(1800);

        return $p;
    }

    private function laporkanGagal(string $pesan): void
    {
        $penerima = User::role('SuperAdmin')->where('is_active', true)->get();
        if ($penerima->isEmpty()) {
            return;
        }

        Notification::send($penerima, new NotifikasiAplikasi(
            'backup',
            'Uji restore backup gagal',
            mb_substr($pesan, 0, 300),
            route('dashboard'),
            'uji-restore-gagal:'.now()->toDateString(),
        ));
    }
}
