<?php

namespace App\Services;

use App\Models\MutasiBank;
use App\Models\Pembayaran;
use App\Models\PenerimaanDana;
use App\Models\Pengeluaran;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FR-081 dan FR-082: pencocokan mutasi bank dengan transaksi sistem.
 *
 * - Uang masuk (kredit)  : pembayaran mahasiswa terverifikasi, atau penerimaan dana non-mahasiswa.
 * - Uang keluar (debit)  : pengeluaran disetujui.
 * - Hanya transaksi non-Tunai yang bisa dicocokkan (sama seperti aturan jurnal: selain Tunai masuk akun Bank).
 * - Nominal harus sama persis. Selisih (biaya admin, bunga) ditangani dengan status Diabaikan.
 * - Pencocokan hanya penanda rekonsiliasi: tidak mengubah tagihan, penerimaan, pengeluaran, maupun jurnal.
 */
class RekonsiliasiService
{
    /** Selisih tanggal maksimum (hari) antara mutasi dan transaksi untuk pencocokan otomatis. */
    public const JENDELA_HARI = 3;

    private const TANGGAL = [
        Pembayaran::class => 'tanggal_bayar',
        PenerimaanDana::class => 'tanggal',
        Pengeluaran::class => 'tanggal',
    ];

    /** Transaksi yang layak dicocokkan dan belum dipakai mutasi mana pun. */
    protected function dasar(string $kelas): Builder
    {
        $q = $kelas::query()
            ->whereNotIn('id', MutasiBank::where('cocok_type', $kelas)->whereNotNull('cocok_id')->select('cocok_id'))
            ->where(fn ($w) => $w->whereNull('metode')->orWhere('metode', '!=', 'Tunai'));

        return match ($kelas) {
            Pembayaran::class => $q->where('status', Pembayaran::TERVERIFIKASI),
            PenerimaanDana::class => $q->whereNull('pembayaran_id'),
            Pengeluaran::class => $q->where('status', Pengeluaran::DISETUJUI),
        };
    }

    /**
     * Muat transaksi sistem yang belum dicocokkan untuk satu arah.
     * @return Collection<int, array{type:string,id:int,tanggal:Carbon,nominal:string,label:string,kata:array}>
     */
    public function muat(string $arah, ?string $dari = null, ?string $sampai = null, ?string $nominal = null): Collection
    {
        $hasil = collect();

        foreach ($arah === 'masuk' ? [Pembayaran::class, PenerimaanDana::class] : [Pengeluaran::class] as $kelas) {
            $kol = self::TANGGAL[$kelas];
            $q = $this->dasar($kelas)
                ->when($dari, fn ($q) => $q->whereDate($kol, '>=', $dari))
                ->when($sampai, fn ($q) => $q->whereDate($kol, '<=', $sampai))
                ->when($nominal !== null, fn ($q) => $q->where('nominal', $nominal));

            if ($kelas === Pembayaran::class) {
                $q->with('mahasiswa');
            }

            foreach ($q->get() as $t) {
                $hasil->push($this->item($t));
            }
        }

        return $hasil;
    }

    public function label(Model $t): string
    {
        return match (true) {
            $t instanceof Pembayaran => 'Pembayaran '.($t->nomor_kwitansi ?? '#'.$t->id).' - '.($t->mahasiswa?->nama ?? '?').' ('.($t->mahasiswa?->nim ?? '?').')',
            $t instanceof PenerimaanDana => 'Penerimaan '.ucfirst($t->sumber).($t->keterangan ? ': '.$t->keterangan : ''),
            $t instanceof Pengeluaran => "Pengeluaran {$t->nomor}: {$t->keperluan}",
            default => class_basename($t).' #'.$t->getKey(),
        };
    }

    protected function item(Model $t): array
    {
        $kata = match (true) {
            $t instanceof Pembayaran => [$t->referensi, $t->mahasiswa?->nim, $t->nomor_kwitansi],
            $t instanceof Pengeluaran => [$t->nomor],
            default => [],
        };
        $tanggal = $t instanceof Pembayaran ? $t->tanggal_bayar : $t->tanggal;

        return [
            'type' => $t::class,
            'id' => $t->id,
            'tanggal' => $tanggal,
            'nominal' => (string) $t->nominal,
            'label' => $this->label($t),
            // kata kunci yang mungkin muncul di berita transfer; yang terlalu pendek dibuang agar tidak asal cocok
            'kata' => array_values(array_filter(array_map(fn ($k) => $this->rapat((string) $k), $kata), fn ($k) => strlen($k) >= 4)),
        ];
    }

