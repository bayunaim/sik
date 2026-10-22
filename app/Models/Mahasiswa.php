<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mahasiswa extends Model
{
    use SoftDeletes;

    protected $table = 'mahasiswa';

    protected $fillable = [
        'nim', 'nama', 'program_studi_id', 'angkatan',
        'status_akademik', 'semester_aktif', 'sia_id', 'user_id',
    ];
}
