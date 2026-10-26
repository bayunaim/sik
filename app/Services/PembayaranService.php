<?php

namespace App\Services;

use App\Models\Pembayaran;
use App\Models\PenerimaanDana;
use App\Models\Tagihan;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class PembayaranService
{
    public function __construct(private JurnalService $jurnal)
    {
    }

    /** Sisa yang masih boleh dibayar = nominal - terbayar - pembayaran yang masih Pending. */
    public function sisaBisaDibayar(Tagihan $t): BigDecimal
    {
        $sisa = BigDecimal::of($t->nominal)
            ->minus($t->terbayar)
            ->minus($this->totalPending($t->id));

        return $sisa->isNegative() ? BigDecimal::zero()->toScale(2) : $sisa->toScale(2);
    }

    protected function totalPending(int $tagihanId): BigDecimal
    {
        $total = Pembayaran::where('tagihan_id', $tagihanId)
            ->where('status', Pembayaran::PENDING)
            ->sum('nominal');

        return BigDecimal::of((string) ($total ?? 0));
    }

    /**
     * FR-040: catat pembayaran, status awal Pending.
     * $d: tagihan_id, tanggal_bayar, nominal, metode, referensi?
     */
    public function catat(array $d, User $user, ?string $buktiPath = null): Pembayaran
    {
        return DB::transaction(function () use ($d, $user, $buktiPath) {
            $t = Tagihan::with('tahun')->lockForUpdate()->find($d['tagihan_id'])
                ?? throw new DomainException('Tagihan tidak ditemukan atau sudah dibatalkan.');

            if ($t->tahun->isDitutup()) {
                throw new DomainException('Tahun anggaran sudah ditutup, pembayaran tidak dapat dicatat.');
            }
            if ($t->status === Tagihan::LUNAS) {
                throw new DomainException('Tagihan ini sudah lunas.');
            }

            $nominal = BigDecimal::of($d['nominal'])->toScale(2);

            if ($nominal->isLessThanOrEqualTo(0)) {
                throw new DomainException('Nominal pembayaran harus lebih dari nol.');
            }

            // FR-046
            $sisa = $this->sisaBisaDibayar($t);
            if ($nominal->isGreaterThan($sisa)) {
                throw new DomainException('Nominal melebihi sisa tagihan yang bisa dibayar (Rp '
                    .number_format((float) (string) $sisa, 0, ',', '.')
                    .'). Pembayaran yang masih menunggu verifikasi ikut dihitung.');
            }

            $p = Pembayaran::create([
                'tagihan_id' => $t->id,
                'mahasiswa_id' => $t->mahasiswa_id,
                'tanggal_bayar' => $d['tanggal_bayar'],
                'nominal' => (string) $nominal,
                'metode' => $d['metode'],
                'referensi' => ($d['referensi'] ?? '') ?: null,
                'bukti_path' => $buktiPath,
                'status' => Pembayaran::PENDING,
                'diinput_oleh' => $user->id,
            ]);

            $this->segarkanStatus($t);

            return $p;
        });
    }

    /**
     * FR-043 dan FR-044: verifikasi secara atomik.
     * Urutan lock selalu tagihan dulu, lalu pembayaran.
     */
    public function verifikasi(int $pembayaranId, User $user): Pembayaran
    {
        $tagihanId = Pembayaran::whereKey($pembayaranId)->value('tagihan_id')
            ?? throw new DomainException('Pembayaran tidak ditemukan.');

        return DB::transaction(function () use ($pembayaranId, $tagihanId, $user) {
            $t = Tagihan::with(['tahun', 'komponen'])->lockForUpdate()->find($tagihanId)
                ?? throw new DomainException('Tagihan sudah dibatalkan.');
            $p = Pembayaran::lockForUpdate()->findOrFail($pembayaranId);

            if ($p->status !== Pembayaran::PENDING) {
                throw new DomainException('Pembayaran ini sudah diproses sebelumnya.');
            }
            if ($t->tahun->isDitutup()) {
                throw new DomainException('Tahun anggaran sudah ditutup.');
            }

            $nominal = BigDecimal::of($p->nominal);
            $sisa = BigDecimal::of($t->nominal)->minus($t->terbayar);

            if ($nominal->isGreaterThan($sisa)) {
                throw new DomainException('Nominal melebihi sisa tagihan saat ini. Tolak pembayaran ini.');
            }

            // 1. pembayaran terverifikasi + nomor kwitansi (id sudah diketahui)
            $p->update([
                'status' => Pembayaran::TERVERIFIKASI,
                'diverifikasi_oleh' => $user->id,
                'diverifikasi_pada' => now(),
                'nomor_kwitansi' => sprintf('KWT/%s/%06d', $p->tanggal_bayar->format('Y'), $p->id),
            ]);

            // 2. tagihan: terbayar dan status
            $t->update(['terbayar' => (string) BigDecimal::of($t->terbayar)->plus($nominal)->toScale(2)]);
            $this->segarkanStatus($t);

            // 3. penerimaan dana (FR-050)
            PenerimaanDana::create([
                'pembayaran_id' => $p->id,
                'sumber' => 'mahasiswa',
                'anggaran_id' => $t->anggaran_id,
                'tahun_anggaran_id' => $t->tahun_anggaran_id,
                'tanggal' => $p->tanggal_bayar,
                'nominal' => $p->nominal,
                'keterangan' => "Pembayaran {$t->nomor}",
                'dibuat_oleh' => $user->id,
            ]);

            // 4. jurnal otomatis; jika tidak balance, semuanya dibatalkan
            $this->jurnal->untukPembayaran($p, $t, $user);

            return $p->refresh();
        });
    }

    /** FR-042: tolak dengan alasan. */
    public function tolak(int $pembayaranId, string $alasan, User $user): Pembayaran
    {
        $tagihanId = Pembayaran::whereKey($pembayaranId)->value('tagihan_id')
            ?? throw new DomainException('Pembayaran tidak ditemukan.');

        return DB::transaction(function () use ($pembayaranId, $tagihanId, $alasan, $user) {
            $t = Tagihan::with('tahun')->lockForUpdate()->find($tagihanId)
                ?? throw new DomainException('Tagihan sudah dibatalkan.');
            $p = Pembayaran::lockForUpdate()->findOrFail($pembayaranId);

            if ($p->status !== Pembayaran::PENDING) {
                throw new DomainException('Pembayaran ini sudah diproses sebelumnya.');
            }
            if ($t->tahun->isDitutup()) {
                throw new DomainException('Tahun anggaran sudah ditutup.');
            }

            $p->update([
                'status' => Pembayaran::DITOLAK,
                'alasan_tolak' => $alasan,
                'diverifikasi_oleh' => $user->id,
                'diverifikasi_pada' => now(),
            ]);

            $this->segarkanStatus($t);

            return $p->refresh();
        });
    }

    /** Hitung ulang status tagihan dari data terkini. Harus dipanggil di dalam transaksi. */
    protected function segarkanStatus(Tagihan $t): void
    {
        $adaPending = Pembayaran::where('tagihan_id', $t->id)->where('status', Pembayaran::PENDING)->exists();
        $terbayar = BigDecimal::of($t->terbayar);

        $status = match (true) {
            $terbayar->isGreaterThanOrEqualTo($t->nominal) => Tagihan::LUNAS,
            $adaPending => Tagihan::PENDING,
            $terbayar->isGreaterThan(0) => Tagihan::SEBAGIAN,
            default => Tagihan::BELUM_BAYAR,
        };

        if ($t->status !== $status) {
            $t->update(['status' => $status]);
        }
    }
}
