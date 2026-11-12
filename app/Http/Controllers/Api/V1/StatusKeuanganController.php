<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StatusKeuanganService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-152: endpoint mesin-ke-mesin untuk SIA. Hanya token ber-ability status-keuangan:baca. */
class StatusKeuanganController extends Controller
{
    public const ABILITY = 'status-keuangan:baca';

    public function show(Request $request, string $nim, StatusKeuanganService $svc): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && $user->tokenCan(self::ABILITY), 403, 'Token tidak berhak membaca status keuangan.');

        $data = $svc->untukNim($nim);
        abort_if($data === null, 404, 'Mahasiswa tidak ditemukan.');

        return response()->json(['data' => $data]);
    }
}
