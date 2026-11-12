<?php

namespace App\Services;

use App\Imports\BacaSemua;
use App\Models\MutasiBank;
use App\Models\MutasiImport;
use App\Models\RekeningBank;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;

/**
 * FR-080: import mutasi bank (CSV/Excel) per rekening.
 * Alur sama dengan ImportMahasiswaService: baca, tebak pemetaan, analisis tanpa menyimpan, lalu simpan atomik.
 */
class ImportMutasiBankService
{
    public const FIELD = [
        'tanggal' => ['label' => 'Tanggal', 'wajib' => true, 'sinonim' => ['tanggal', 'tgl', 'tanggal transaksi', 'tgl transaksi', 'tanggal posting', 'posting date', 'date']],
        'keterangan' => ['label' => 'Keterangan / Berita Transfer', 'wajib' => true, 'sinonim' => ['keterangan', 'berita', 'berita transfer', 'uraian', 'uraian transaksi', 'deskripsi', 'description', 'remark', 'remarks', 'narasi', 'detail transaksi']],
        'debit' => ['label' => 'Debit (uang keluar)', 'wajib' => false, 'sinonim' => ['debit', 'debet', 'mutasi debit', 'uang keluar', 'keluar', 'withdrawal']],
        'kredit' => ['label' => 'Kredit (uang masuk)', 'wajib' => false, 'sinonim' => ['kredit', 'credit', 'mutasi kredit', 'uang masuk', 'masuk', 'deposit']],
        'jumlah' => ['label' => 'Jumlah (jika debit dan kredit satu kolom)', 'wajib' => false, 'sinonim' => ['jumlah', 'nominal', 'amount', 'mutasi']],
        'jenis' => ['label' => 'Jenis DB/CR (untuk kolom Jumlah)', 'wajib' => false, 'sinonim' => ['jenis', 'tipe', 'type', 'db cr', 'dbcr', 'd k', 'cr db', 'dc']],
        'saldo' => ['label' => 'Saldo', 'wajib' => false, 'sinonim' => ['saldo', 'saldo akhir', 'balance']],
        'referensi' => ['label' => 'No. Referensi', 'wajib' => false, 'sinonim' => ['referensi', 'no referensi', 'nomor referensi', 'no ref', 'ref', 'reference']],
    ];

    private const JENIS_DEBIT = ['db', 'd', 'debit', 'debet', 'dr', 'keluar'];
    private const JENIS_KREDIT = ['cr', 'k', 'kredit', 'credit', 'masuk'];

