<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class KomponenBiaya extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'komponen_biaya';

    protected $fillable = ['kode', 'nama', 'jenis', 'akun_pendapatan_id', 'anggaran_id', 'is_active'];

    public const JENIS = [
        'pendaftaran' => 'Pendaftaran',
        'spp_ukt' => 'SPP/UKT',
        'semester' => 'Biaya Semester',
        'praktikum' => 'Praktikum',
        'ujian' => 'Ujian',
        'wisuda' => 'Wisuda',
        'lainnya' => 'Lainnya',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function akunPendapatan()
    {
        return $this->belongsTo(ChartOfAccount::class, 'akun_pendapatan_id');
    }

    public function tarif()
    {
        return $this->hasMany(TarifBiaya::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kode', 'nama', 'jenis', 'akun_pendapatan_id', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('komponen_biaya');
    }
}
