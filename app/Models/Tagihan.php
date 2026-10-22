<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Tagihan extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'tagihan';

    protected $fillable = [
        'nomor', 'mahasiswa_id', 'komponen_biaya_id', 'anggaran_id', 'tahun_anggaran_id',
        'semester', 'tahun_ajaran', 'nominal', 'terbayar', 'tanggal_tagihan', 'jatuh_tempo',
        'metode_bayar', 'keterangan', 'status', 'kunci_unik', 'dibuat_oleh',
        'alasan_batal', 'dibatalkan_pada', 'dibatalkan_oleh',
    ];

    public const BELUM_BAYAR = 'belum_bayar';
    public const SEBAGIAN = 'sebagian';
    public const PENDING = 'pending';
    public const LUNAS = 'lunas';

    public const STATUS = [
        self::BELUM_BAYAR => 'Belum Bayar',
        self::SEBAGIAN => 'Sebagian',
        self::PENDING => 'Pending',
        self::LUNAS => 'Lunas',
    ];

    public const SEMESTER = ['Ganjil', 'Genap', 'Pendek'];

    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'terbayar' => 'decimal:2',
            'tanggal_tagihan' => 'date',
            'jatuh_tempo' => 'date',
            'dibatalkan_pada' => 'datetime',
        ];
    }

    public function mahasiswa()
    {
        return $this->belongsTo(Mahasiswa::class)->withTrashed();
    }

    public function komponen()
    {
        return $this->belongsTo(KomponenBiaya::class, 'komponen_biaya_id')->withTrashed();
    }

    public function anggaran()
    {
        return $this->belongsTo(Anggaran::class)->withTrashed();
    }

    public function tahun()
    {
        return $this->belongsTo(TahunAnggaran::class, 'tahun_anggaran_id');
    }

    // Sisa tagihan sebagai string desimal, dihitung tanpa float
    public function getSisaAttribute(): string
    {
        $sisa = BigDecimal::of($this->nominal)->minus($this->terbayar);

        return (string) ($sisa->isNegative() ? BigDecimal::zero()->toScale(2) : $sisa->toScale(2));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nomor', 'mahasiswa_id', 'komponen_biaya_id', 'anggaran_id', 'semester',
                'tahun_ajaran', 'nominal', 'terbayar', 'jatuh_tempo', 'status', 'alasan_batal'])
            ->logOnlyDirty()
            ->useLogName('tagihan');
    }
}
