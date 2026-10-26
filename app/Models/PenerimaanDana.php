<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PenerimaanDana extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'penerimaan_dana';

    protected $fillable = [
        'pembayaran_id', 'sumber', 'anggaran_id', 'tahun_anggaran_id',
        'tanggal', 'nominal', 'metode', 'keterangan', 'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return ['tanggal' => 'date', 'nominal' => 'decimal:2'];
    }

    public function pembayaran()
    {
        return $this->belongsTo(Pembayaran::class)->withTrashed();
    }

    public function anggaran()
    {
        return $this->belongsTo(Anggaran::class)->withTrashed();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sumber', 'anggaran_id', 'tanggal', 'nominal', 'metode', 'keterangan'])
            ->logOnlyDirty()
            ->useLogName('penerimaan_dana');
    }
}
