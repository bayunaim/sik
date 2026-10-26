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
use Illuminate\Support\Facades\Cache;

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

    public const MAKS_MASSAL = 2000;

    /** Pratinjau: tidak menyimpan apa pun. */
    public function pratinjauMassal(array $p, array $mahasiswaIds, User $user): array
    {
        $r = $this->rencanaMassal($p, $mahasiswaIds, $user);

        $total = BigDecimal::zero();
        foreach ($r['siap'] as $s) {
            $total = $total->plus($s['nominal']);
        }

        return [
            'siap' => count($r['siap']),
            'total' => (string) $total->toScale(2),
            'jumlah_lewati' => count($r['lewati']),
            'ringkasan_alasan' => collect($r['lewati'])->countBy('alasan')->all(),
            'lewati' => $r['lewati'],
        ];
    }

    /** Terbitkan: semua berhasil atau semua batal. */
    public function buatMassal(array $p, array $mahasiswaIds, User $user): array
    {
        $kunciProses = Cache::lock("tagihan-massal:{$p['komponen_biaya_id']}:{$p['semester']}:{$p['tahun_ajaran']}", 120);

        if (! $kunciProses->get()) {
            throw new DomainException('Penerbitan untuk komponen dan periode ini sedang berjalan. Tunggu sebentar lalu coba lagi.');
        }

        try {
            return DB::transaction(function () use ($p, $mahasiswaIds, $user) {
                $r = $this->rencanaMassal($p, $mahasiswaIds, $user);

                if (empty($r['siap'])) {
                    throw new DomainException('Tidak ada tagihan yang bisa diterbitkan. Semua mahasiswa terpilih dilewati.');
                }

                $anggaran = $r['anggaran'];
                $now = now();
                $total = BigDecimal::zero();
                $baris = [];

                foreach ($r['siap'] as $s) {
                    $m = $s['mhs'];
                    $total = $total->plus($s['nominal']);
                    $baris[] = [
                        'mahasiswa_id' => $m->id,
                        'komponen_biaya_id' => (int) $p['komponen_biaya_id'],
                        'anggaran_id' => $anggaran->id,
                        'tahun_anggaran_id' => $anggaran->tahun_anggaran_id,
                        'semester' => $p['semester'],
                        'tahun_ajaran' => $p['tahun_ajaran'],
                        'nominal' => $s['nominal'],
                        'terbayar' => '0.00',
                        'tanggal_tagihan' => $p['tanggal_tagihan'],
                        'jatuh_tempo' => $p['jatuh_tempo'],
                        'metode_bayar' => ($p['metode_bayar'] ?? '') ?: null,
                        'keterangan' => ($p['keterangan'] ?? '') ?: null,
                        'status' => Tagihan::BELUM_BAYAR,
                        'kunci_unik' => implode('|', [$m->id, $p['komponen_biaya_id'], $p['semester'], $p['tahun_ajaran']]),
                        'dibuat_oleh' => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($baris, 500) as $potongan) {
                    DB::table('tagihan')->insert($potongan);
                }

                // Nomor otomatis. Di dalam transaksi ini, hanya baris kita yang belum bernomor.
                DB::table('tagihan')->whereNull('nomor')->update([
                    'nomor' => DB::raw("CONCAT('TGH/', YEAR(tanggal_tagihan), '/', LPAD(id, 6, '0'))"),
                ]);

                activity('tagihan')
                    ->causedBy($user)
                    ->withProperties([
                        'jumlah' => count($baris),
                        'total_nominal' => (string) $total->toScale(2),
                        'komponen_biaya_id' => (int) $p['komponen_biaya_id'],
                        'anggaran_id' => $anggaran->id,
                        'semester' => $p['semester'],
                        'tahun_ajaran' => $p['tahun_ajaran'],
                        'dilewati' => count($r['lewati']),
                    ])
                    ->log('tagihan massal diterbitkan');

                return [
                    'dibuat' => count($baris),
                    'total' => (string) $total->toScale(2),
                    'jumlah_lewati' => count($r['lewati']),
                    'ringkasan_alasan' => collect($r['lewati'])->countBy('alasan')->all(),
                    'lewati' => $r['lewati'],
                ];
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('Ada tagihan baru yang masuk saat proses berjalan. Seluruh batch dibatalkan, buat pratinjau ulang.');
        } finally {
            $kunciProses->release();
        }
    }

    /** Menentukan siapa yang siap ditagih dan siapa yang dilewati (beserta alasan). */
    protected function rencanaMassal(array $p, array $mahasiswaIds, User $user): array
    {
        $ids = array_values(array_unique(array_map('intval', $mahasiswaIds)));

        if (! $ids) {
            throw new DomainException('Pilih minimal satu mahasiswa.');
        }
        if (count($ids) > self::MAKS_MASSAL) {
            throw new DomainException('Maksimal '.number_format(self::MAKS_MASSAL, 0, ',', '.').' mahasiswa per batch.');
        }

        $komponen = KomponenBiaya::where('is_active', true)->find($p['komponen_biaya_id'])
            ?? throw new DomainException('Komponen biaya tidak ditemukan atau nonaktif.');
        $anggaran = Anggaran::with('tahun')->find($p['anggaran_id'])
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

        // Nominal manual hanya berlaku untuk yang berhak; selain itu pakai tarif per mahasiswa
        $manual = $p['nominal'] ?? null;
        $nominalManual = null;
        if ($manual !== null && $manual !== '' && $user->can('tagihan.ubah-nominal')) {
            $nominalManual = BigDecimal::of($manual)->toScale(2);
            if ($nominalManual->isLessThanOrEqualTo(0)) {
                throw new DomainException('Nominal tagihan harus lebih dari nol.');
            }
        }

        // Peta tarif dimuat sekali. Prioritas sama dengan TarifBiaya::cari():
        // (prodi+angkatan) > prodi saja > angkatan saja > umum
        $peta = [];
        if (! $nominalManual) {
            foreach (TarifBiaya::where('komponen_biaya_id', $komponen->id)->get() as $t) {
                $peta[($t->program_studi_id ?? '*').'|'.($t->angkatan ?? '*')] = $t->nominal;
            }
        }

        $sudahAda = Tagihan::where('komponen_biaya_id', $komponen->id)
            ->where('semester', $p['semester'])
            ->where('tahun_ajaran', $p['tahun_ajaran'])
            ->whereNotNull('kunci_unik')
            ->whereIn('mahasiswa_id', $ids)
            ->pluck('mahasiswa_id')
            ->flip();

        $daftar = Mahasiswa::whereIn('id', $ids)->orderBy('nim')->get();

        $siap = [];
        $lewati = [];

        foreach (array_diff($ids, $daftar->pluck('id')->all()) as $hilang) {
            $lewati[] = ['nim' => '-', 'nama' => "ID {$hilang}", 'alasan' => 'Mahasiswa tidak ditemukan'];
        }

        foreach ($daftar as $m) {
            $info = ['nim' => $m->nim, 'nama' => $m->nama];

            if ($sudahAda->has($m->id)) {
                $lewati[] = $info + ['alasan' => 'Sudah punya tagihan untuk komponen, semester, dan tahun ajaran ini'];
                continue;
            }

            $nominal = $nominalManual
                ? (string) $nominalManual
                : ($peta["{$m->program_studi_id}|{$m->angkatan}"]
                    ?? $peta["{$m->program_studi_id}|*"]
                    ?? $peta["*|{$m->angkatan}"]
                    ?? $peta['*|*']
                    ?? null);

            if ($nominal === null) {
                $lewati[] = $info + ['alasan' => 'Tarif belum diatur'];
                continue;
            }
            if (BigDecimal::of($nominal)->isLessThanOrEqualTo(0)) {
                $lewati[] = $info + ['alasan' => 'Tarif bernilai nol'];
                continue;
            }

            $siap[] = ['mhs' => $m, 'nominal' => (string) BigDecimal::of($nominal)->toScale(2)];
        }

        return ['anggaran' => $anggaran, 'siap' => $siap, 'lewati' => $lewati];
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
