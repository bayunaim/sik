<?php

return [
    /*
    | Ekspor dengan baris lebih banyak dari ini diproses lewat antrean (queue) dan
    | pengguna diberi notifikasi berisi tautan unduh. Di bawahnya tetap langsung diunduh.
    */
    'batas_antrean' => (int) env('LAPORAN_BATAS_ANTREAN', 1000),

    /* Berapa hari file hasil ekspor antrean disimpan sebelum dihapus otomatis. */
    'simpan_hari' => (int) env('LAPORAN_SIMPAN_HARI', 2),
];
