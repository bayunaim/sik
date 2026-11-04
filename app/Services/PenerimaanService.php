<?php

namespace App\Services;

use App\Models\Anggaran;
use App\Models\Jurnal;
use App\Models\PenerimaanDana;
use App\Models\TahunAnggaran;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class PenerimaanService
{
    public function __construct(private JurnalService $jurnal)
    {
    }

    /**
     * FR-051: penerimaan non-mahasiswa. Penerimaan dan jurnal dibuat atomik.
     * $d: sumber, anggaran_id, tanggal, nominal, metode, keterangan?
     */
    public function catatManual(array $d, User $user): PenerimaanDana
    {
        return DB::transaction(function () use ($d, $user) {
            $anggaran = Anggaran::with('tahun')->lockForUpdate()->find($d['anggaran_id'])
                ?? throw new DomainException('Kode anggaran tidak ditemukan.');

            if ($anggaran->tipe !== 'D') {
                throw new DomainException('Penerimaan hanya bisa memakai kode anggaran penerimaan (D).');
            }
            if (! $anggaran->isApproved()) {
                throw new DomainException('Kode anggaran belum disetujui (masih Draft).');
            }
            if ($anggaran->tahun->isDitutup()) {
                throw new DomainException('Tahun anggaran sudah ditutup.');
            }

            $nominal = BigDecimal::of($d['nominal'])->toScale(2);
            if ($nominal->isLessThanOrEqualTo(0)) {
                throw new DomainException('Nominal harus lebih dari nol.');
            }

            $p = PenerimaanDana::create([
                'pembayaran_id' => null,
                'sumber' => $d['sumber'],
                'anggaran_id' => $anggaran->id,
                'tahun_anggaran_id' => $anggaran->tahun_anggaran_id,
                'tanggal' => $d['tanggal'],
                'nominal' => (string) $nominal,
                'metode' => $d['metode'],
                'keterangan' => ($d['keterangan'] ?? '') ?: null,
                'dibuat_oleh' => $user->id,
            ]);

            // jika jurnal gagal (misal tidak balance), penerimaan ikut dibatalkan
            $this->jurnal->untukPenerimaan($p, $d['metode'], $user);

            return $p;
        });
    }

    /**
     * Batalkan penerimaan non-mahasiswa (misalnya salah input). Atomik: penerimaan dihapus (soft delete,
     * alasan tersimpan) dan jurnalnya dibalik. Penerimaan dari pembayaran mahasiswa dibatalkan lewat
     * PembayaranService::batalkan(), bukan di sini.
     */
    public function batalkan(int $id, string $alasan, User $user): void
    {
        $alasan = trim($alasan);
        if ($alasan === '') {
            throw new DomainException('Alasan pembatalan wajib diisi.');
        }

        DB::transaction(function () use ($id, $alasan, $user) {
            $p = PenerimaanDana::lockForUpdate()->find($id)
                ?? throw new DomainException('Penerimaan tidak ditemukan atau sudah dibatalkan.');

            if (! $user->can('penerimaan.input')) {
                throw new DomainException('Anda tidak punya hak untuk membatalkan penerimaan.');
            }
            if ($p->pembayaran_id !== null) {
                throw new DomainException('Penerimaan ini berasal dari pembayaran mahasiswa. Batalkan lewat modul pembayaran.');
            }

            $tahun = TahunAnggaran::find($p->tahun_anggaran_id);
            if (! $tahun || $tahun->isDitutup()) {
                throw new DomainException('Tahun anggaran sudah ditutup.');
            }

            $jurnal = Jurnal::where('kunci_sumber', 'penerimaan:'.$p->id)->first()
                ?? throw new DomainException('Jurnal penerimaan ini tidak ditemukan. Pembatalan dihentikan.');

            $p->update(['alasan_batal' => $alasan, 'dibatalkan_oleh' => $user->id]);
            $p->delete();

            $this->jurnal->balik($jurnal, "Pembatalan penerimaan: {$alasan}", $user, true);
        });
    }
}
