<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Pengeluaran extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'pengeluaran';

    protected $fillable = [
        'nomor', 'tanggal', 'anggaran_id', 'tahun_anggaran_id', 'akun_beban_id', 'keperluan',
        'nominal', 'metode', 'lampiran_path', 'status', 'alasan_tolak',
        'diajukan_oleh', 'diproses_oleh', 'diproses_pada',
    ];

    public const PENDING = 'pending';
    public const DISETUJUI = 'disetujui';
    public const DITOLAK = 'ditolak';

    public const STATUS = [
        self::PENDING => 'Pending',
        self::DISETUJUI => 'Disetujui',
        self::DITOLAK => 'Ditolak',
    ];

    public const METODE = ['Transfer Bank', 'Tunai'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'nominal' => 'decimal:2',
            'diproses_pada' => 'datetime',
        ];
    }

    public function anggaran()
    {
        return $this->belongsTo(Anggaran::class)->withTrashed();
    }

    public function tahun()
    {
        return $this->belongsTo(TahunAnggaran::class, 'tahun_anggaran_id');
    }

    public function akunBeban()
    {
        return $this->belongsTo(ChartOfAccount::class, 'akun_beban_id')->withTrashed();
    }

    public function pengaju()
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function pemroses()
    {
        return $this->belongsTo(User::class, 'diproses_oleh');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nomor', 'tanggal', 'anggaran_id', 'akun_beban_id', 'keperluan',
                'nominal', 'metode', 'status', 'alasan_tolak', 'diproses_oleh'])
            ->logOnlyDirty()
            ->useLogName('pengeluaran');
    }
}
