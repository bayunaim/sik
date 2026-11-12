<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MutasiImport extends Model
{
    protected $table = 'mutasi_import';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'tanggal_awal' => 'date', 'tanggal_akhir' => 'date'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rekening()
    {
        return $this->belongsTo(RekeningBank::class, 'rekening_bank_id');
    }
}
