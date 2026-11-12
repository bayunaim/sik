<?php

namespace App\Http\Controllers;

use App\Models\MutasiImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RekonsiliasiController extends Controller
{
    public function error(Request $request, MutasiImport $impor)
    {
        abort_unless($request->user()->can('rekonsiliasi.kelola'), 403);
        abort_unless($impor->error_path && Storage::disk('local')->exists($impor->error_path), 404);

        return Storage::disk('local')->download($impor->error_path, 'baris-error-mutasi-'.$impor->id.'.csv');
    }
}
