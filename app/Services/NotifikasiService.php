<?php

namespace App\Services;

use App\Models\ImportLog;
use App\Models\Pembayaran;
use App\Models\Pengeluaran;
use App\Models\Tagihan;
use App\Models\User;
use App\Notifications\NotifikasiAplikasi;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * FR-130: notifikasi di aplikasi.
 * Dipanggil SETELAH transaksi utama selesai. Kegagalan kirim notifikasi hanya dilaporkan
 * ke log dan tidak pernah menggagalkan pembayaran atau pengeluaran.
 */
class NotifikasiService
{
    public function __construct(private PengeluaranService $pengeluaran)
    {
    }

    /** Pembayaran baru berstatus Pending: beri tahu yang berhak memverifikasi. */
    public function pembayaranMenunggu(Pembayaran $p): void
    {
        $this->aman(function () use ($p) {
            $p->loadMissing('mahasiswa');
            $nama = $p->mahasiswa?->nama ?? 'Mahasiswa';

            $this->kirim($this->penerima('pembayaran.verifikasi'), new NotifikasiAplikasi(
                'pembayaran_menunggu',
                'Pembayaran menunggu verifikasi',
                "{$nama} - ".$this->rupiah($p->nominal).'.',
                route('pembayaran.index', [], false),
                'pembayaran:'.$p->id,
            ));
        });
    }

    /** Pembayaran sudah diverifikasi atau ditolak: tandai dibaca untuk staf, kabari mahasiswa. */
    public function pembayaranSelesai(Pembayaran $p): void
    {
        $this->aman(function () use ($p) {
            $this->tandaiDibaca('pembayaran:'.$p->id);

            $p->loadMissing('mahasiswa.user');
            $user = $p->mahasiswa?->user;
            if (! $user) {
                return;
            }

            $verifikasi = $p->status === Pembayaran::TERVERIFIKASI;
            if (! $verifikasi && $p->status !== Pembayaran::DITOLAK) {
                return;
            }

            $user->notify(new NotifikasiAplikasi(
                'pembayaran_hasil',
                $verifikasi ? 'Pembayaran diverifikasi' : 'Pembayaran ditolak',
                $this->rupiah($p->nominal).($verifikasi
                    ? ' sudah diverifikasi. Kwitansi bisa diunduh di Portal.'
                    : ' ditolak. Alasan: '.($p->alasan_tolak ?: '-')),
                route('portal.index', [], false),
                'pembayaran-hasil:'.$p->id,
            ));
        });
    }

    /** Pengeluaran baru Pending: hanya approver yang boleh menyetujuinya (batas nominal, bukan pengaju). */
    public function pengeluaranMenunggu(Pengeluaran $p): void
    {
        $this->aman(function () use ($p) {
            $p->loadMissing('pengaju');

            $approver = $this->penerima('pengeluaran.approve')
                ->filter(fn (User $u) => $this->pengeluaran->alasanTidakBisaSetujui($p, $u) === null);

            $this->kirim($approver, new NotifikasiAplikasi(
                'pengeluaran_menunggu',
                'Pengeluaran menunggu persetujuan',
                "{$p->nomor}: {$p->keperluan} - ".$this->rupiah($p->nominal)
                    .' (diajukan '.($p->pengaju?->name ?? '-').').',
                route('pengeluaran.index', [], false),
                'pengeluaran:'.$p->id,
            ));
        });
    }

    /** Pengeluaran disetujui atau ditolak: tandai dibaca untuk approver, kabari pengaju. */
    public function pengeluaranSelesai(Pengeluaran $p): void
    {
        $this->aman(function () use ($p) {
            $this->tandaiDibaca('pengeluaran:'.$p->id);

            $disetujui = $p->status === Pengeluaran::DISETUJUI;
            if (! $disetujui && $p->status !== Pengeluaran::DITOLAK) {
                return;
            }
            $pengaju = $p->pengaju;
            if (! $pengaju || $pengaju->id === $p->diproses_oleh) {
                return;
            }

            $pengaju->notify(new NotifikasiAplikasi(
                'pengeluaran_hasil',
                $disetujui ? 'Pengeluaran disetujui' : 'Pengeluaran ditolak',
                "{$p->nomor}: {$p->keperluan}".($disetujui ? '.' : '. Alasan: '.($p->alasan_tolak ?: '-')),
                route('pengeluaran.index', [], false),
                'pengeluaran-hasil:'.$p->id,
            ));
        });
    }

