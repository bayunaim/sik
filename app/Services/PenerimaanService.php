<?php

namespace App\Services;

use App\Models\Anggaran;
use App\Models\PenerimaanDana;
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
}
