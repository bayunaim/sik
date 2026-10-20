<?php

return [
    ['title' => 'Utama'],
    ['label' => 'Dashboard', 'icon' => 'cil-speedometer', 'route' => 'dashboard'],
    ['label' => 'Portal Mahasiswa', 'icon' => 'cil-school', 'route' => 'portal.index', 'can' => 'portal.lihat'],

    ['title' => 'Keuangan'],
    ['label' => 'Anggaran', 'icon' => 'cil-calculator', 'route' => 'anggaran.index', 'can' => 'anggaran.lihat'],
    ['label' => 'Tagihan', 'icon' => 'cil-description', 'route' => 'tagihan.index', 'can' => 'tagihan.lihat'],
    ['label' => 'Pembayaran', 'icon' => 'cil-credit-card', 'route' => 'pembayaran.index', 'can' => 'pembayaran.lihat'],
    ['label' => 'Penerimaan Dana', 'icon' => 'cil-arrow-circle-bottom', 'route' => 'penerimaan.index', 'can' => 'penerimaan.lihat'],
    ['label' => 'Pengeluaran', 'icon' => 'cil-arrow-circle-top', 'route' => 'pengeluaran.index', 'can' => 'pengeluaran.lihat'],
    ['label' => 'Jurnal Umum', 'icon' => 'cil-book', 'route' => 'jurnal.index', 'can' => 'jurnal.lihat'],
    ['label' => 'Rekonsiliasi Bank', 'icon' => 'cil-sync', 'route' => 'rekonsiliasi.index', 'can' => 'rekonsiliasi.kelola'],

    ['title' => 'Laporan'],
    [
        'label' => 'Laporan', 'icon' => 'cil-chart-line', 'can' => 'laporan.lihat',
        'children' => [
            ['label' => 'Jurnal Umum', 'route' => 'laporan.jurnal'],
            ['label' => 'Tunggakan Mahasiswa', 'route' => 'laporan.tunggakan'],
            ['label' => 'Capaian Anggaran', 'route' => 'laporan.capaian'],
            ['label' => 'Laba Rugi', 'route' => 'laporan.laba-rugi'],
            ['label' => 'Arus Kas', 'route' => 'laporan.arus-kas'],
        ],
    ],

    ['title' => 'Data Master'],
    [
        'label' => 'Data Master', 'icon' => 'cil-storage', 'can' => 'master.lihat',
        'children' => [
            ['label' => 'Program Studi', 'route' => 'master.prodi.index'],
            ['label' => 'Mahasiswa', 'route' => 'master.mahasiswa.index'],
            ['label' => 'Tahun Anggaran', 'route' => 'master.tahun.index'],
            ['label' => 'Chart of Accounts', 'route' => 'master.coa.index'],
            ['label' => 'Komponen Biaya', 'route' => 'master.komponen.index'],
            ['label' => 'Tarif Biaya', 'route' => 'master.tarif.index'],
        ],
    ],

    ['title' => 'Sistem'],
    ['label' => 'Import GoFeeder', 'icon' => 'cil-cloud-upload', 'route' => 'import.index', 'can' => 'import.sia'],
    ['label' => 'Tutup Tahun', 'icon' => 'cil-lock-locked', 'route' => 'tahun.tutup', 'can' => 'tahun.tutup'],
    ['label' => 'Pengguna & Role', 'icon' => 'cil-people', 'route' => 'user.index', 'can' => 'user.kelola'],
];