    protected function rapat(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
    }

    protected function izin(User $user): void
    {
        if (! $user->can('rekonsiliasi.kelola')) {
            throw new DomainException('Anda tidak punya hak untuk rekonsiliasi bank.');
        }
    }

    /**
     * FR-081: pencocokan otomatis berdasarkan nominal, tanggal (selisih maks. JENDELA_HARI), dan berita transfer.
     *
     * Hati-hati, bukan asal cocok. Mutasi dicocokkan hanya kalau pilihannya tidak ambigu:
     *  - tepat satu kandidat yang beritanya memuat referensi/NIM/nomor transaksi, atau
     *  - tidak ada yang beritanya cocok, tetapi hanya ada satu kandidat (nominal dan tanggal), dan tidak ada
     *    mutasi lain yang berebut kandidat itu.
     * Sisanya dibiarkan untuk dicocokkan manual.
     *
     * @return array{diproses:int,cocok:int,ambigu:int,tanpa_kandidat:int}
     */
    public function cocokOtomatis(User $user, ?int $rekeningId = null, ?string $dari = null, ?string $sampai = null): array
    {
        $this->izin($user);

        $mutasi = MutasiBank::where('status', MutasiBank::BELUM_COCOK)
            ->when($rekeningId, fn ($q) => $q->where('rekening_bank_id', $rekeningId))
            ->when($dari, fn ($q) => $q->whereDate('tanggal', '>=', $dari))
            ->when($sampai, fn ($q) => $q->whereDate('tanggal', '<=', $sampai))
            ->orderBy('tanggal')->orderBy('id')
            ->get();

        $r = ['diproses' => $mutasi->count(), 'cocok' => 0, 'ambigu' => 0, 'tanpa_kandidat' => 0];
        if ($mutasi->isEmpty()) {
            return $r;
        }

        $tgl = $mutasi->map(fn ($m) => $m->tanggal->toDateString());
        $awal = Carbon::parse($tgl->min())->subDays(self::JENDELA_HARI)->toDateString();
        $akhir = Carbon::parse($tgl->max())->addDays(self::JENDELA_HARI)->toDateString();

        $kolam = ['masuk' => $this->muat('masuk', $awal, $akhir)->all(), 'keluar' => $this->muat('keluar', $awal, $akhir)->all()];

        $daftar = [];
        $klaim = [];
        $klaimHit = [];

        foreach ($mutasi as $m) {
            $berita = $this->rapat($m->keterangan.' '.$m->referensi);
            $cands = [];
            $hits = [];

            foreach ($kolam[$m->isMasuk() ? 'masuk' : 'keluar'] as $c) {
                if ($c['nominal'] !== $m->nominal) {
                    continue;
                }
                if (abs($c['tanggal']->diffInDays($m->tanggal, false)) > self::JENDELA_HARI) {
                    continue;
                }

                $kunci = $c['type'].':'.$c['id'];
                $cands[$kunci] = $c;
                foreach ($c['kata'] as $k) {
                    if (str_contains($berita, $k)) {
                        $hits[$kunci] = $c;
                        break;
                    }
                }
            }

            $daftar[$m->id] = [$cands, $hits];
            foreach (array_keys($cands) as $k) {
                $klaim[$k] = ($klaim[$k] ?? 0) + 1;
            }
            foreach (array_keys($hits) as $k) {
                $klaimHit[$k] = ($klaimHit[$k] ?? 0) + 1;
            }
        }

        foreach ($mutasi as $m) {
            [$cands, $hits] = $daftar[$m->id];

            if ($cands === []) {
                $r['tanpa_kandidat']++;
                continue;
            }

            $pilih = null;
            if (count($hits) === 1) {
                $k = array_key_first($hits);
                $pilih = ($klaimHit[$k] ?? 0) === 1 ? $hits[$k] : null;
            } elseif ($hits === [] && count($cands) === 1) {
                $k = array_key_first($cands);
                $pilih = ($klaim[$k] ?? 0) === 1 ? $cands[$k] : null;
            }

            if ($pilih === null) {
                $r['ambigu']++;
                continue;
            }

            try {
                DB::transaction(fn () => $this->ikat($m->id, $pilih['type'], $pilih['id'], 'otomatis', $user));
                $r['cocok']++;
            } catch (DomainException|UniqueConstraintViolationException) {
                $r['ambigu']++; // keburu dipakai/berubah oleh proses lain
            }
        }

        return $r;
    }

