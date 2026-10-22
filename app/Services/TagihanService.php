<?php

namespace App\Services;

use App\Models\Anggaran;
use App\Models\KomponenBiaya;
use App\Models\Mahasiswa;
use App\Models\Tagihan;
use App\Models\TarifBiaya;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class TagihanService
{
    /**
     * Buat satu tagihan. Semua pengecekan dan insert dalam satu transaksi.
     *
     * $data: mahasiswa_id, komponen_biaya_id, anggaran_id, semester, tahun_ajaran,
     *        nominal (opsional), tanggal_tagihan, jatuh_tempo, metode_bayar, keterangan
     */
    public function buat(array $data, User $user): Tagihan
    {
        return DB::transaction(function () use ($data, $user) {
            $mhs = Mahasiswa::findOrFail($data['mahasiswa_id']);
            $komponen = KomponenBiaya::where('is_active', true)->find($data['komponen_biaya_id'])
                ?? throw new DomainException('Komponen biaya tidak ditemukan atau nonaktif.');
            $anggaran = Anggaran::with('tahun')->find($data['anggaran_id'])
                ?? throw new DomainException('Kode anggaran tidak ditemukan.');

            if ($anggaran->tipe !== 'D') {
                throw new DomainException('Tagihan hanya bisa memakai kode anggaran penerimaan (D).');
            }
            if (! $anggaran->isApproved()) {
                throw new DomainException('Kode anggaran belum disetujui (masih Draft).');
            }
            if ($anggaran->tahun->isDitutup()) {
                throw new DomainException('Tahun anggaran untuk kode ini sudah ditutup.');
            }

            $nominal = $this->tentukanNominal($data, $mhs, $komponen, $user);

            $kunci = implode('|', [$mhs->id, $komponen->id, $data['semester'], $data['tahun_ajaran']]);

            if (Tagihan::where('kunci_unik', $kunci)->exists()) {
                throw new DomainException('Mahasiswa ini sudah punya tagihan untuk komponen, semester, dan tahun ajaran yang sama.');
            }

            try {
                $tagihan = Tagihan::create([
                    'mahasiswa_id' => $mhs->id,
                    'komponen_biaya_id' => $komponen->id,
                    'anggaran_id' => $anggaran->id,
                    'tahun_anggaran_id' => $anggaran->tahun_anggaran_id,
                    'semester' => $data['semester'],
                    'tahun_ajaran' => $data['tahun_ajaran'],
                    'nominal' => $nominal,
                    'terbayar' => '0.00',
                    'tanggal_tagihan' => $data['tanggal_tagihan'],
                    'jatuh_tempo' => $data['jatuh_tempo'],
                    'metode_bayar' => $data['metode_bayar'] ?? null,
                    'keterangan' => $data['keterangan'] ?? null,
                    'status' => Tagihan::BELUM_BAYAR,
                    'kunci_unik' => $kunci,
                    'dibuat_oleh' => $user->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // klik ganda / dua proses bersamaan: unique index di database yang menang
                throw new DomainException('Tagihan yang sama baru saja dibuat. Muat ulang halaman.');
            }

            $tagihan->update([
                'nomor' => sprintf('TGH/%s/%06d', $tagihan->tanggal_tagihan->format('Y'), $tagihan->id),
            ]);

            return $tagihan;
        });
    }

    /** Pratinjau tarif untuk form (tanpa menyimpan apa pun). */
    public function tarifUntuk(int $mahasiswaId, int $komponenId): ?string
    {
        $mhs = Mahasiswa::find($mahasiswaId);
        if (! $mhs) {
            return null;
        }

        return TarifBiaya::cari($komponenId, $mhs->program_studi_id, $mhs->angkatan)?->nominal;
    }

    public function batalkan(Tagihan $tagihan, string $alasan, User $user): void
    {
        DB::transaction(function () use ($tagihan, $alasan, $user) {
            $t = Tagihan::with('tahun')->lockForUpdate()->findOrFail($tagihan->id);

            if ($t->tahun->isDitutup()) {
                throw new DomainException('Tahun anggaran sudah ditutup, tagihan tidak dapat diubah.');
            }

            if (! BigDecimal::of($t->terbayar)->isZero() || $t->status !== Tagihan::BELUM_BAYAR) {
                throw new DomainException('Tagihan sudah ada pembayaran. Koreksi lewat modul pembayaran, bukan pembatalan.');
            }

            // Fase pembayaran: tambahkan juga cek "ada pembayaran Pending" di sini.

            $t->update([
                'alasan_batal' => $alasan,
                'dibatalkan_pada' => now(),
                'dibatalkan_oleh' => $user->id,
                'kunci_unik' => null, // lepaskan kunci agar kombinasi ini bisa ditagih ulang
            ]);
            $t->delete();
        });
    }

    protected function tentukanNominal(array $data, Mahasiswa $mhs, KomponenBiaya $komponen, User $user): string
    {
        $manual = $data['nominal'] ?? null;

        if ($manual !== null && $manual !== '' && $user->can('tagihan.ubah-nominal')) {
            $nominal = BigDecimal::of($manual)->toScale(2);
        } else {
            $tarif = TarifBiaya::cari($komponen->id, $mhs->program_studi_id, $mhs->angkatan);

            if (! $tarif) {
                throw new DomainException("Tarif {$komponen->nama} belum diatur untuk mahasiswa ini. Hubungi Admin Keuangan.");
            }

            $nominal = BigDecimal::of($tarif->nominal)->toScale(2);
        }

        if ($nominal->isLessThanOrEqualTo(0)) {
            throw new DomainException('Nominal tagihan harus lebih dari nol.');
        }

        return (string) $nominal;
    }
}
