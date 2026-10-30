@php
    use App\Services\LaporanService as L;
    $pathLogo = public_path('img/logo_stok.png');
    $logo = is_file($pathLogo) ? 'data:image/png;base64,' . base64_encode(file_get_contents($pathLogo)) : null;
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>{{ $lap['judul'] }}</title>
    <style>
        @page {
            margin: 24px 28px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5px;
            color: #1f2937;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .kop td {
            vertical-align: middle;
        }

        .kop h2 {
            margin: 0;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .kop p {
            margin: 2px 0 0;
            font-size: 9px;
            color: #4b5563;
        }

        .garis {
            border-top: 3px solid #1f2937;
            border-bottom: 1px solid #1f2937;
            height: 2px;
            margin: 6px 0 10px;
        }

        h3 {
            margin: 0 0 2px;
            font-size: 13px;
        }

        .periode {
            margin-bottom: 8px;
            color: #374151;
        }

        table.data th {
            background: #e5e7eb;
            border: 1px solid #9ca3af;
            padding: 4px;
            text-align: left;
        }

        table.data td {
            border: 1px solid #d1d5db;
            padding: 3px 4px;
            vertical-align: top;
        }

        table.data thead {
            display: table-header-group;
        }

        table.data tr {
            page-break-inside: avoid;
        }

        .r {
            text-align: right;
        }

        .total td {
            font-weight: bold;
            background: #f3f4f6;
            border: 1px solid #9ca3af;
        }

        .info {
            margin-top: 8px;
            font-size: 8px;
            color: #4b5563;
        }

        .foot {
            margin-top: 12px;
            font-size: 8px;
            color: #6b7280;
        }
    </style>
</head>

<body>
    <table class="kop">
        <tr>
            <td style="width: 60px;">
                @if ($logo)
                    <img src="{{ $logo }}" style="width: 52px;">
                @endif
            </td>
            <td style="text-align: center; padding-right: 60px;">
                <h2>{{ config('app.name') }}</h2>
                <p>Bagian Keuangan</p>
            </td>
        </tr>
    </table>
    <div class="garis"></div>

    <h3>{{ $lap['judul'] }}</h3>
    <div class="periode">{{ $lap['periode'] }}</div>

    <table class="data">
        <thead>
            <tr>
                @foreach ($lap['kolom'] as $k)
                    <th class="{{ in_array($k['tipe'], ['uang', 'angka', 'persen']) ? 'r' : '' }}">{{ $k['label'] }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($lap['baris'] as $b)
                <tr>
                    @foreach ($lap['kolom'] as $k)
                        <td class="{{ in_array($k['tipe'], ['uang', 'angka', 'persen']) ? 'r' : '' }}">
                            {{ L::format($k['tipe'], $b[$k['key']] ?? null) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($lap['kolom']) }}" style="text-align: center;">Tidak ada data.</td>
                </tr>
            @endforelse
            @foreach ($lap['total'] as $t)
                <tr class="total">
                    @foreach ($lap['kolom'] as $i => $k)
                        <td class="{{ in_array($k['tipe'], ['uang', 'angka', 'persen']) ? 'r' : '' }}">
                            {{ $i === 0 ? $t['label'] : L::format($k['tipe'], $t['nilai'][$k['key']] ?? null) }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($lap['info'])
        <div class="info">{{ $lap['info'] }}</div>
    @endif
    <div class="foot">Dicetak oleh {{ $oleh }} pada {{ now()->format('d/m/Y H:i') }}.</div>
</body>

</html>
