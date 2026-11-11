<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;

/** CSV polos untuk dipakai sistem lain: header di baris 1, tanpa judul dan baris total. */
class LaporanCsv implements FromArray
{
    public function __construct(private array $lap)
    {
    }

    public function array(): array
    {
        $kolom = $this->lap['kolom'];
        $rows = [array_column($kolom, 'label')];

        foreach ($this->lap['baris'] as $b) {
            $rows[] = array_map(function ($k) use ($b) {
                $v = $b[$k['key']] ?? '';
                // sel yang diawali = + - @ dibaca sebagai rumus oleh Excel: netralkan pada kolom teks
                if ($k['tipe'] === 'teks' && is_string($v) && $v !== '' && str_contains('=+-@', $v[0])) {
                    return "'".$v;
                }

                return $v;
            }, $kolom);
        }

        return $rows;
    }
}
