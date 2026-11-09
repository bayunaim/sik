<?php

namespace App\Http\Controllers;

use App\Models\ImportLog;
use App\Services\ImportMahasiswaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    public function error(Request $request, ImportLog $log, ImportMahasiswaService $svc)
    {
        abort_unless($request->user()->can('import.sia'), 403);

        $nama = 'baris-error-import-'.$log->id.'.csv';

        // sudah selesai: pakai CSV yang tersimpan
        if ($log->error_path && Storage::disk('local')->exists($log->error_path)) {
            return Storage::disk('local')->download($log->error_path, $nama);
        }

        // masih pratinjau: hitung ulang dari file sumber
        abort_unless($log->status === 'pratinjau' && $log->file_path && $log->mapping === null || $log->status === 'pratinjau', 404);
        abort_unless($log->user_id === $request->user()->id && $log->file_path, 404);

        $mapping = $svc->tebakPemetaan($svc->baca($log->file_path)['header'], \App\Models\ImportTemplate::where('nama', 'gofeeder')->value('mapping'));
        $a = $svc->analisis($svc->baca($log->file_path), $mapping);

        return response()->streamDownload(fn () => print($svc->csvError($a['error'])), $nama, ['Content-Type' => 'text/csv']);
    }
}
