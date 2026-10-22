<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ChartOfAccount extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'chart_of_accounts';

    protected $fillable = ['kode', 'nama', 'tipe', 'parent_id', 'is_active'];

    public const TIPE = [
        'aset' => 'Aset',
        'kewajiban' => 'Kewajiban',
        'ekuitas' => 'Ekuitas',
        'pendapatan' => 'Pendapatan',
        'beban' => 'Beban',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kode', 'nama', 'tipe', 'parent_id', 'is_active'])
            ->logOnlyDirty()
            ->useLogName('chart_of_accounts');
    }
}