    /** FR-082: pencocokan manual. */
    public function cocokkan(int $mutasiId, string $type, int $id, User $user): MutasiBank
    {
        $this->izin($user);

        try {
            return DB::transaction(fn () => $this->ikat($mutasiId, $type, $id, 'manual', $user));
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('Transaksi ini sudah dicocokkan ke mutasi lain.');
        }
    }

    /** Harus dipanggil di dalam transaksi. */
    protected function ikat(int $mutasiId, string $type, int $id, string $cara, User $user): MutasiBank
    {
        $m = MutasiBank::lockForUpdate()->find($mutasiId)
            ?? throw new DomainException('Mutasi tidak ditemukan.');

        if ($m->status !== MutasiBank::BELUM_COCOK) {
            throw new DomainException('Mutasi ini sudah dicocokkan atau diabaikan.');
        }

        $masuk = $m->isMasuk();
        if (! in_array($type, $masuk ? [Pembayaran::class, PenerimaanDana::class] : [Pengeluaran::class], true)) {
            throw new DomainException($masuk
                ? 'Uang masuk hanya bisa dicocokkan dengan pembayaran atau penerimaan dana.'
                : 'Uang keluar hanya bisa dicocokkan dengan pengeluaran.');
        }

        $t = $this->dasar($type)->whereKey($id)->lockForUpdate()->first()
            ?? throw new DomainException('Transaksi tidak ditemukan, belum terverifikasi/disetujui, tercatat Tunai, atau sudah dicocokkan ke mutasi lain.');

        if (! BigDecimal::of((string) $t->nominal)->isEqualTo($m->nominal)) {
            throw new DomainException('Nominal transaksi (Rp '.number_format((float) $t->nominal, 0, ',', '.')
                .') tidak sama dengan mutasi (Rp '.number_format((float) $m->nominal, 0, ',', '.').').');
        }

        $m->update([
            'status' => MutasiBank::COCOK,
            'cocok_type' => $type,
            'cocok_id' => $t->id,
            'cara_cocok' => $cara,
            'dicocokkan_oleh' => $user->id,
            'dicocokkan_pada' => now(),
            'alasan_abaikan' => null,
        ]);

        return $m;
    }

    /** Lepas pencocokan (salah cocok, atau transaksinya sudah dibatalkan). */
    public function lepas(int $mutasiId, User $user): MutasiBank
    {
        $this->izin($user);

        return DB::transaction(function () use ($mutasiId) {
            $m = MutasiBank::lockForUpdate()->find($mutasiId)
                ?? throw new DomainException('Mutasi tidak ditemukan.');

            if ($m->status !== MutasiBank::COCOK) {
                throw new DomainException('Mutasi ini tidak sedang berstatus Cocok.');
            }

            $m->update([
                'status' => MutasiBank::BELUM_COCOK,
                'cocok_type' => null, 'cocok_id' => null, 'cara_cocok' => null,
                'dicocokkan_oleh' => null, 'dicocokkan_pada' => null,
            ]);

            return $m;
        });
    }

    /** Tandai mutasi yang memang tidak punya padanan di sistem (biaya admin, bunga bank, dan sejenisnya). */
    public function abaikan(int $mutasiId, string $alasan, User $user): MutasiBank
    {
        $this->izin($user);
        $alasan = trim($alasan);
        if ($alasan === '') {
            throw new DomainException('Alasan wajib diisi.');
        }

        return DB::transaction(function () use ($mutasiId, $alasan) {
            $m = MutasiBank::lockForUpdate()->find($mutasiId)
                ?? throw new DomainException('Mutasi tidak ditemukan.');

            if ($m->status !== MutasiBank::BELUM_COCOK) {
                throw new DomainException('Hanya mutasi Belum Cocok yang bisa diabaikan.');
            }

            $m->update(['status' => MutasiBank::DIABAIKAN, 'alasan_abaikan' => mb_substr($alasan, 0, 255)]);

            return $m;
        });
    }

