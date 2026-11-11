<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Surat Keterangan Lunas {{ $m->nim }}</title>
    <style>
        @page { margin: 40px 50px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; line-height: 1.6; }
        table { border-collapse: collapse; }
        h3 { margin: 18px 0 0; text-align: center; font-size: 15px; text-decoration: underline; letter-spacing: 1px; }
        .nomor { text-align: center; font-size: 11px; margin-bottom: 18px; }
        table.data td { padding: 2px 6px; vertical-align: top; }
        table.data td.l { width: 130px; }
        table.rinci { width: 100%; margin: 10px 0; }
        table.rinci td { border: 1px solid #9ca3af; padding: 5px 8px; }
        table.rinci td.n { text-align: right; width: 35%; }
        .ttd { margin-top: 36px; width: 100%; }
        .ttd td { text-align: center; width: 45%; }
        .ttd .nama { margin-top: 60px; font-weight: bold; text-decoration: underline; }
        .catatan { margin-top: 26px; font-size: 9.5px; color: #6b7280; }
    </style>
</head>

<body>
    @include('pdf._kop')
    <h3>SURAT KETERANGAN LUNAS</h3>
    <div class="nomor">No. SKL/{{ $m->nim }}/{{ $tanggal->format('Ymd') }}</div>

    <p>Yang bertanda tangan di bawah ini, Bagian Keuangan {{ config('app.name') }}, menerangkan bahwa:</p>
    <table class="data">
        <tr><td class="l">Nama</td><td>:</td><td><b>{{ $m->nama }}</b></td></tr>
        <tr><td class="l">NIM</td><td>:</td><td>{{ $m->nim }}</td></tr>
        <tr><td class="l">Program studi</td><td>:</td><td>{{ $m->programStudi?->nama }}</td></tr>
        <tr><td class="l">Angkatan</td><td>:</td><td>{{ $m->angkatan }}</td></tr>
    </table>

    <p>telah menyelesaikan seluruh kewajiban pembayaran yang tercatat pada sistem keuangan kampus
        per tanggal {{ $tanggal->format('d/m/Y') }}, dengan rincian:</p>
    <table class="rinci">
        <tr><td>Jumlah tagihan</td><td class="n">{{ $r['jumlah'] }}</td></tr>
        <tr><td>Total tagihan</td><td class="n">Rp {{ number_format($r['total'], 0, ',', '.') }}</td></tr>
        <tr><td>Total terbayar</td><td class="n">Rp {{ number_format($r['terbayar'], 0, ',', '.') }}</td></tr>
        <tr><td><b>Tunggakan</b></td><td class="n"><b>Rp 0</b></td></tr>
    </table>

    <p>Surat keterangan ini dibuat untuk digunakan sebagai bukti kepada bagian akademik.</p>

    <table class="ttd">
        <tr>
            <td></td>
            <td>
                {{ $tanggal->format('d/m/Y') }}<br>
                Bagian Keuangan
                <div class="nama">{{ auth()->user()?->name }}</div>
            </td>
        </tr>
    </table>

    <div class="catatan">
        Surat ini dibuat otomatis oleh sistem dan berlaku untuk kondisi pada tanggal tercetak.
        Tagihan baru yang terbit setelahnya tidak tercakup. Dicetak {{ now()->format('d/m/Y H:i') }}.
    </div>
</body>

</html>
