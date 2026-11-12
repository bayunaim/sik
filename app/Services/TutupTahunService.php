<?php

namespace App\Services;

use App\Models\Anggaran;
use App\Models\Pembayaran;
use App\Models\Pengeluaran;
use App\Models\Tagihan;
use App\Models\TahunAnggaran;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * FR-120 dan FR-121: Tutup Tahun.
 *
 * Satu transaksi atomik:
 *  1. Anggaran tahun ini disalin ke tahun berikutnya sebagai Draft (kode yang sudah ada tidak digandakan).
 *  2. Tagihan belum lunas dipindah ke tahun berikutnya (anggaran dipetakan berdasarkan kode)
 *     dan ditandai carry over, supaya masih bisa dibayar. Tagihan lunas dan yang dibatalkan tetap di tahun lama.
 *  3. Tahun ini menjadi Ditutup (read-only), tahun berikutnya menjadi Aktif.
 *
 * Syarat: tahun berstatus Aktif, tidak ada pembayaran atau pengeluaran yang masih Pending.
 */
class TutupTahunService
{
    /** Ringkasan dampak untuk pratinjau (FR-121). Tidak mengubah data. */
    public function pratinjau(TahunAnggaran $tahun): array
    {
        return $this->hitung($tahun);
    }

    /** @return array<string, mixed> ringkasan hasil */
    public function tutup(int $tahunId, User $user): array
    {
        if (! $user->can('tahun.tutup')) {
            throw new DomainException('Anda tidak punya hak untuk menutup tahun anggaran.');
        }

        return DB::transaction(function () use ($tahunId, $user) {
            $tahun = TahunAnggaran::lockForUpdate()->find($tahunId)
                ?? throw new DomainException('Tahun anggaran tidak ditemukan.');

            // Dihitung ulang setelah lock, bukan memakai pratinjau lama
            $r = $this->hitung($tahun);
            if ($r['hambatan'] !== []) {
                throw new DomainException(implode(' ', $r['hambatan']));
            }

            $berikut = $this->tahunBerikutnya($tahun) ?? TahunAnggaran::create([
                'nama' => $r['tahun_berikut'],
                'tanggal_mulai' => $tahun->tanggal_mulai->copy()->addYear()->toDateString(),
                'tanggal_selesai' => $tahun->tanggal_selesai->copy()->addYear()->toDateString(),
                'status' => TahunAnggaran::DRAFT,
            ]);

            $peta = $this->salinAnggaran($tahun, $berikut, $user);
            $this->bawaTagihan($tahun, $berikut, $peta);

            $tahun->update(['status' => TahunAnggaran::DITUTUP]);
            $berikut->update(['status' => TahunAnggaran::AKTIF]);

            activity('tahun_anggaran')
                ->performedOn($tahun)
                ->causedBy($user)
                ->withProperties([
                    'tahun_berikut' => $berikut->nama,
                    'anggaran_disalin' => $r['anggaran_jumlah'],
                    'tagihan_carry_over' => $r['tagihan_jumlah'],
                    'sisa_carry_over' => $r['tagihan_sisa'],
                ])
                ->log('tahun anggaran ditutup');

            return $r + ['tahun_berikut_id' => $berikut->id];
        });
    }

    /**
     * Hitung dampak dan daftar hambatan. Dipakai pratinjau dan tutup() sehingga aturannya satu sumber.
     */
    protected function hitung(TahunAnggaran $tahun): array
    {
        $hambatan = [];

        if ($tahun->status !== TahunAnggaran::AKTIF) {
            $hambatan[] = 'Hanya tahun anggaran yang berstatus Aktif yang bisa ditutup.';
        }

        // Tahun berikutnya: pakai yang sudah ada (harus Draft) atau dibuat otomatis
        $namaBerikut = is_numeric($tahun->nama) ? (string) ((int) $tahun->nama + 1) : null;
        $berikut = $this->tahunBerikutnya($tahun);
        $berikutBaru = false;

        if ($berikut) {
            $namaBerikut = $berikut->nama;
            if ($berikut->status !== TahunAnggaran::DRAFT) {
                $hambatan[] = "Tahun anggaran berikutnya ({$berikut->nama}) berstatus {$berikut->status}; harus Draft.";
            }
        } elseif ($namaBerikut === null) {
            $hambatan[] = 'Nama tahun anggaran bukan angka. Buat dulu tahun anggaran berikutnya (status Draft) secara manual.';
        } else {
            $berikutBaru = true;
            $mulai = $tahun->tanggal_mulai->copy()->addYear();
            $selesai = $tahun->tanggal_selesai->copy()->addYear();
            $bentrok = TahunAnggaran::where('tanggal_mulai', '<=', $selesai)
                ->where('tanggal_selesai', '>=', $mulai)->first();
            if ($bentrok) {
                $hambatan[] = "Periode tahun berikutnya bentrok dengan tahun anggaran {$bentrok->nama}.";
            }
        }

        $belumLunas = Tagihan::query()
            ->where('tahun_anggaran_id', $tahun->id)
            ->where('status', '!=', Tagihan::LUNAS);

        $idAnggaranTagihan = (clone $belumLunas)->distinct()->pluck('anggaran_id');
        $anggaran = $this->anggaranUntukDisalin($tahun, $idAnggaranTagihan);

        $tidakAda = $idAnggaranTagihan->diff($anggaran->pluck('id'));
        if ($tidakAda->isNotEmpty()) {
            $hambatan[] = 'Ada tagihan yang memakai kode anggaran dari tahun lain. Data tidak konsisten, periksa dulu.';
        }

        $ringkas = (clone $belumLunas)
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(nominal - terbayar), 0) as total_sisa')
            ->first();

