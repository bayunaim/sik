<?php

namespace App\Services;

use App\Imports\BacaSemua;
use App\Models\ImportLog;
use App\Models\Mahasiswa;
use App\Models\ProgramStudi;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportMahasiswaService
{
    public const FIELD = [
        'nim' => ['label' => 'NIM', 'wajib' => true, 'sinonim' => ['nim', 'npm', 'nomor induk mahasiswa', 'no induk mahasiswa', 'no induk']],
        'nama' => ['label' => 'Nama', 'wajib' => true, 'sinonim' => ['nama', 'nama mahasiswa', 'nama lengkap', 'nm mahasiswa']],
        'kode_prodi' => ['label' => 'Kode Program Studi', 'wajib' => true, 'sinonim' => ['kode prodi', 'kode program studi', 'kd prodi', 'kode jurusan', 'prodi', 'id prodi']],
        'angkatan' => ['label' => 'Angkatan', 'wajib' => true, 'sinonim' => ['angkatan', 'tahun masuk', 'thn masuk', 'tahun angkatan', 'periode masuk']],
        'status' => ['label' => 'Status Akademik', 'wajib' => false, 'sinonim' => ['status', 'status akademik', 'status mahasiswa', 'status mhs']],
        'semester' => ['label' => 'Semester Aktif', 'wajib' => false, 'sinonim' => ['semester', 'semester aktif', 'smt', 'smt aktif']],
        'nama_prodi' => ['label' => 'Nama Program Studi', 'wajib' => false, 'sinonim' => ['nama prodi', 'nama program studi', 'program studi', 'nama jurusan']],
    ];

    public const STATUS_ALIAS = [
        'aktif' => 'Aktif', 'a' => 'Aktif', 'active' => 'Aktif',
        'cuti' => 'Cuti', 'c' => 'Cuti',
        'lulus' => 'Lulus', 'l' => 'Lulus', 'alumni' => 'Lulus',
        'nonaktif' => 'Nonaktif', 'non aktif' => 'Nonaktif', 'tidak aktif' => 'Nonaktif', 'n' => 'Nonaktif',
        'keluar' => 'Nonaktif', 'do' => 'Nonaktif', 'drop out' => 'Nonaktif',
        'mengundurkan diri' => 'Nonaktif', 'putus studi' => 'Nonaktif',
    ];

    public const MAKS_BARIS = 20000;

    /** Baca file: ['header' => [idx => nama], 'data' => [['baris' => n, 'sel' => [...]], ...]] */
    public function baca(string $path): array
    {
        $lembar = Excel::toArray(new BacaSemua(), $path, 'local')[0] ?? [];
        $header = null;
        $data = [];

        foreach ($lembar as $i => $r) {
            $r = array_values($r);
            $kosong = collect($r)->every(fn ($v) => $this->teks($v) === '');

            if ($kosong) {
                continue;
            }
            if ($header === null) {
                $header = $this->headerUnik($r);
                continue;
            }
            $data[] = ['baris' => $i + 1, 'sel' => $r];
        }

        if ($header === null) {
            throw new DomainException('File kosong atau tidak terbaca.');
        }
        if (count($data) > self::MAKS_BARIS) {
            throw new DomainException('File terlalu besar (maks. '.number_format(self::MAKS_BARIS, 0, ',', '.').' baris). Pecah menjadi beberapa file.');
        }

        return ['header' => $header, 'data' => $data];
    }

    /** Tebak pemetaan: template tersimpan dulu (jika kolomnya masih ada), lalu sinonim nama kolom. */
    public function tebakPemetaan(array $header, ?array $template): array
    {
        $norm = fn (string $s) => trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)));
        $peta = [];

        foreach (self::FIELD as $kunci => $def) {
            $dari = $template[$kunci] ?? '';
            if ($dari !== '' && in_array($dari, $header, true)) {
                $peta[$kunci] = $dari;
                continue;
            }

            $peta[$kunci] = '';
            foreach ($header as $nama) {
                if (in_array($norm($nama), $def['sinonim'], true)) {
                    $peta[$kunci] = $nama;
                    break;
                }
            }
        }

        return $peta;
    }

    /**
     * Analisis tanpa menyimpan apa pun.
     * @return array{baru:int,berubah:int,sama:int,error:array,prodi_baru:array,rows:array,nim_file:array}
     */
    public function analisis(array $baca, array $mapping): array
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

        $ambil = fn (array $sel, string $k) => $idx[$k] === null ? '' : $this->teks($sel[$idx[$k]] ?? null);

        $valid = [];
        $error = [];
        $semuaNim = [];

        foreach ($baca['data'] as $b) {
            $sel = $b['sel'];
            $nim = $ambil($sel, 'nim');
            $nama = $ambil($sel, 'nama');
            $kode = strtoupper($ambil($sel, 'kode_prodi'));
            $angk = $ambil($sel, 'angkatan');
            $statusMentah = $ambil($sel, 'status');
            $smt = $ambil($sel, 'semester');
            $alasan = [];

            if ($nim === '') {
                $alasan[] = 'NIM kosong';
            } elseif (! preg_match('/^[A-Za-z0-9.\-]{1,30}$/', $nim)) {
                $alasan[] = 'NIM tidak valid';
            } else {
                $semuaNim[$nim] = true;
            }
            if ($nama === '') {
                $alasan[] = 'Nama kosong';
            } elseif (mb_strlen($nama) > 255) {
                $alasan[] = 'Nama terlalu panjang';
            }
            if ($kode === '') {
                $alasan[] = 'Kode prodi kosong';
            } elseif (mb_strlen($kode) > 20) {
                $alasan[] = 'Kode prodi lebih dari 20 karakter (kolom salah dipetakan?)';
            }

            $angkatan = null;
            if (preg_match('/^(\d{4})/', $angk, $m) && (int) $m[1] >= 1990 && (int) $m[1] <= 2100) {
                $angkatan = (int) $m[1];
            } else {
                $alasan[] = 'Angkatan tidak valid';
            }

            $status = null;
            if ($statusMentah !== '') {
                $kunciStatus = trim(preg_replace('/[^a-z]+/', ' ', mb_strtolower($statusMentah)));
                $status = self::STATUS_ALIAS[$kunciStatus] ?? null;
                if ($status === null) {
                    $alasan[] = "Status \"{$statusMentah}\" tidak dikenal";
                }
            }

            if (mb_strlen($smt) > 10) {
                $alasan[] = 'Semester terlalu panjang';
            }

            if ($alasan === [] && isset($valid[$nim])) {
                $alasan[] = 'NIM ganda di file (baris '.$valid[$nim]['baris'].' dipakai)';
            }

            if ($alasan !== []) {
                $error[] = ['baris' => $b['baris'], 'nim' => $nim, 'nama' => $nama, 'alasan' => implode('; ', $alasan)];
                continue;
            }

            $valid[$nim] = [
                'baris' => $b['baris'], 'nim' => $nim, 'nama' => $nama, 'kode_prodi' => $kode,
                'nama_prodi' => $ambil($sel, 'nama_prodi'), 'angkatan' => $angkatan,
                'status' => $status, 'semester' => $smt,
            ];
        }

        $ada = [];
        foreach (array_chunk(array_keys($valid), 1000) as $potong) {
            foreach (Mahasiswa::withTrashed()->whereIn('nim', $potong)->get() as $m) {
                $ada[(string) $m->nim] = $m; // kunci ditulis langsung, tidak dinomori ulang
            }
        }

        $prodi = array_change_key_case(ProgramStudi::withTrashed()->pluck('id', 'kode')->all(), CASE_UPPER);

        $baru = $berubah = $sama = 0;
        $prodiBaru = [];
        $rows = [];

        foreach ($valid as $nim => $v) {
            $lama = $ada[$nim] ?? null;
            $prodiId = $prodi[$v['kode_prodi']] ?? null;

            if ($prodiId === null) {
                $prodiBaru[$v['kode_prodi']] ??= $v['nama_prodi'] !== '' ? $v['nama_prodi'] : $v['kode_prodi'];
            }

            // Kolom yang tidak dipetakan/kosong tidak menimpa data lama
            $v['status'] ??= $lama?->status_akademik ?? 'Aktif';
            $v['semester'] = $idx['semester'] === null || $v['semester'] === ''
                ? ($lama?->semester_aktif)
                : $v['semester'];

            if (! $lama) {
                $baru++;
                $rows[] = $v + ['jenis' => 'baru'];
                continue;
            }

            $beda = $lama->trashed()
                || $lama->nama !== $v['nama']
                || (int) $lama->angkatan !== $v['angkatan']
                || $lama->status_akademik !== $v['status']
                || (string) $lama->semester_aktif !== (string) $v['semester']
                || $prodiId === null
                || (int) $lama->program_studi_id !== (int) $prodiId;

            if ($beda) {
                $berubah++;
                $rows[] = $v + ['jenis' => 'berubah'];
            } else {
                $sama++;
            }
        }

        return [
            'baru' => $baru, 'berubah' => $berubah, 'sama' => $sama,
            'error' => $error, 'prodi_baru' => $prodiBaru, 'rows' => $rows,
            'nim_file' => array_keys($semuaNim),
        ];
    }

    /** Simpan: analisis ulang dari file, lalu upsert dalam satu transaksi. */
    public function simpan(int $logId, array $baca, array $mapping, bool $tandaiHilang, User $user): ImportLog
    {
        $a = $this->analisis($baca, $mapping);
        $now = now();

        return DB::transaction(function () use ($logId, $a, $mapping, $tandaiHilang, $user, $now) {
            $log = ImportLog::lockForUpdate()->findOrFail($logId);

            // 'berjalan' = diambil worker antrean (FR-144); 'pratinjau' = dipanggil langsung
            if (! in_array($log->status, ['pratinjau', 'berjalan'], true)) {
                throw new DomainException('Import ini sudah diproses.');
            }

            // Program studi otomatis (FR-143)
            foreach ($a['prodi_baru'] as $kode => $nama) {
                $p = ProgramStudi::withTrashed()->where('kode', $kode)->first();
                if ($p) {
                    $p->trashed() ? $p->restore() : null;
                } else {
                    ProgramStudi::create(['kode' => $kode, 'nama' => $nama, 'is_active' => true]);
                }
            }
            $prodi = array_change_key_case(ProgramStudi::withTrashed()->pluck('id', 'kode')->all(), CASE_UPPER);

            $baris = array_map(fn ($r) => [
                'nim' => $r['nim'],
                'nama' => $r['nama'],
                'program_studi_id' => $prodi[$r['kode_prodi']],
                'angkatan' => $r['angkatan'],
                'status_akademik' => $r['status'],
                'semester_aktif' => $r['semester'] !== '' ? $r['semester'] : null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ], $a['rows']);

            foreach (array_chunk($baris, 500) as $potong) {
                DB::table('mahasiswa')->upsert(
                    $potong,
                    ['nim'],
                    ['nama', 'program_studi_id', 'angkatan', 'status_akademik', 'semester_aktif', 'updated_at', 'deleted_at']
                );
            }

            // FR-142: yang tidak ada di file tidak dihapus, hanya diberi status
            $hilang = 0;
            if ($tandaiHilang) {
                $diFile = array_flip($a['nim_file']);
                $ids = Mahasiswa::whereIn('status_akademik', ['Aktif', 'Cuti'])->pluck('nim', 'id')
                    ->reject(fn ($nim) => isset($diFile[$nim]))->keys()->all();

                foreach (array_chunk($ids, 1000) as $potong) {
                    $hilang += DB::table('mahasiswa')->whereIn('id', $potong)
                        ->update(['status_akademik' => Mahasiswa::STATUS_TIDAK_ADA_DI_IMPORT, 'updated_at' => $now]);
                }
            }

            $errorPath = null;
            if ($a['error']) {
                $errorPath = 'import-error/'.$log->id.'.csv';
                Storage::disk('local')->put($errorPath, $this->csvError($a['error']));
            }

            $log->update([
                'status' => 'selesai',
                'mapping' => $mapping,
                'jumlah_baru' => $a['baru'],
                'jumlah_berubah' => $a['berubah'],
                'jumlah_sama' => $a['sama'],
                'jumlah_gagal' => count($a['error']),
                'jumlah_prodi_baru' => count($a['prodi_baru']),
                'jumlah_hilang' => $hilang,
                'error_path' => $errorPath,
            ]);

            activity('import')
                ->performedOn($log)
                ->causedBy($user)
                ->withProperties($log->only(['nama_file', 'jumlah_baru', 'jumlah_berubah', 'jumlah_sama', 'jumlah_gagal', 'jumlah_prodi_baru', 'jumlah_hilang']))
                ->log('import mahasiswa GoFeeder');

            return $log->refresh();
        });
    }

    public function csvError(array $error): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, ['Baris', 'NIM', 'Nama', 'Alasan']);
        foreach ($error as $e) {
            // cegah formula injection saat dibuka di Excel
            $aman = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : $v;
            fputcsv($h, [$e['baris'], $aman($e['nim']), $aman($e['nama']), $e['alasan']]);
        }
        rewind($h);
        $isi = stream_get_contents($h);
        fclose($h);

        return $isi;
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
