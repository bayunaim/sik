<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Anggaran extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'anggaran';

    protected $fillable = [
        'tahun_anggaran_id', 'kode', 'tipe', 'nama', 'sub_anggaran', 'jumlah',
        'status', 'catatan', 'dibuat_oleh', 'disetujui_oleh', 'disetujui_pada',
    ];

    public const DRAFT = 'draft';
    public const APPROVED = 'approved';

    public const TIPE = ['D' => 'Penerimaan', 'K' => 'Pengeluaran'];

    protected function casts(): array
    {
        return [
            'jumlah' => 'decimal:2',
            'disetujui_pada' => 'datetime',
        ];
    }

    public function tahun()
    {
        return $this->belongsTo(TahunAnggaran::class, 'tahun_anggaran_id');
    }

    public function pembuat()
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function penyetuju()
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tahun_anggaran_id', 'kode', 'nama', 'sub_anggaran', 'jumlah', 'status', 'catatan'])
            ->logOnlyDirty()
            ->useLogName('anggaran');
    }
}