        $pembayaranPending = Pembayaran::where('status', Pembayaran::PENDING)
            ->whereHas('tagihan', fn ($q) => $q->where('tahun_anggaran_id', $tahun->id))
            ->count();
        if ($pembayaranPending > 0) {
            $hambatan[] = "Masih ada {$pembayaranPending} pembayaran yang menunggu verifikasi. Verifikasi atau tolak dulu.";
        }

        $pengeluaranPending = Pengeluaran::where('tahun_anggaran_id', $tahun->id)
            ->where('status', Pengeluaran::PENDING)->count();
        if ($pengeluaranPending > 0) {
            $hambatan[] = "Masih ada {$pengeluaranPending} pengeluaran yang menunggu persetujuan. Setujui, tolak, atau batalkan dulu.";
        }

        return [
            'tahun' => $tahun->nama,
            'tahun_berikut' => $namaBerikut,
            'tahun_berikut_baru' => $berikutBaru,
            'anggaran_jumlah' => $anggaran->count(),
            'anggaran_d' => $anggaran->where('tipe', 'D')->count(),
            'anggaran_k' => $anggaran->where('tipe', 'K')->count(),
            'tagihan_jumlah' => (int) $ringkas->jumlah,
            'tagihan_sisa' => (string) BigDecimal::of((string) $ringkas->total_sisa)->toScale(2),
            'pembayaran_pending' => $pembayaranPending,
            'pengeluaran_pending' => $pengeluaranPending,
            'hambatan' => $hambatan,
        ];
    }

    /** Tahun berikutnya yang sudah ada, atau null. Nama angka: nama + 1; selain itu Draft terdekat setelahnya. */
    protected function tahunBerikutnya(TahunAnggaran $tahun): ?TahunAnggaran
    {
        if (is_numeric($tahun->nama)) {
            return TahunAnggaran::where('nama', (string) ((int) $tahun->nama + 1))->first();
        }

        return TahunAnggaran::where('status', TahunAnggaran::DRAFT)
            ->where('tanggal_mulai', '>', $tahun->tanggal_selesai)
            ->orderBy('tanggal_mulai')->first();
    }

    /** Anggaran yang aktif, ditambah yang sudah dihapus tapi masih dipakai tagihan belum lunas. */
    protected function anggaranUntukDisalin(TahunAnggaran $tahun, $idDipakaiTagihan)
    {
        return Anggaran::withTrashed()
            ->where('tahun_anggaran_id', $tahun->id)
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhereIn('id', $idDipakaiTagihan))
            ->orderBy('kode')
            ->get();
    }

    /** @return array<int,int> id anggaran lama => id anggaran baru */
    protected function salinAnggaran(TahunAnggaran $lama, TahunAnggaran $baru, User $user): array
    {
        $idDipakai = Tagihan::where('tahun_anggaran_id', $lama->id)
            ->where('status', '!=', Tagihan::LUNAS)->distinct()->pluck('anggaran_id');

        $peta = [];
        foreach ($this->anggaranUntukDisalin($lama, $idDipakai) as $a) {
            $ada = Anggaran::withTrashed()
                ->where('tahun_anggaran_id', $baru->id)->where('kode', $a->kode)->first();

            if ($ada) {
                if ($ada->trashed()) {
                    $ada->restore();
                }
                $peta[$a->id] = $ada->id;
                continue;
            }

            $peta[$a->id] = Anggaran::create([
                'tahun_anggaran_id' => $baru->id,
                'kode' => $a->kode,
                'tipe' => $a->tipe,
                'nama' => $a->nama,
                'sub_anggaran' => $a->sub_anggaran,
                'jumlah' => $a->jumlah,
                'status' => Anggaran::DRAFT,
                'catatan' => $a->catatan,
                'dibuat_oleh' => $user->id,
            ])->id;
        }

        return $peta;
    }

    /** Pindahkan tagihan belum lunas per kode anggaran (satu UPDATE per kode, bukan per baris). */
    protected function bawaTagihan(TahunAnggaran $lama, TahunAnggaran $baru, array $peta): void
    {
        foreach ($peta as $idLama => $idBaru) {
            Tagihan::query()
                ->where('tahun_anggaran_id', $lama->id)
                ->where('anggaran_id', $idLama)
                ->where('status', '!=', Tagihan::LUNAS)
                ->update([
                    'tahun_anggaran_id' => $baru->id,
                    'anggaran_id' => $idBaru,
                    'carry_over' => true,
                    'tahun_asal_id' => $lama->id,
                    'carry_over_pada' => now(),
                ]);
        }
    }
}