    /**
     * Pengingat jatuh tempo ke mahasiswa. Dua tahap per tagihan, masing-masing sekali saja:
     * "segera" (jatuh tempo dalam $hari hari ke depan) dan "lewat" (sudah lewat jatuh tempo).
     * Tagihan lunas, tagihan yang pembayarannya sedang menunggu verifikasi, dan tahun yang sudah ditutup dilewati.
     *
     * @return int jumlah notifikasi yang dikirim
     */
    public function kirimJatuhTempo(int $hari = 3): int
    {
        $terkirim = 0;
        $batas = today()->addDays($hari);

        Tagihan::query()
            ->with(['mahasiswa.user', 'komponen'])
            ->whereNotIn('status', [Tagihan::LUNAS, Tagihan::PENDING])
            ->whereNotNull('jatuh_tempo')
            ->whereDate('jatuh_tempo', '<=', $batas)
            ->whereHas('tahun', fn ($q) => $q->where('status', '!=', 'ditutup'))
            ->chunkById(500, function ($daftar) use (&$terkirim) {
                foreach ($daftar as $t) {
                    $user = $t->mahasiswa?->user;
                    if (! $user || ! $user->is_active) {
                        continue;
                    }

                    $lewat = $t->jatuh_tempo->lt(today());
                    $ref = 'jatuh-tempo:'.$t->id.':'.($lewat ? 'lewat' : 'segera');
                    if ($user->notifications()->where('data->ref', $ref)->exists()) {
                        continue;
                    }

                    $tanggal = $t->jatuh_tempo->format('d/m/Y');
                    $user->notify(new NotifikasiAplikasi(
                        'tagihan_jatuh_tempo',
                        $lewat ? 'Tagihan melewati jatuh tempo' : 'Tagihan segera jatuh tempo',
                        "{$t->nomor} ({$t->komponen?->nama}): "
                            .($lewat ? "jatuh tempo {$tanggal} sudah lewat" : "jatuh tempo {$tanggal}")
                            .'. Sisa '.$this->rupiah($t->sisa).'.',
                        route('portal.index', [], false),
                        $ref,
                    ));
                    $terkirim++;
                }
            });

        return $terkirim;
    }

    /**
     * FR-145: pengingat import GoFeeder ke yang berhak mengimpor. Satu pengingat per periode dan per penerima:
     * - awal semester: dalam jendela setelah tanggal mulai semester dan belum ada import berhasil sejak tanggal itu;
     * - mingguan: import berhasil terakhir lebih tua dari batas hari (atau belum pernah ada).
     * Bila keduanya jatuh tempo, yang dikirim hanya pengingat awal semester.
     *
     * @return int jumlah penerima yang diberi pengingat
     */
    public function kirimPengingatImport(?Carbon $hari = null): int
    {
        $hari = ($hari ?? today())->copy()->startOfDay();
        $terakhirRaw = ImportLog::where('status', 'selesai')->max('updated_at');
        $terakhir = $terakhirRaw ? Carbon::parse($terakhirRaw) : null;
        $cfg = (array) config('sia.pengingat_import');

        $pengingat = $this->pengingatAwalSemester($hari, $terakhir, (array) ($cfg['awal_semester'] ?? []), (int) ($cfg['jendela_awal_semester'] ?? 14))
            ?? $this->pengingatMingguan($hari, $terakhir, (int) ($cfg['hari_maksimal'] ?? 7));

        if ($pengingat === null) {
            return 0;
        }

        [$ref, $judul, $pesan] = $pengingat;

        $penerima = $this->penerima('import.sia')
            ->reject(fn (User $u) => $u->notifications()->where('data->ref', $ref)->exists())
            ->values();

        $this->kirim($penerima, new NotifikasiAplikasi(
            'pengingat_import',
            $judul,
            $pesan,
            route('import.index', [], false),
            $ref,
        ));

        return $penerima->count();
    }

