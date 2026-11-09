<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportTemplate extends Model
{
    protected $table = 'import_template';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['mapping' => 'array'];
    }
}
