<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class TahunAnggaran extends Model
{
    use LogsActivity;

    protected $table = 'tahun_anggaran';

    protected $fillable = ['nama', 'tanggal_mulai', 'tanggal_selesai', 'status'];

    public const DRAFT = 'draft';
    public const AKTIF = 'aktif';
    public const DITUTUP = 'ditutup';

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
        ];
    }

    public function scopeAktif(Builder $q): Builder
    {
        return $q->where('status', self::AKTIF);
    }

    // Dipakai modul lain: TahunAnggaran::aktifSekarang()
    public static function aktifSekarang(): ?self
    {
        return static::aktif()->first();
    }

    public function isDitutup(): bool
    {
        return $this->status === self::DITUTUP;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nama', 'tanggal_mulai', 'tanggal_selesai', 'status'])
            ->logOnlyDirty()
            ->useLogName('tahun_anggaran');
    }
}
