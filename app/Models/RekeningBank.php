<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RekeningBank extends Model
{
    use LogsActivity;

    protected $table = 'rekening_bank';

    protected $fillable = ['nama_bank', 'nomor', 'atas_nama', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function mutasi()
    {
        return $this->hasMany(MutasiBank::class);
    }

    public function getLabelAttribute(): string
    {
        return "{$this->nama_bank} - {$this->nomor} (a.n. {$this->atas_nama})";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nama_bank', 'nomor', 'atas_nama', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('rekening_bank');
    }
}
