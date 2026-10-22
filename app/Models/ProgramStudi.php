<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProgramStudi extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'program_studi';

    protected $fillable = ['kode', 'nama', 'jenjang', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function mahasiswa()
    {
        return $this->hasMany(Mahasiswa::class);
    }

    // Audit log: siapa, kapan, data sebelum & sesudah
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kode', 'nama', 'jenjang', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('program_studi');
    }
}
