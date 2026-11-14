<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\NotifikasiAplikasi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class BackupDatabase extends Command
{
    private const SISA_MINIMAL = 3;

    protected $signature = 'db:backup {--simpan= : Hari penyimpanan (bawaan: config/backup.php)}';

    protected $description = 'Backup database (mysqldump + gzip) ke storage/app/private/backup dan hapus yang sudah lewat masa simpan';

    public function handle(): int
    {
        $k = config('database.connections.'.config('database.default'));
        if (($k['driver'] ?? null) !== 'mysql') {
            $this->error('Backup ini hanya untuk koneksi MySQL.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory('backup');
        $tujuan = $disk->path('backup/sik-'.now()->format('Ymd-His').'.sql.gz');

        try {
            $this->dump($k, $tujuan);
            $hapus = $this->hapusLama((int) ($this->option('simpan') ?: config('backup.simpan_hari', 14)));
        } catch (Throwable $e) {
            @unlink($tujuan);
            report($e);
            $this->laporkanGagal($e->getMessage());
            $this->error('Backup gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup selesai: '.basename($tujuan).' ('.number_format(filesize($tujuan) / 1024, 0, ',', '.').' KB). '.$hapus.' berkas lama dihapus.');

        return self::SUCCESS;
    }

    /** Hapus berkas backup yang lebih tua dari $hari, tetapi sisakan SISA_MINIMAL yang terbaru. Mengembalikan jumlah terhapus. */
    public function hapusLama(int $hari): int
    {
        $disk = Storage::disk('local');
        $batas = now()->subDays(max(1, $hari))->getTimestamp();

        $berkas = collect($disk->files('backup'))
            ->filter(fn (string $f) => preg_match('/^sik-\d{8}-\d{6}\.sql\.gz$/', basename($f)) === 1)
            ->sortByDesc(fn (string $f) => $disk->lastModified($f))
            ->values();

        $terhapus = 0;
        foreach ($berkas->slice(self::SISA_MINIMAL) as $f) {
            if ($disk->lastModified($f) < $batas) {
                $disk->delete($f);
                $terhapus++;
            }
        }

        return $terhapus;
    }

    private function dump(array $k, string $tujuan): void
    {
        $cmd = [
            config('backup.mysqldump', 'mysqldump'),
            '--single-transaction', '--quick', '--routines', '--triggers',
            '--default-character-set=utf8mb4',
            '--user='.$k['username'],
        ];
        if (! empty($k['unix_socket'])) {
            $cmd[] = '--socket='.$k['unix_socket'];
        } else {
            $cmd[] = '--host='.$k['host'];
            $cmd[] = '--port='.$k['port'];
        }
        $cmd[] = $k['database'];

        // kata sandi lewat environment, bukan argumen, supaya tidak terlihat di daftar proses
        $proses = new Process($cmd, null, ['MYSQL_PWD' => (string) ($k['password'] ?? '')]);
        $proses->setTimeout(1800);

        $gz = gzopen($tujuan, 'wb9');
        if ($gz === false) {
            throw new RuntimeException('Tidak bisa menulis ke folder backup.');
        }

        $proses->run(function (string $tipe, string $data) use ($gz) {
            if ($tipe === Process::OUT) {
                gzwrite($gz, $data);
            }
        });
        gzclose($gz);

        if (! $proses->isSuccessful()) {
            throw new RuntimeException('mysqldump gagal: '.trim($proses->getErrorOutput() ?: 'tidak ada pesan (cek MYSQLDUMP_PATH)'));
        }

        if (! is_file($tujuan) || filesize($tujuan) < 200) {
            throw new RuntimeException('Berkas backup kosong atau terlalu kecil.');
        }
    }

    private function laporkanGagal(string $pesan): void
    {
        $penerima = User::role('SuperAdmin')->where('is_active', true)->get();
        if ($penerima->isEmpty()) {
            return;
        }

        Notification::send($penerima, new NotifikasiAplikasi(
            'backup',
            'Backup database gagal',
            mb_substr($pesan, 0, 300),
            route('dashboard'),
            'backup-gagal:'.now()->toDateString(),
        ));
    }
}
