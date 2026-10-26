<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Kwitansi {{ $p->nomor_kwitansi }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #222;
        }

        .kop {
            text-align: center;
            border-bottom: 2px solid #222;
            padding-bottom: 8px;
            margin-bottom: 16px;
        }

        .kop h2 {
            margin: 0;
            font-size: 18px;
        }

        .kop p {
            margin: 2px 0 0;
            font-size: 11px;
        }

        h3 {
            text-align: center;
            margin: 0 0 4px;
            letter-spacing: 2px;
        }

        .nomor {
            text-align: center;
            margin-bottom: 16px;
        }

        table.info {
            width: 100%;
            border-collapse: collapse;
        }

        table.info td {
            padding: 5px 4px;
            vertical-align: top;
        }

        table.info td.l {
            width: 28%;
        }

        table.info td.t {
            width: 3%;
        }

        .jumlah {
            border: 1px solid #222;
            padding: 10px;
            margin: 16px 0;
            font-size: 16px;
            font-weight: bold;
            text-align: center;
        }

        .ttd {
            width: 100%;
            margin-top: 28px;
        }

        .ttd td {
            width: 50%;
            text-align: center;
            vertical-align: top;
        }

        .catatan {
            font-size: 10px;
            color: #666;
            margin-top: 24px;
        }
    </style>
</head>

<body>
    <div class="kop">
        <h2>{{ config('app.name') }}</h2>
        <p>Bagian Keuangan</p>
    </div>

    <h3>KWITANSI</h3>
    <div class="nomor">No. {{ $p->nomor_kwitansi }}</div>

    <table class="info">
        <tr>
            <td class="l">Sudah terima dari</td>
            <td class="t">:</td>
            <td>{{ $p->mahasiswa->nama }} (NIM {{ $p->mahasiswa->nim }})</td>
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

    <div class="jumlah">Jumlah dibayar: Rp {{ number_format($p->nominal, 0, ',', '.') }}</div>

    <table class="ttd">
        <tr>
            <td></td>
            <td>
                {{ $p->diverifikasi_pada?->format('d/m/Y') }}<br>
                Bagian Keuangan<br><br><br><br>
                ( {{ $p->pemverifikasi?->name }} )
            </td>
        </tr>
    </table>

    <div class="catatan">Kwitansi ini sah dan dibuat oleh sistem setelah pembayaran diverifikasi. Dicetak
        {{ now()->format('d/m/Y H:i') }}.</div>
</body>

</html>
