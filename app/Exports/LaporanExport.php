<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LaporanExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithStyles
{
    private const BARIS_HEADER = 4;

    public function __construct(private array $lap)
    {
    }

    public function array(): array
    {
        $kolom = $this->lap['kolom'];
        $rows = [[$this->lap['judul']], [$this->lap['periode']], [], array_column($kolom, 'label')];

        foreach ($this->lap['baris'] as $b) {
            $rows[] = array_map(fn ($k) => $this->nilai($k['tipe'], $b[$k['key']] ?? null), $kolom);
        }

        foreach ($this->lap['total'] as $t) {
            $r = [];
            foreach ($kolom as $i => $k) {
                $r[] = $i === 0 ? $t['label'] : $this->nilai($k['tipe'], $t['nilai'][$k['key']] ?? null);
            }
            $rows[] = $r;
        }

        return $rows;
    }

    public function columnFormats(): array
    {
        $f = [];
        foreach ($this->lap['kolom'] as $i => $k) {
            $huruf = Coordinate::stringFromColumnIndex($i + 1);
            if ($k['tipe'] === 'uang' || $k['tipe'] === 'angka') {
                $f[$huruf] = '#,##0';
            } elseif ($k['tipe'] === 'persen') {
                $f[$huruf] = '0.0"%"';
            }
        }

        return $f;
    }

    public function styles(Worksheet $sheet): array
    {
        $akhir = Coordinate::stringFromColumnIndex(count($this->lap['kolom']));
        $h = self::BARIS_HEADER;

        $sheet->mergeCells("A1:{$akhir}1");
        $sheet->mergeCells("A2:{$akhir}2");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setItalic(true);

        $sheet->getStyle("A{$h}:{$akhir}{$h}")->getFont()->setBold(true);
        $sheet->getStyle("A{$h}:{$akhir}{$h}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');

        $jumlahTotal = count($this->lap['total']);
        if ($jumlahTotal > 0) {
            $awal = $h + count($this->lap['baris']) + 1;
            $sheet->getStyle("A{$awal}:{$akhir}".($awal + $jumlahTotal - 1))->getFont()->setBold(true);
        }

        return [];
    }

    private function nilai(string $tipe, mixed $v): mixed
    {
        if ($v === null || $v === '') {
            return null;
        }

        return match ($tipe) {
            'uang', 'persen' => (float) $v,
            'angka' => (int) $v,
            default => $v,
        };
    }
}
