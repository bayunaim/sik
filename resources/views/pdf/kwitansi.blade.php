@php
    $pathLogo = public_path('img/logo_stok.png');
    $logo = is_file($pathLogo) ? 'data:image/png;base64,' . base64_encode(file_get_contents($pathLogo)) : null;
    $lunas = $p->tagihan->status === 'lunas';
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Kwitansi {{ $p->nomor_kwitansi }}</title>
    <style>
        @page {
            margin: 22px 30px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            color: #1f2937;
            margin: 0;
        }

        table {
            border-collapse: collapse;
        }

        /* Kop surat */
        .kop {
            width: 100%;
        }

        .kop td {
            vertical-align: middle;
        }

        .kop .logo {
            width: 70px;
        }

        .kop .logo img {
            width: 62px;
            height: auto;
        }

        .kop .teks {
            text-align: center;
            padding-right: 70px;
        }

        .kop h2 {
            margin: 0;
            font-size: 17px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .kop p {
            margin: 2px 0 0;
            font-size: 10px;
            color: #4b5563;
        }

        .garis {
            border-top: 3px solid #1f2937;
            border-bottom: 1px solid #1f2937;
            height: 2px;
            margin: 6px 0 12px;
        }

        /* Judul */
        .judul {
            width: 100%;
            margin-bottom: 10px;
        }

        .judul td {
            vertical-align: middle;
        }

        .judul .kiri {
            width: 33%;
        }

        .judul .tengah {
            width: 34%;
            text-align: center;
        }

        .judul .kanan {
            width: 33%;
            text-align: right;
        }

        .judul h3 {
            margin: 0;
            font-size: 16px;
            letter-spacing: 4px;
        }

        .judul .nomor {
            font-size: 10px;
            margin-top: 2px;
            color: #374151;
        }

        .status {
            display: inline-block;
            border: 2px solid #15803d;
            color: #15803d;
            padding: 3px 12px;
            font-weight: bold;
            font-size: 12px;
            letter-spacing: 2px;
        }

        .status.sebagian {
            border-color: #b45309;
            color: #b45309;
        }

        /* Data */
        table.info {
            width: 100%;
        }

        table.info td {
            padding: 3px 4px;
            vertical-align: top;
            border-bottom: 1px dotted #d1d5db;
        }

        table.info td.l {
            width: 24%;
            color: #4b5563;
        }

        table.info td.t {
            width: 2%;
        }

        table.info tr:last-child td {
            border-bottom: none;
        }

        /* Jumlah */
        .jumlah {
            width: 100%;
            margin-top: 10px;
            background: #f3f4f6;
            border: 1px solid #1f2937;
        }

        .jumlah td {
            padding: 8px 12px;
            vertical-align: middle;
        }

        .jumlah .label {
            width: 26%;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #4b5563;
        }

        .jumlah .nilai {
            font-size: 18px;
            font-weight: bold;
        }

        .terbilang {
            margin-top: 6px;
            font-style: italic;
        }

        .terbilang b {
            font-style: normal;
        }

        /* Tanda tangan */
        .ttd {
            width: 100%;
            margin-top: 12px;
        }

        .ttd td {
            vertical-align: top;
        }

        .ttd .kiri {
            width: 60%;
            font-size: 9px;
            color: #6b7280;
            padding-right: 20px;
        }

        .ttd .kanan {
            width: 40%;
            text-align: center;
        }

        .ttd .nama {
            margin-top: 44px;
            font-weight: bold;
            text-decoration: underline;
        }
    </style>
</head>

<body>
    {{-- Kop --}}
    <table class="kop">
        <tr>
            <td class="logo">
                @if ($logo)
                    <img src="{{ $logo }}" alt="Logo">
                @endif
            </td>
            <td class="teks">
                <h2>{{ config('app.name') }}</h2>
                <p>Bagian Keuangan</p>
            </td>
        </tr>
    </table>
    <div class="garis"></div>

    {{-- Judul --}}
    <table class="judul">
        <tr>
            <td class="kiri"></td>
            <td class="tengah">
                <h3>KWITANSI</h3>
                <div class="nomor">No. {{ $p->nomor_kwitansi }}</div>
            </td>
            <td class="kanan">
                <span class="status {{ $lunas ? '' : 'sebagian' }}">{{ $lunas ? 'LUNAS' : 'CICILAN' }}</span>
            </td>
        </tr>
    </table>

    {{-- Data --}}
    <table class="info">
        <tr>
            <td class="l">Telah diterima dari</td>
            <td class="t">:</td>
            <td><b>{{ $p->mahasiswa->nama }}</b> (NIM {{ $p->mahasiswa->nim }})</td>
        </tr>
        <tr>
            <td class="l">Untuk pembayaran</td>
            <td class="t">:</td>
            <td>{{ $p->tagihan->komponen->nama }} - {{ $p->tagihan->semester }} {{ $p->tagihan->tahun_ajaran }}</td>
        </tr>
        <tr>
            <td class="l">Nomor tagihan</td>
            <td class="t">:</td>
            <td>{{ $p->tagihan->nomor }}</td>
        </tr>
        <tr>
            <td class="l">Tanggal bayar</td>
            <td class="t">:</td>
            <td>{{ $p->tanggal_bayar->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="l">Metode</td>
            <td class="t">:</td>
            <td>{{ $p->metode }}{{ $p->referensi ? ' (' . $p->referensi . ')' : '' }}</td>
        </tr>
        <tr>
            <td class="l">Total tagihan</td>
            <td class="t">:</td>
            <td>Rp {{ number_format($p->tagihan->nominal, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="l">Total terbayar</td>
            <td class="t">:</td>
            <td>Rp {{ number_format($p->tagihan->terbayar, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="l">Sisa tagihan</td>
            <td class="t">:</td>
            <td>Rp {{ number_format($p->tagihan->sisa, 0, ',', '.') }}</td>
        </tr>
    </table>

    {{-- Jumlah --}}
    <table class="jumlah">
        <tr>
            <td class="label">Jumlah dibayar</td>
            <td class="nilai">Rp {{ number_format($p->nominal, 0, ',', '.') }}</td>
        </tr>
    </table>
    <div class="terbilang"><b>Terbilang:</b> {{ \App\Support\Terbilang::rupiah($p->nominal) }}</div>

    {{-- Tanda tangan --}}
    <table class="ttd">
        <tr>
            <td class="kiri">
                Kwitansi ini sah dan dibuat oleh sistem setelah pembayaran diverifikasi.<br>
                Dicetak {{ now()->format('d/m/Y H:i') }}.
            </td>
            <td class="kanan">
                {{ $p->diverifikasi_pada?->format('d/m/Y') }}<br>
                Bagian Keuangan
                <div class="nama">{{ $p->pemverifikasi?->name }}</div>
            </td>
        </tr>
    </table>
</body>

</html>
