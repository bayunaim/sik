<?php

namespace App\Services;

use App\Models\Anggaran;
use App\Models\ChartOfAccount;
use App\Models\Pengeluaran;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class PengeluaranService
{
    public function __construct(private JurnalService $jurnal)
    {
    }

    /** Total pengeluaran yang sudah disetujui pada satu kode anggaran. */
    public function terpakai(int $anggaranId): BigDecimal
    {
        $total = Pengeluaran::where('anggaran_id', $anggaranId)
            ->where('status', Pengeluaran::DISETUJUI)
            ->sum('nominal');

        return BigDecimal::of((string) ($total ?? 0))->toScale(2);
    }

    /** Sisa anggaran = jumlah - terpakai. Bisa negatif jika sudah terlanjur melebihi. */
    public function sisaAnggaran(Anggaran $a): BigDecimal
    {
        return BigDecimal::of($a->jumlah)->minus($this->terpakai($a->id))->toScale(2);
    }

    /** FR-060: ajukan pengeluaran, status awal Pending. */
    public function ajukan(array $d, User $user, ?string $lampiranPath = null): Pengeluaran
    {
        return DB::transaction(function () use ($d, $user, $lampiranPath) {
            $anggaran = Anggaran::with('tahun')->find($d['anggaran_id'])
                ?? throw new DomainException('Kode anggaran tidak ditemukan.');

            if ($anggaran->tipe !== 'K') {
                throw new DomainException('Pengeluaran hanya bisa memakai kode anggaran pengeluaran (K).');
            }
            if (! $anggaran->isApproved()) {
                throw new DomainException('Kode anggaran belum disetujui (masih Draft).');
            }
            if ($anggaran->tahun->isDitutup()) {
                throw new DomainException('Tahun anggaran sudah ditutup.');
            }

            $akun = ChartOfAccount::where('is_active', true)->find($d['akun_beban_id'])
                ?? throw new DomainException('Akun beban tidak ditemukan atau nonaktif.');

            if ($akun->tipe !== 'beban') {
                throw new DomainException('Akun yang dipilih harus bertipe Beban.');
            }
            if ($akun->children()->exists()) {
                throw new DomainException('Pilih akun beban rinci, bukan akun induk.');
            }

            $nominal = BigDecimal::of($d['nominal'])->toScale(2);
            if ($nominal->isLessThanOrEqualTo(0)) {
                throw new DomainException('Nominal harus lebih dari nol.');
            }

            $p = Pengeluaran::create([
                'tanggal' => $d['tanggal'],
                'anggaran_id' => $anggaran->id,
                'tahun_anggaran_id' => $anggaran->tahun_anggaran_id,
                'akun_beban_id' => $akun->id,
                'keperluan' => trim($d['keperluan']),
                'nominal' => (string) $nominal,
                'metode' => $d['metode'],
                'lampiran_path' => $lampiranPath,
                'status' => Pengeluaran::PENDING,
                'diajukan_oleh' => $user->id,
            ]);

            $p->update(['nomor' => sprintf('PNG/%s/%06d', $p->tanggal->format('Y'), $p->id)]);

            return $p;
        });
    }

    /**
     * Alasan kenapa user ini TIDAK boleh menyetujui, atau null jika boleh.
     * Dipakai UI (untuk menampilkan tombol) dan dicek ulang di setujui().
     */
    public function alasanTidakBisaSetujui(Pengeluaran $p, User $user): ?string
    {
        if (! $user->can('pengeluaran.approve')) {
            return 'Anda tidak punya hak approval.';
        }
        if ($p->status !== Pengeluaran::PENDING) {
            return 'Pengeluaran ini sudah diproses.';
        }
        // FR-064: berlaku untuk semua, termasuk SuperAdmin
        if ($p->diajukan_oleh === $user->id) {
            return 'Anda tidak boleh menyetujui pengajuan sendiri.';
        }
        // FR-062: SuperAdmin tidak terbatas
        if ($user->hasRole('SuperAdmin')) {
            return null;
        }
        if ($user->batas_persetujuan === null) {
            return 'Anda belum punya batas persetujuan nominal. Hubungi SuperAdmin.';
        }
        if (BigDecimal::of($p->nominal)->isGreaterThan($user->batas_persetujuan)) {
            return 'Nominal melebihi batas persetujuan Anda (Rp '
                .number_format((float) $user->batas_persetujuan, 0, ',', '.').').';
        }

        return null;
    }

    /**
     * FR-063: setujui secara atomik.
     * @return array{pengeluaran: Pengeluaran, melebihi: bool}
     */
    public function setujui(int $id, User $user): array
    {
        return DB::transaction(function () use ($id, $user) {
            $p = Pengeluaran::with(['tahun', 'anggaran'])->lockForUpdate()->find($id)
                ?? throw new DomainException('Pengeluaran tidak ditemukan atau sudah dibatalkan.');

            if ($alasan = $this->alasanTidakBisaSetujui($p, $user)) {
                throw new DomainException($alasan);
            }
            if ($p->tahun->isDitutup()) {
                throw new DomainException('Tahun anggaran sudah ditutup.');
            }
            if (! $p->anggaran->isApproved()) {
                throw new DomainException('Kode anggaran tidak lagi berstatus Approved.');
            }

            // FR-024: hanya peringatan, dicatat di audit log
            $sisa = $this->sisaAnggaran($p->anggaran);
            $melebihi = BigDecimal::of($p->nominal)->isGreaterThan($sisa);

            $p->update([
                'status' => Pengeluaran::DISETUJUI,
                'diproses_oleh' => $user->id,
                'diproses_pada' => now(),
            ]);

            // jika jurnal gagal (misal akun Kas/Bank tidak ada), semuanya dibatalkan
            $this->jurnal->untukPengeluaran($p, $user);

            if ($melebihi) {
                activity('pengeluaran')
                    ->performedOn($p)
                    ->causedBy($user)
                    ->withProperties([
                        'kode_anggaran' => $p->anggaran->kode,
                        'sisa_sebelumnya' => (string) $sisa,
                        'nominal' => (string) $p->nominal,
                    ])
                    ->log('disetujui melebihi sisa anggaran');
            }

            return ['pengeluaran' => $p->refresh(), 'melebihi' => $melebihi];
        });
    }

    /** Tolak dengan alasan. Aturan wewenang: punya hak approval dan bukan pengajunya. */
    public function tolak(int $id, string $alasan, User $user): Pengeluaran
    {
        return DB::transaction(function () use ($id, $alasan, $user) {
            $p = Pengeluaran::with('tahun')->lockForUpdate()->find($id)
                ?? throw new DomainException('Pengeluaran tidak ditemukan atau sudah dibatalkan.');

            if (! $user->can('pengeluaran.approve')) {
                throw new DomainException('Anda tidak punya hak approval.');
            }
            if ($p->status !== Pengeluaran::PENDING) {
                throw new DomainException('Pengeluaran ini sudah diproses.');
            }
            if ($p->diajukan_oleh === $user->id) {
                throw new DomainException('Anda tidak boleh memproses pengajuan sendiri. Gunakan Batalkan.');
            }
            if ($p->tahun->isDitutup()) {
                throw new DomainException('Tahun anggaran sudah ditutup.');
            }

            $p->update([
                'status' => Pengeluaran::DITOLAK,
                'alasan_tolak' => $alasan,
                'diproses_oleh' => $user->id,
                'diproses_pada' => now(),
            ]);

            return $p->refresh();
        });
    }

    /** Pengaju membatalkan pengajuannya sendiri selama masih Pending (soft delete). */
    public function batalkan(int $id, User $user): void
    {
        DB::transaction(function () use ($id, $user) {
            $p = Pengeluaran::lockForUpdate()->find($id)
                ?? throw new DomainException('Pengeluaran tidak ditemukan.');

            if ($p->diajukan_oleh !== $user->id) {
                throw new DomainException('Hanya pengaju yang bisa membatalkan pengajuan ini.');
            }
            if ($p->status !== Pengeluaran::PENDING) {
                throw new DomainException('Hanya pengajuan Pending yang bisa dibatalkan.');
            }

            $p->delete();
        });
    }
}
