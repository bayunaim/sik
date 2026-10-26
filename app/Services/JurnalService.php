<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Jurnal;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\TahunAnggaran;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use App\Models\PenerimaanDana;

class JurnalService
{
    public const AKUN_KAS = '1110';
    public const AKUN_BANK = '1120';

    /**
     * Buat jurnal. Menolak jika tidak balance.
     *
     * $h     : tanggal, tahun_anggaran_id, keterangan, jenis?, sumber_type?, sumber_id?,
     *          kunci_sumber?, jurnal_asal_id?
     * $baris : [['akun_id' => int, 'debit' => x, 'kredit' => y, 'keterangan' => ?], ...]
     */
    public function buat(array $h, array $baris, User $user): Jurnal
    {
        return DB::transaction(function () use ($h, $baris, $user) {
            if (count($baris) < 2) {
                throw new DomainException('Jurnal minimal terdiri dari dua baris.');
            }

            $tahun = TahunAnggaran::find($h['tahun_anggaran_id'] ?? null)
                ?? throw new DomainException('Tahun anggaran jurnal tidak ditemukan.');

            if ($tahun->isDitutup()) {
                throw new DomainException('Tahun anggaran sudah ditutup, jurnal tidak dapat dibuat.');
            }

            $akunAktif = ChartOfAccount::whereIn('id', collect($baris)->pluck('akun_id')->unique())
                ->where('is_active', true)
                ->pluck('id')
                ->flip();

            $totalDebit = BigDecimal::zero();
            $totalKredit = BigDecimal::zero();
            $detail = [];

            foreach ($baris as $b) {
                $debit = BigDecimal::of($b['debit'] ?? 0)->toScale(2);
                $kredit = BigDecimal::of($b['kredit'] ?? 0)->toScale(2);

                if ($debit->isNegative() || $kredit->isNegative()) {
                    throw new DomainException('Nilai debit/kredit tidak boleh negatif.');
                }
                // satu baris: salah satu saja yang terisi
                if ($debit->isZero() === $kredit->isZero()) {
                    throw new DomainException('Setiap baris jurnal harus berisi debit atau kredit (salah satu).');
                }
                if (! $akunAktif->has($b['akun_id'])) {
                    throw new DomainException('Akun jurnal tidak ditemukan atau nonaktif.');
                }

                $totalDebit = $totalDebit->plus($debit);
                $totalKredit = $totalKredit->plus($kredit);

                $detail[] = [
                    'chart_of_account_id' => $b['akun_id'],
                    'debit' => (string) $debit,
                    'kredit' => (string) $kredit,
                    'keterangan' => $b['keterangan'] ?? null,
                ];
            }

            // FR-071: total debit harus sama dengan total kredit
            if (! $totalDebit->isEqualTo($totalKredit)) {
                throw new DomainException("Jurnal tidak balance (debit {$totalDebit}, kredit {$totalKredit}). Transaksi ditolak.");
            }

            $tanggal = Carbon::parse($h['tanggal']);

            try {
                $jurnal = Jurnal::create([
                    'tanggal' => $tanggal->format('Y-m-d'),
                    'tahun_anggaran_id' => $tahun->id,
                    'jenis' => $h['jenis'] ?? Jurnal::OTOMATIS,
                    'keterangan' => $h['keterangan'],
                    'sumber_type' => $h['sumber_type'] ?? null,
                    'sumber_id' => $h['sumber_id'] ?? null,
                    'kunci_sumber' => $h['kunci_sumber'] ?? null,
                    'jurnal_asal_id' => $h['jurnal_asal_id'] ?? null,
                    'total_debit' => (string) $totalDebit,
                    'total_kredit' => (string) $totalKredit,
                    'dibuat_oleh' => $user->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('Jurnal untuk transaksi ini sudah pernah dibuat.');
            }

            $jurnal->detail()->createMany($detail);

            // lewat query builder karena model jurnal tidak boleh di-update
            DB::table('jurnal')->where('id', $jurnal->id)->update([
                'nomor' => sprintf('JRN/%s/%06d', $tanggal->format('Y'), $jurnal->id),
            ]);

            return $jurnal->refresh();
        });
    }

    /** Aturan dasar: pembayaran masuk = Debit Kas/Bank, Kredit Pendapatan (FR-074). */
    public function untukPembayaran(Pembayaran $p, Tagihan $t, User $user): Jurnal
    {
        $kodeKas = $p->metode === 'Tunai' ? self::AKUN_KAS : self::AKUN_BANK;

        $akunKas = ChartOfAccount::where('kode', $kodeKas)->where('is_active', true)->value('id')
            ?? throw new DomainException("Akun {$kodeKas} (Kas/Bank) belum tersedia di Chart of Accounts.");

        $akunPendapatan = $t->komponen->akun_pendapatan_id
            ?? throw new DomainException('Komponen biaya belum punya akun pendapatan.');

        $ket = "Pembayaran {$t->nomor} ({$t->komponen->nama})";

        return $this->buat([
            'tanggal' => $p->tanggal_bayar,
            'tahun_anggaran_id' => $t->tahun_anggaran_id,
            'keterangan' => $ket,
            'jenis' => Jurnal::OTOMATIS,
            'sumber_type' => Pembayaran::class,
            'sumber_id' => $p->id,
            'kunci_sumber' => 'pembayaran:'.$p->id,
        ], [
            ['akun_id' => $akunKas, 'debit' => $p->nominal, 'kredit' => 0, 'keterangan' => $ket],
            ['akun_id' => $akunPendapatan, 'debit' => 0, 'kredit' => $p->nominal, 'keterangan' => $ket],
        ], $user);
    }

    /** Jurnal pembalik: debit dan kredit ditukar. Satu jurnal hanya bisa dibalik sekali (FR-072). */
    public function balik(Jurnal $asal, string $alasan, User $user): Jurnal
    {
        return DB::transaction(function () use ($asal, $alasan, $user) {
            $a = Jurnal::with('detail')->lockForUpdate()->findOrFail($asal->id);

            if ($a->jenis === Jurnal::PEMBALIK) {
                throw new DomainException('Jurnal pembalik tidak dapat dibalik lagi.');
            }
            if (Jurnal::where('jurnal_asal_id', $a->id)->exists()) {
                throw new DomainException('Jurnal ini sudah pernah dibalik.');
            }

            $baris = $a->detail->map(fn ($d) => [
                'akun_id' => $d->chart_of_account_id,
                'debit' => $d->kredit,
                'kredit' => $d->debit,
                'keterangan' => $d->keterangan,
            ])->all();

            return $this->buat([
                'tanggal' => now()->format('Y-m-d'),
                'tahun_anggaran_id' => $a->tahun_anggaran_id,
                'keterangan' => "Pembalik {$a->nomor}: {$alasan}",
                'jenis' => Jurnal::PEMBALIK,
                'sumber_type' => $a->sumber_type,
                'sumber_id' => $a->sumber_id,
                'jurnal_asal_id' => $a->id,
            ], $baris, $user);
        });
    }

    /** Penerimaan non-mahasiswa: Debit Kas/Bank, Kredit Hibah/Pendapatan lain. */
    public function untukPenerimaan(PenerimaanDana $p, string $metode, User $user): Jurnal
    {
        $kodeKas = $metode === 'Tunai' ? self::AKUN_KAS : self::AKUN_BANK;
        $kodePendapatan = in_array($p->sumber, ['hibah', 'donasi'], true) ? '4210' : '4290';

        $akunKas = ChartOfAccount::where('kode', $kodeKas)->where('is_active', true)->value('id')
            ?? throw new DomainException("Akun {$kodeKas} (Kas/Bank) belum tersedia.");
        $akunPendapatan = ChartOfAccount::where('kode', $kodePendapatan)->where('is_active', true)->value('id')
            ?? throw new DomainException("Akun {$kodePendapatan} belum tersedia di Chart of Accounts.");

        $ket = 'Penerimaan '.ucfirst($p->sumber).($p->keterangan ? ": {$p->keterangan}" : '');

        return $this->buat([
            'tanggal' => $p->tanggal,
            'tahun_anggaran_id' => $p->tahun_anggaran_id,
            'keterangan' => $ket,
            'jenis' => Jurnal::OTOMATIS,
            'sumber_type' => PenerimaanDana::class,
            'sumber_id' => $p->id,
            'kunci_sumber' => 'penerimaan:'.$p->id,
        ], [
            ['akun_id' => $akunKas, 'debit' => $p->nominal, 'kredit' => 0, 'keterangan' => $ket],
            ['akun_id' => $akunPendapatan, 'debit' => 0, 'kredit' => $p->nominal, 'keterangan' => $ket],
        ], $user);
    }
}
