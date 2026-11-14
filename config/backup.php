<?php

return [
    /* Lokasi program mysqldump dan mysql. Isi path lengkap bila tidak ada di PATH (mis. MAMP/XAMPP). */
    'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),
    'mysql' => env('MYSQL_PATH', 'mysql'),

    /* Berapa hari berkas backup disimpan. Tiga berkas terbaru selalu disisakan. */
    'simpan_hari' => (int) env('BACKUP_SIMPAN_HARI', 14),
];
