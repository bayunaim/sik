<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class Jurnal extends Model
{
    protected $table = 'jurnal';

    protected $fillable = [
        'nomor', 'tanggal', 'tahun_anggaran_id', 'jenis', 'keterangan',
        'sumber_type', 'sumber_id', 'kunci_sumber', 'jurnal_asal_id',
        'total_debit', 'total_kredit', 'dibuat_oleh',
    ];

    public const OTOMATIS = 'otomatis';
    public const PENYESUAIAN = 'penyesuaian';
    public const PEMBALIK = 'pembalik';

    // Jurnal tidak boleh diubah atau dihapus (FR-072, aturan bisnis #4)
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Jurnal tidak dapat diubah. Lakukan koreksi dengan jurnal pembalik.'));
        static::deleting(fn () => throw new LogicException('Jurnal tidak dapat dihapus. Lakukan koreksi dengan jurnal pembalik.'));
    }

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'total_debit' => 'decimal:2',
            'total_kredit' => 'decimal:2',
        ];
    }

    public function detail()
    {
        return $this->hasMany(JurnalDetail::class);
    }

    public function sumber()
    {
        return $this->morphTo();
    }

    public function pembuat()
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}