    private const BULAN = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7,
        'agu' => 8, 'aug' => 8, 'agt' => 8, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12,
    ];

    public const MAKS_BARIS = 20000;

    /**
     * Baca file. Mutasi bank sering diawali baris judul/info rekening, jadi baris header dicari:
     * baris pertama yang memuat minimal dua nama kolom yang dikenal.
     * @return array{header: array<int,string>, data: array<int,array{baris:int,sel:array}>}
     */
    public function baca(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $lembar = in_array($ext, ['csv', 'txt'], true)
            ? $this->bacaCsv(Storage::disk('local')->get($path))
            : (Excel::toArray(new BacaSemua(), $path, 'local')[0] ?? []);
        $dikenal = collect(self::FIELD)->pluck('sinonim')->flatten()->all();
        $header = null;
        $pertama = null;
        $data = [];

        foreach ($lembar as $i => $r) {
            $r = array_values($r);
            if (collect($r)->every(fn ($v) => $this->teks($v) === '')) {
                continue;
            }

            if ($header === null) {
                $pertama ??= $r;
                $cocok = collect($r)->filter(fn ($v) => in_array($this->norm($this->teks($v)), $dikenal, true))->count();
                if ($cocok >= 2) {
                    $header = $this->headerUnik($r);
                }
                continue;
            }
            $data[] = ['baris' => $i + 1, 'sel' => $r];
        }

        if ($header === null) {
            throw new DomainException('Baris judul kolom tidak ditemukan. Pastikan file memuat kolom seperti Tanggal, Keterangan, Debit/Kredit.');
        }
        if (count($data) > self::MAKS_BARIS) {
            throw new DomainException('File terlalu besar (maks. '.number_format(self::MAKS_BARIS, 0, ',', '.').' baris). Pecah menjadi beberapa file.');
        }

        return ['header' => $header, 'data' => $data];
    }

    /**
     * Baca CSV sendiri: penebak pemisah bawaan PhpSpreadsheet hanya melihat beberapa baris pertama,
     * sehingga salah tebak jika file diawali baris info rekening yang bentuknya beda dari data.
     * Pemisah dipilih dari seluruh file: yang paling banyak menghasilkan baris dengan >= 3 kolom.
     */
    private function bacaCsv(?string $isi): array
    {
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', (string) $isi);
        $isi = str_replace(["\r\n", "\r"], "\n", $isi);

        $parse = function (string $sep) use ($isi): array {
            $h = fopen('php://temp', 'r+');
            fwrite($h, $isi);
            rewind($h);
            $baris = [];
            while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
                $baris[] = $r;
            }
            fclose($h);

            return $baris;
        };

        $terbaik = null;
        $skorTerbaik = -1;
        foreach ([',', ';', "\t", '|'] as $sep) {
            $baris = $parse($sep);
            $skor = collect($baris)->filter(fn ($r) => count($r) >= 3)->count();
            if ($skor > $skorTerbaik) {
                $skorTerbaik = $skor;
                $terbaik = $baris;
            }
        }

        return array_map(fn ($r) => array_map(fn ($v) => $v === null ? null : (string) $v, $r), $terbaik ?? []);
    }

    /** Template tersimpan dulu (jika kolomnya masih ada), lalu sinonim. Satu kolom hanya dipakai satu field. */
    public function tebakPemetaan(array $header, ?array $template): array
    {
        $peta = [];
        $dipakai = [];

        foreach (self::FIELD as $kunci => $def) {
            $dari = $template[$kunci] ?? '';
            if ($dari !== '' && in_array($dari, $header, true) && ! isset($dipakai[$dari])) {
                $peta[$kunci] = $dari;
                $dipakai[$dari] = true;
                continue;
            }

            $peta[$kunci] = '';
            foreach ($header as $nama) {
                if (! isset($dipakai[$nama]) && in_array($this->norm($nama), $def['sinonim'], true)) {
                    $peta[$kunci] = $nama;
                    $dipakai[$nama] = true;
                    break;
                }
            }
        }

        return $peta;
    }

    /**
     * Analisis tanpa menyimpan apa pun.
     * @return array{baru:int,duplikat:int,error:array,rows:array,tanggal_awal:?string,tanggal_akhir:?string,total_debit:string,total_kredit:string}
     */
    public function analisis(array $baca, array $mapping, int $rekeningId): array
    {
        $idx = [];
        foreach (self::FIELD as $kunci => $def) {
            $nama = $mapping[$kunci] ?? '';
            $p = $nama !== '' ? array_search($nama, $baca['header'], true) : false;
            $idx[$kunci] = $p === false ? null : $p;

            if ($def['wajib'] && $idx[$kunci] === null) {
                throw new DomainException("Kolom {$def['label']} wajib dipetakan.");
            }
        }
        if ($idx['jumlah'] === null && $idx['debit'] === null && $idx['kredit'] === null) {
            throw new DomainException('Petakan kolom Debit dan/atau Kredit, atau satu kolom Jumlah.');
        }

        $ambil = fn (array $sel, string $k) => $idx[$k] === null ? null : ($sel[$idx[$k]] ?? null);

        $valid = [];
        $error = [];
        $urutan = [];

        foreach ($baca['data'] as $b) {
            $sel = $b['sel'];
            $mentahTgl = $ambil($sel, 'tanggal');
            $alasan = [];

            $tanggal = $this->tanggal($mentahTgl);
            $debit = BigDecimal::zero();
            $kredit = BigDecimal::zero();

            try {
                if ($idx['jumlah'] !== null) {
                    $jml = $this->uang($ambil($sel, 'jumlah'));
                    $jenis = $idx['jenis'] !== null ? trim(preg_replace('/[^a-z]+/', ' ', mb_strtolower($this->teks($ambil($sel, 'jenis'))))) : '';

                    if ($jml !== null && ! $jml->isZero()) {
                        if (in_array($jenis, self::JENIS_DEBIT, true)) {
                            $debit = $jml->abs();
                        } elseif (in_array($jenis, self::JENIS_KREDIT, true)) {
                            $kredit = $jml->abs();
                        } elseif ($jenis === '' && $jml->isNegative()) {
                            $debit = $jml->abs();
                        } elseif ($jenis === '' && $idx['jenis'] === null) {
                            $alasan[] = 'Arah mutasi tidak diketahui (petakan kolom Jenis DB/CR, atau pakai angka negatif untuk uang keluar)';
                        } else {
                            $alasan[] = 'Jenis "'.$this->teks($ambil($sel, 'jenis')).'" tidak dikenal (harus DB/CR)';
                        }
                    }
                }
                if ($idx['debit'] !== null) {
                    $debit = ($this->uang($ambil($sel, 'debit')) ?? BigDecimal::zero())->abs()->plus($debit);
                }
                if ($idx['kredit'] !== null) {
                    $kredit = ($this->uang($ambil($sel, 'kredit')) ?? BigDecimal::zero())->abs()->plus($kredit);
                }
            } catch (DomainException $e) {
                $alasan[] = $e->getMessage();
            }

            // Baris tanpa tanggal dan tanpa nominal (total, saldo awal, catatan kaki) dilewati diam-diam
            if ($tanggal === null && $debit->isZero() && $kredit->isZero() && $alasan === []) {
                continue;
            }

            if ($tanggal === null) {
                $alasan[] = 'Tanggal tidak valid';
            }
            if ($alasan === []) {
                if ($debit->isZero() && $kredit->isZero()) {
                    $alasan[] = 'Nominal kosong atau nol';
                } elseif (! $debit->isZero() && ! $kredit->isZero()) {
                    $alasan[] = 'Debit dan kredit terisi bersamaan';
                }
            }

            $ket = trim(preg_replace('/\s+/', ' ', $this->teks($ambil($sel, 'keterangan'))));
            if ($ket === '') {
                $alasan[] = 'Keterangan kosong';
            }

            if ($alasan !== []) {
                $error[] = ['baris' => $b['baris'], 'tanggal' => $this->teks($mentahTgl), 'keterangan' => $ket, 'alasan' => implode('; ', array_unique($alasan))];
                continue;
            }

            try {
                $saldo = $idx['saldo'] !== null ? $this->uang($ambil($sel, 'saldo')) : null;
            } catch (DomainException) {
                $saldo = null; // saldo hanya informasi, tidak menggagalkan baris
            }

            $ket = mb_substr($ket, 0, 500);
            $dasar = implode('|', [
                $tanggal, (string) $debit->toScale(2), (string) $kredit->toScale(2),
                $saldo === null ? '' : (string) $saldo->toScale(2), mb_strtolower($ket),
            ]);
            // baris yang persis sama dalam satu file dibedakan dengan nomor kemunculan
            $urutan[$dasar] = ($urutan[$dasar] ?? 0) + 1;

            $valid[] = [
                'baris' => $b['baris'],
                'tanggal' => $tanggal,
                'keterangan' => $ket,
                'referensi' => mb_substr($this->teks($ambil($sel, 'referensi')), 0, 100) ?: null,
                'debit' => (string) $debit->toScale(2),
                'kredit' => (string) $kredit->toScale(2),
                'saldo' => $saldo === null ? null : (string) $saldo->toScale(2),
                'kunci' => sha1($dasar.'|'.$urutan[$dasar]),
            ];
        }

        $ada = [];
        foreach (array_chunk(array_column($valid, 'kunci'), 1000) as $potong) {
            foreach (DB::table('mutasi_bank')->where('rekening_bank_id', $rekeningId)->whereIn('kunci', $potong)->pluck('kunci') as $k) {
                $ada[$k] = true;
            }
        }

        $baru = [];
        $duplikat = 0;
        foreach ($valid as $v) {
            if (isset($ada[$v['kunci']])) {
                $duplikat++;
            } else {
                $baru[] = $v;
            }
        }

        $tgl = array_column($valid, 'tanggal');

        return [
            'baru' => count($baru),
            'duplikat' => $duplikat,
            'error' => $error,
            'rows' => $baru,
            'tanggal_awal' => $tgl ? min($tgl) : null,
            'tanggal_akhir' => $tgl ? max($tgl) : null,
            'total_debit' => (string) array_reduce($baru, fn ($c, $r) => $c->plus($r['debit']), BigDecimal::zero()->toScale(2)),
            'total_kredit' => (string) array_reduce($baru, fn ($c, $r) => $c->plus($r['kredit']), BigDecimal::zero()->toScale(2)),
        ];
    }

    /** Simpan: analisis ulang dari file, lalu masukkan dalam satu transaksi. */
    public function simpan(int $importId, array $baca, array $mapping, User $user): MutasiImport
    {
        if (! $user->can('rekonsiliasi.kelola')) {
            throw new DomainException('Anda tidak punya hak untuk mengimpor mutasi bank.');
        }

        return DB::transaction(function () use ($importId, $baca, $mapping, $user) {
            $log = MutasiImport::lockForUpdate()->findOrFail($importId);

            if ($log->status !== 'pratinjau') {
                throw new DomainException('Import ini sudah diproses.');
            }
            $rekening = RekeningBank::findOrFail($log->rekening_bank_id);
            if (! $rekening->is_active) {
                throw new DomainException('Rekening bank ini sudah dinonaktifkan.');
            }

            $a = $this->analisis($baca, $mapping, $rekening->id);
            $now = now();

            $baru = 0;
            foreach (array_chunk($a['rows'], 500) as $potong) {
                // insertOrIgnore: kalau ada import lain yang menyelip, baris kembar dilewati oleh unique index
                $baru += DB::table('mutasi_bank')->insertOrIgnore(array_map(fn ($r) => [
                    'rekening_bank_id' => $rekening->id,
                    'mutasi_import_id' => $log->id,
                    'tanggal' => $r['tanggal'],
                    'keterangan' => $r['keterangan'],
                    'referensi' => $r['referensi'],
                    'debit' => $r['debit'],
                    'kredit' => $r['kredit'],
                    'saldo' => $r['saldo'],
                    'kunci' => $r['kunci'],
                    'status' => MutasiBank::BELUM_COCOK,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $potong));
            }

            $errorPath = null;
            if ($a['error']) {
                $errorPath = 'mutasi-error/'.$log->id.'.csv';
                Storage::disk('local')->put($errorPath, $this->csvError($a['error']));
            }

            $log->update([
                'status' => 'selesai',
                'mapping' => $mapping,
                'jumlah_baru' => $baru,
                'jumlah_duplikat' => $a['duplikat'] + (count($a['rows']) - $baru),
                'jumlah_gagal' => count($a['error']),
                'tanggal_awal' => $a['tanggal_awal'],
                'tanggal_akhir' => $a['tanggal_akhir'],
                'error_path' => $errorPath,
            ]);

            activity('import')
                ->performedOn($log)
                ->causedBy($user)
                ->withProperties($log->only(['nama_file', 'rekening_bank_id', 'jumlah_baru', 'jumlah_duplikat', 'jumlah_gagal']))
                ->log('import mutasi bank');

            return $log->refresh();
        });
    }

    /**
     * Batalkan satu import (misalnya salah pilih rekening). Hanya boleh kalau tidak ada mutasi dari import ini
     * yang sudah dicocokkan atau diabaikan. Mutasi di-soft-delete dan kuncinya diganti supaya file yang sama
     * bisa diimpor ulang ke rekening yang benar tanpa dianggap duplikat.
     */
    public function batalkan(int $importId, string $alasan, User $user): MutasiImport
    {
        if (! $user->can('rekonsiliasi.kelola')) {
            throw new DomainException('Anda tidak punya hak untuk membatalkan import mutasi.');
        }
        $alasan = trim($alasan);
        if ($alasan === '') {
            throw new DomainException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($importId, $alasan, $user) {
            $log = MutasiImport::lockForUpdate()->findOrFail($importId);

            if ($log->status !== 'selesai') {
                throw new DomainException('Hanya import yang sudah selesai yang bisa dibatalkan.');
            }
            if (MutasiBank::where('mutasi_import_id', $log->id)->where('status', '!=', MutasiBank::BELUM_COCOK)->exists()) {
                throw new DomainException('Sebagian mutasi dari import ini sudah dicocokkan atau diabaikan. Lepaskan dulu sebelum membatalkan import.');
            }

            $now = now();
            $jumlah = 0;
            MutasiBank::where('mutasi_import_id', $log->id)->select('id')->chunkById(500, function ($potong) use ($now, &$jumlah) {
                foreach ($potong as $m) {
                    DB::table('mutasi_bank')->where('id', $m->id)->update([
                        'kunci' => sha1('batal|'.$m->id),
                        'deleted_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $jumlah++;
                }
            });

            $log->update(['status' => 'dibatalkan', 'catatan' => $alasan]);

            activity('import')
                ->performedOn($log)
                ->causedBy($user)
                ->withProperties(['nama_file' => $log->nama_file, 'mutasi_dihapus' => $jumlah, 'alasan' => $alasan])
                ->log('pembatalan import mutasi bank');

            return $log->refresh();
        });
    }

    public function csvError(array $error): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, ['Baris', 'Tanggal', 'Keterangan', 'Alasan']);
        foreach ($error as $e) {
            // cegah formula injection saat dibuka di Excel
            $aman = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : $v;
            fputcsv($h, [$e['baris'], $aman($e['tanggal']), $aman($e['keterangan']), $e['alasan']]);
        }
        rewind($h);
        $isi = stream_get_contents($h);
        fclose($h);

        return $isi;
    }

    /** Angka dari berbagai format: 1.250.000,00 / 1,250,000.00 / 1250000 / (1.000) / -1000. Kosong = null. */
    public function uang(mixed $v): ?BigDecimal
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return BigDecimal::of(number_format((float) $v, 2, '.', ''));
        }

        $s = trim((string) $v);
        if ($s === '' || $s === '-') {
            return null;
        }

        $neg = str_starts_with($s, '-') || str_ends_with($s, '-') || (bool) preg_match('/^\(.*\)$/', $s);
        $s = preg_replace('/[^0-9.,]/', '', $s);
        if ($s === '' || ! preg_match('/\d/', $s)) {
            throw new DomainException("Angka \"{$v}\" tidak valid");
        }

        $koma = substr_count($s, ',');
        $titik = substr_count($s, '.');
        $desimal = null;

        if ($koma && $titik) {
            $desimal = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
        } elseif ($koma + $titik === 1) {
            // satu pemisah saja: tiga digit di belakangnya dianggap pemisah ribuan ("1.250" = 1250)
            $sep = $koma ? ',' : '.';
            $desimal = preg_match('/'.preg_quote($sep, '/').'\d{3}$/', $s) ? null : $sep;
        }

        if ($desimal !== null) {
            $bagian = explode($desimal, $s);
            $belakang = array_pop($bagian);
            $s = str_replace([',', '.'], '', implode('', $bagian)).'.'.$belakang;
        } else {
            $s = str_replace([',', '.'], '', $s);
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
            throw new DomainException("Angka \"{$v}\" tidak valid");
        }

        $d = BigDecimal::of($s)->toScale(2);

        return $neg ? $d->negated() : $d;
    }

    /** Tanggal ke Y-m-d. Format Indonesia (hari dulu), ISO, nama bulan, atau nomor seri Excel. */
    public function tanggal(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }

        if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) {
            return TanggalExcel::excelToDateTimeObject((float) $s)->format('Y-m-d');
        }

        $y = $m = $d = null;
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $x)) {
            [$y, $m, $d] = [(int) $x[1], (int) $x[2], (int) $x[3]];
        } elseif (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})#', $s, $x)) {
            [$y, $m, $d] = [(int) $x[1], (int) $x[2], (int) $x[3]];
        } elseif (preg_match('#^(\d{1,2})[-/. ](\d{1,2})[-/. ](\d{2,4})#', $s, $x)) {
            [$d, $m, $y] = [(int) $x[1], (int) $x[2], (int) $x[3]];
        } elseif (preg_match('#^(\d{1,2})[-/. ]([A-Za-z]{3,})[-/. ,]+(\d{2,4})#', $s, $x)) {
            $m = self::BULAN[mb_strtolower(mb_substr($x[2], 0, 3))] ?? null;
            [$d, $y] = [(int) $x[1], (int) $x[3]];
        }

        if ($y === null || $m === null) {
            return null;
        }
        if ($y < 100) {
            $y += 2000;
        }

        return checkdate($m, $d, $y) && $y >= 1990 && $y <= 2100
            ? Carbon::create($y, $m, $d)->format('Y-m-d')
            : null;
    }

    private function norm(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)));
    }

    private function headerUnik(array $r): array
    {
        $out = [];
        $pakai = [];
        foreach ($r as $i => $v) {
            $nama = $this->teks($v) ?: 'Kolom '.($i + 1);
            $pakai[$nama] = ($pakai[$nama] ?? 0) + 1;
            $out[$i] = $pakai[$nama] > 1 ? "{$nama} ({$pakai[$nama]})" : $nama;
        }

        return $out;
    }

    private function teks(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_int($v) || is_float($v)) {
            return floor($v) == $v ? number_format($v, 0, '', '') : (string) $v;
        }

        return trim((string) $v);
    }
}
