<?php

/*
 * FR-104: tombol akses cepat di dashboard. Hanya tombol yang izinnya dimiliki pengguna yang tampil.
 * 'badge' (opsional): 'verifikasi' atau 'approval' menampilkan jumlah antrean pada tombol.
 * Tidak memakai closure agar config tetap bisa di-cache (php artisan config:cache).
 */
return [
    ['label' => 'Buat tagihan massal', 'icon' => 'cil-description', 'route' => 'tagihan.massal', 'can' => 'tagihan.buat'],
    ['label' => 'Catat pembayaran', 'icon' => 'cil-credit-card', 'route' => 'pembayaran.index', 'can' => 'pembayaran.input'],
    ['label' => 'Verifikasi pembayaran', 'icon' => 'cil-credit-card', 'route' => 'pembayaran.index', 'can' => 'pembayaran.verifikasi', 'badge' => 'verifikasi'],
    ['label' => 'Ajukan pengeluaran', 'icon' => 'cil-arrow-circle-top', 'route' => 'pengeluaran.index', 'can' => 'pengeluaran.ajukan'],
    ['label' => 'Setujui pengeluaran', 'icon' => 'cil-arrow-circle-top', 'route' => 'pengeluaran.index', 'params' => ['filterStatus' => 'pending'], 'can' => 'pengeluaran.approve', 'badge' => 'approval'],
    ['label' => 'Catat penerimaan dana', 'icon' => 'cil-arrow-circle-bottom', 'route' => 'penerimaan.index', 'can' => 'penerimaan.input'],
    ['label' => 'Jurnal umum', 'icon' => 'cil-book', 'route' => 'jurnal.index', 'can' => 'jurnal.lihat'],
    ['label' => 'Laporan tunggakan', 'icon' => 'cil-chart-line', 'route' => 'laporan.tunggakan', 'can' => 'laporan.lihat'],
    ['label' => 'Histori mahasiswa', 'icon' => 'cil-school', 'route' => 'laporan.histori-mahasiswa', 'can' => 'laporan.lihat'],
    ['label' => 'Rekonsiliasi bank', 'icon' => 'cil-sync', 'route' => 'rekonsiliasi.index', 'can' => 'rekonsiliasi.kelola'],
    ['label' => 'Import GoFeeder', 'icon' => 'cil-cloud-upload', 'route' => 'import.index', 'can' => 'import.sia'],
];
