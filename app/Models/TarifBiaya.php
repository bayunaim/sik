<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class TarifBiaya extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'tarif_biaya';

    protected $fillable = ['komponen_biaya_id', 'program_studi_id', 'angkatan', 'nominal'];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2'];
    }

    public function komponen()
    {
        return $this->belongsTo(KomponenBiaya::class, 'komponen_biaya_id');
    }

    public function programStudi()
    {
        return $this->belongsTo(ProgramStudi::class);
    }

    /**
     * Cari tarif paling spesifik untuk satu mahasiswa.
     * Prioritas: (prodi + angkatan) > prodi saja > angkatan saja > umum.
     */
    public static function cari(int $komponenId, ?int $prodiId, ?int $angkatan): ?self
    {
        return static::query()
            ->where('komponen_biaya_id', $komponenId)
            ->where(fn ($q) => $q->whereNull('program_studi_id')
                ->when($prodiId, fn ($q) => $q->orWhere('program_studi_id', $prodiId)))
            ->where(fn ($q) => $q->whereNull('angkatan')
                ->when($angkatan, fn ($q) => $q->orWhere('angkatan', $angkatan)))
            ->orderByRaw('(program_studi_id is not null) desc, (angkatan is not null) desc')
            ->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['komponen_biaya_id', 'program_studi_id', 'angkatan', 'nominal'])
            ->logOnlyDirty()
            ->useLogName('tarif_biaya');
    }
}