    public function batalAbaikan(int $mutasiId, User $user): MutasiBank
    {
        $this->izin($user);

        return DB::transaction(function () use ($mutasiId) {
            $m = MutasiBank::lockForUpdate()->find($mutasiId)
                ?? throw new DomainException('Mutasi tidak ditemukan.');

            if ($m->status !== MutasiBank::DIABAIKAN) {
                throw new DomainException('Mutasi ini tidak sedang diabaikan.');
            }

            $m->update(['status' => MutasiBank::BELUM_COCOK, 'alasan_abaikan' => null]);

            return $m;
        });
    }

    /** Transaksi sistem yang bisa dicocokkan dengan satu mutasi: arah dan nominal sama persis. */
    public function kandidatManual(MutasiBank $m, string $cari = ''): Collection
    {
        $items = $this->muat($m->isMasuk() ? 'masuk' : 'keluar', null, null, $m->nominal);

        if ($cari !== '') {
            $c = mb_strtolower($cari);
            $items = $items->filter(fn ($i) => str_contains(mb_strtolower($i['label']), $c));
        }

        return $items
            ->sortBy(fn ($i) => abs($i['tanggal']->diffInDays($m->tanggal, false)))
            ->take(20)
            ->values();
    }

    /** Hitungan per status (plus yang cocoknya bermasalah) untuk rekening dan periode terpilih. */
    public function ringkasan(?int $rekeningId, ?string $dari, ?string $sampai): array
    {
        $q = fn () => MutasiBank::query()
            ->when($rekeningId, fn ($q) => $q->where('rekening_bank_id', $rekeningId))
            ->when($dari, fn ($q) => $q->whereDate('tanggal', '>=', $dari))
            ->when($sampai, fn ($q) => $q->whereDate('tanggal', '<=', $sampai));

        $baris = $q()->selectRaw('status, COUNT(*) as n, COALESCE(SUM(kredit), 0) as total_kredit, COALESCE(SUM(debit), 0) as total_debit')
            ->groupBy('status')->get()->keyBy('status');

        $hasil = [];
        foreach (array_keys(MutasiBank::STATUS) as $s) {
            $b = $baris->get($s);
            $hasil[$s] = [
                'n' => (int) ($b->n ?? 0),
                'kredit' => (string) ($b->total_kredit ?? '0.00'),
                'debit' => (string) ($b->total_debit ?? '0.00'),
            ];
        }
        $hasil['bermasalah'] = $q()->bermasalah()->count();

        return $hasil;
    }

    /**
     * Transaksi sistem (non-Tunai) yang belum ditemukan di mutasi mana pun pada periode itu.
     * Tidak dibedakan per rekening karena transaksi belum mencatat rekening tujuannya.
     * @return array{masuk: array{n:int,total:string}, keluar: array{n:int,total:string}}
     */
    public function sistemBelumDiBank(?string $dari, ?string $sampai): array
    {
        $hitung = function (string $kelas) use ($dari, $sampai) {
            $kol = self::TANGGAL[$kelas];
            $r = $this->dasar($kelas)
                ->when($dari, fn ($q) => $q->whereDate($kol, '>=', $dari))
                ->when($sampai, fn ($q) => $q->whereDate($kol, '<=', $sampai))
                ->selectRaw('COUNT(*) as n, COALESCE(SUM(nominal), 0) as total')
                ->first();

            return [(int) $r->n, BigDecimal::of((string) $r->total)];
        };

        [$n1, $t1] = $hitung(Pembayaran::class);
        [$n2, $t2] = $hitung(PenerimaanDana::class);
        [$n3, $t3] = $hitung(Pengeluaran::class);

        return [
            'masuk' => ['n' => $n1 + $n2, 'total' => (string) $t1->plus($t2)->toScale(2)],
            'keluar' => ['n' => $n3, 'total' => (string) $t3->toScale(2)],
        ];
    }
}
