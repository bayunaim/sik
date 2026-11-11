@php
    $batal = $t->trashed();
    $warna = $t->status === 'lunas' ? '#15803d' : ($t->status === 'sebagian' ? '#b45309' : '#374151');
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Tagihan {{ $t->nomor }}</title>
    <style>
        @page { margin: 22px 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; }
        table { border-collapse: collapse; }
        h3 { margin: 0; font-size: 14px; letter-spacing: 3px; text-align: center; }
        .nomor { text-align: center; font-size: 10px; margin: 2px 0 8px; color: #374151; }
        .status { display: inline-block; border: 2px solid {{ $warna }}; color: {{ $warna }}; padding: 2px 10px; font-weight: bold; letter-spacing: 2px; }
        table.info { width: 100%; }
        table.info td { padding: 3px 4px; vertical-align: top; border-bottom: 1px dotted #d1d5db; }
        table.info td.l { width: 24%; color: #4b5563; }
        table.info td.t { width: 2%; }
        .jumlah { width: 100%; margin-top: 10px; background: #f3f4f6; border: 1px solid #1f2937; }
        .jumlah td { padding: 8px 12px; }
        .jumlah .nilai { font-size: 16px; font-weight: bold; }
        .catatan { margin-top: 10px; font-size: 9px; color: #6b7280; }
    </style>
</head>

<body>
    @include('pdf._kop')
    <h3>TAGIHAN</h3>
    <div class="nomor">No. {{ $t->nomor }}</div>

    <table class="info">
        <tr>
            <td class="l">Mahasiswa</td><td class="t">:</td>
            <td><b>{{ $t->mahasiswa->nama }}</b> (NIM {{ $t->mahasiswa->nim }})</td>
        </tr>
        <tr>
            <td class="l">Program studi</td><td class="t">:</td>
            <td>{{ $t->mahasiswa->programStudi?->nama }} | Angkatan {{ $t->mahasiswa->angkatan }}</td>
        </tr>
        <tr>
            <td class="l">Komponen biaya</td><td class="t">:</td>
            <td>{{ $t->komponen?->nama }} - {{ $t->semester }} {{ $t->tahun_ajaran }}</td>
        </tr>
        <tr>
            <td class="l">Tanggal tagihan</td><td class="t">:</td>
            <td>{{ $t->tanggal_tagihan->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="l">Jatuh tempo</td><td class="t">:</td>
            <td>{{ $t->jatuh_tempo->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="l">Terbayar</td><td class="t">:</td>
            <td>Rp {{ number_format($t->terbayar, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="l">Sisa</td><td class="t">:</td>
            <td>Rp {{ number_format($t->sisa, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="l">Status</td><td class="t">:</td>
            <td><span class="status">{{ strtoupper(\App\Models\Tagihan::STATUS[$t->status] ?? $t->status) }}</span></td>
        </tr>
    </table>

    <table class="jumlah">
        <tr>
            <td style="width:26%; font-size:9px; text-transform:uppercase; letter-spacing:1px; color:#4b5563;">Jumlah tagihan</td>
            <td class="nilai">Rp {{ number_format($t->nominal, 0, ',', '.') }}</td>
        </tr>
    </table>
    <div style="margin-top:6px; font-style:italic;"><b style="font-style:normal;">Terbilang:</b>
        {{ \App\Support\Terbilang::rupiah($t->nominal) }}</div>

    <div class="catatan">
        Mohon lakukan pembayaran sebelum jatuh tempo dan unggah bukti pembayaran melalui portal mahasiswa
        atau serahkan ke Bagian Keuangan. Dicetak {{ now()->format('d/m/Y H:i') }}.
    </div>
</body>

</html>
