<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Mahasiswa extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'mahasiswa';

    protected $fillable = [
        'nim', 'nama', 'program_studi_id', 'angkatan',
        'status_akademik', 'semester_aktif', 'sia_id', 'user_id',
    ];

    public const STATUS = ['Aktif', 'Cuti', 'Lulus', 'Nonaktif'];
    public const STATUS_TIDAK_ADA_DI_IMPORT = 'Tidak Ada di Import';

    public function programStudi()
    {
        return $this->belongsTo(ProgramStudi::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nim', 'nama', 'program_studi_id', 'angkatan', 'status_akademik', 'semester_aktif', 'sia_id'])
            ->logOnlyDirty()
            ->useLogName('mahasiswa');
    }
}
