<?php

use App\Models\Tagihan;
use App\Services\TagihanService;
use Illuminate\Support\Facades\DB;

it('massal: penomoran hanya menyentuh baris batch ini, bukan semua baris tanpa nomor', function () {
    buatTarif('3000000');
    $admin = userDengan('Admin Keuangan');

    // baris lain yang kebetulan belum bernomor (misalnya penulisan lain yang belum selesai)
    $lain = buatTagihan(buatMahasiswa());
    DB::table('tagihan')->where('id', $lain->id)->update(['nomor' => null]);

    $ids = [buatMahasiswa()->id, buatMahasiswa()->id];
    app(TagihanService::class)->buatMassal(paramMassal(), $ids, $admin);

    expect(Tagihan::whereIn('mahasiswa_id', $ids)->whereNull('nomor')->count())->toBe(0)
        ->and(Tagihan::find($lain->id)->nomor)->toBeNull();
});