    /** @return array{0:string,1:string,2:string}|null */
    protected function pengingatAwalSemester(Carbon $hari, ?Carbon $terakhir, array $tanggalMulai, int $jendela): ?array
    {
        foreach ($tanggalMulai as $bbhh) {
            if (! preg_match('/^(\d{2})-(\d{2})$/', (string) $bbhh, $m) || ! checkdate((int) $m[1], (int) $m[2], $hari->year)) {
                continue;
            }

            $mulai = Carbon::create($hari->year, (int) $m[1], (int) $m[2])->startOfDay();
            $dalamJendela = $hari->betweenIncluded($mulai, $mulai->copy()->addDays(max(1, $jendela) - 1));

            if ($dalamJendela && ($terakhir === null || $terakhir->lt($mulai))) {
                return [
                    'pengingat-import:semester:'.$mulai->toDateString(),
                    'Saatnya import data mahasiswa awal semester',
                    'Semester dimulai '.$mulai->format('d/m/Y').' dan belum ada import GoFeeder sejak itu. '
                        .'Import dulu supaya tagihan tidak salah sasaran.',
                ];
            }
        }

        return null;
    }

    /** @return array{0:string,1:string,2:string}|null */
    protected function pengingatMingguan(Carbon $hari, ?Carbon $terakhir, int $hariMaksimal): ?array
    {
        if ($terakhir !== null && $terakhir->gte($hari->copy()->subDays($hariMaksimal))) {
            return null;
        }

        return [
            'pengingat-import:mingguan:'.$hari->isoWeekYear().'-W'.str_pad((string) $hari->isoWeek(), 2, '0', STR_PAD_LEFT),
            "Import GoFeeder sudah lebih dari {$hariMaksimal} hari",
            $terakhir
                ? 'Import berhasil terakhir '.$terakhir->format('d/m/Y').'. Perbarui data mahasiswa dari GoFeeder.'
                : 'Belum pernah ada import yang berhasil. Impor data mahasiswa dari GoFeeder.',
        ];
    }

    /** Import berhasil: pengingat import yang belum dibaca tidak berlaku lagi. */
    public function pengingatImportSelesai(): void
    {
        $this->aman(fn () => DatabaseNotification::query()
            ->where('type', NotifikasiAplikasi::class)
            ->whereNull('read_at')
            ->where('data->ref', 'like', 'pengingat-import:%')
            ->update(['read_at' => now()]));
    }

    /** Tandai dibaca semua notifikasi yang belum dibaca dengan ref ini (semua penerima). */
    public function tandaiDibaca(string $ref): void
    {
        DatabaseNotification::query()
            ->where('type', NotifikasiAplikasi::class)
            ->whereNull('read_at')
            ->where('data->ref', $ref)
            ->update(['read_at' => now()]);
    }

    /** Pengguna aktif yang punya permission (lewat role atau langsung), ditambah SuperAdmin. */
    protected function penerima(string $permission): Collection
    {
        return User::query()->permission($permission)->where('is_active', true)->get()
            ->merge(User::query()->role('SuperAdmin')->where('is_active', true)->get())
            ->unique('id')
            ->values();
    }

    protected function kirim(Collection $penerima, NotifikasiAplikasi $notifikasi): void
    {
        if ($penerima->isNotEmpty()) {
            Notification::send($penerima, $notifikasi);
        }
    }

    protected function rupiah(string|float|int|null $nilai): string
    {
        return 'Rp '.number_format((float) $nilai, 0, ',', '.');
    }

    protected function aman(callable $aksi): void
    {
        try {
            $aksi();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
