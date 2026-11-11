@php
    $pathLogo = public_path('img/logo_stok.png');
    $logoKop = is_file($pathLogo) ? 'data:image/png;base64,' . base64_encode(file_get_contents($pathLogo)) : null;
@endphp
<table style="width:100%; border-collapse:collapse;">
    <tr>
        <td style="width:70px; vertical-align:middle;">
            @if ($logoKop)
                <img src="{{ $logoKop }}" alt="Logo" style="width:62px; height:auto;">
            @endif
        </td>
        <td style="text-align:center; padding-right:70px; vertical-align:middle;">
            <div style="margin:0; font-size:17px; letter-spacing:1px; text-transform:uppercase; font-weight:bold;">
                {{ config('app.name') }}</div>
            <div style="margin-top:2px; font-size:10px;">Bagian Keuangan</div>
        </td>
    </tr>
</table>
<div style="border-top:2px solid #1f2937; margin:6px 0 10px;"></div>
