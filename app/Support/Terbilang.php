<?php

namespace App\Support;

class Terbilang
{
    /** "3500000.00" -> "Tiga juta lima ratus ribu rupiah" (sen diabaikan) */
    public static function rupiah(string|int $nominal): string
    {
        $bulat = (int) explode('.', (string) $nominal)[0];

        return ucfirst($bulat === 0 ? 'nol' : trim(self::baca($bulat))).' rupiah';
    }

    protected static function baca(int $n): string
    {
        $a = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

        return match (true) {
            $n < 12 => $a[$n],
            $n < 20 => self::baca($n - 10).' belas',
            $n < 100 => self::baca(intdiv($n, 10)).' puluh'.($n % 10 ? ' '.self::baca($n % 10) : ''),
            $n < 200 => 'seratus'.($n > 100 ? ' '.self::baca($n - 100) : ''),
            $n < 1000 => self::baca(intdiv($n, 100)).' ratus'.($n % 100 ? ' '.self::baca($n % 100) : ''),
            $n < 2000 => 'seribu'.($n > 1000 ? ' '.self::baca($n - 1000) : ''),
            $n < 1_000_000 => self::baca(intdiv($n, 1000)).' ribu'.($n % 1000 ? ' '.self::baca($n % 1000) : ''),
            $n < 1_000_000_000 => self::baca(intdiv($n, 1_000_000)).' juta'.($n % 1_000_000 ? ' '.self::baca($n % 1_000_000) : ''),
            $n < 1_000_000_000_000 => self::baca(intdiv($n, 1_000_000_000)).' miliar'.($n % 1_000_000_000 ? ' '.self::baca($n % 1_000_000_000) : ''),
            default => self::baca(intdiv($n, 1_000_000_000_000)).' triliun'.($n % 1_000_000_000_000 ? ' '.self::baca($n % 1_000_000_000_000) : ''),
        };
    }
}
