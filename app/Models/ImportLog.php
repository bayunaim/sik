<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    protected $table = 'import_log';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['mapping' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
