<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Pembayaran extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'pembayaran';

    protected $fillable = [
        'nomor_kwitansi', 'tagihan_id', 'mahasiswa_id', 'tanggal_bayar', 'nominal', 'metode',
        'referensi', 'bukti_path', 'status', 'alasan_tolak',
        'diinput_oleh', 'diverifikasi_oleh', 'diverifikasi_pada',
    ];

    public const PENDING = 'pending';
    public const TERVERIFIKASI = 'terverifikasi';
    public const DITOLAK = 'ditolak';

    public const STATUS = [
        self::PENDING => 'Pending',
        self::TERVERIFIKASI => 'Terverifikasi',
        self::DITOLAK => 'Ditolak',
    ];

    public const METODE = ['Transfer Bank', 'Tunai'];

    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'tanggal_bayar' => 'date',
            'diverifikasi_pada' => 'datetime',
        ];
    }

    public function tagihan()
    {
        return $this->belongsTo(Tagihan::class)->withTrashed();
    }

    public function mahasiswa()
    {
        return $this->belongsTo(Mahasiswa::class)->withTrashed();
    }

    public function penginput()
    {
        return $this->belongsTo(User::class, 'diinput_oleh');
    }

    public function pemverifikasi()
    {
        return $this->belongsTo(User::class, 'diverifikasi_oleh');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nomor_kwitansi', 'tagihan_id', 'tanggal_bayar', 'nominal', 'metode',
                'status', 'alasan_tolak', 'diverifikasi_oleh'])
            ->logOnlyDirty()
            ->useLogName('pembayaran');
    }
}
