<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MutasiBank extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'mutasi_bank';

    protected $fillable = [
        'rekening_bank_id', 'mutasi_import_id', 'tanggal', 'keterangan', 'referensi',
        'debit', 'kredit', 'saldo', 'kunci', 'status',
        'cocok_type', 'cocok_id', 'cara_cocok', 'dicocokkan_oleh', 'dicocokkan_pada', 'alasan_abaikan',
    ];

    public const BELUM_COCOK = 'belum_cocok';
    public const COCOK = 'cocok';
    public const DIABAIKAN = 'diabaikan';

    public const STATUS = [
        self::BELUM_COCOK => 'Belum Cocok',
        self::COCOK => 'Cocok',
        self::DIABAIKAN => 'Diabaikan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'debit' => 'decimal:2',
            'kredit' => 'decimal:2',
            'saldo' => 'decimal:2',
            'dicocokkan_pada' => 'datetime',
        ];
    }

    public function rekening()
    {
        return $this->belongsTo(RekeningBank::class, 'rekening_bank_id');
    }

    public function impor()
    {
        return $this->belongsTo(MutasiImport::class, 'mutasi_import_id');
    }

    public function pencocok()
    {
        return $this->belongsTo(User::class, 'dicocokkan_oleh');
    }

    /** Transaksi sistem yang dicocokkan (pembayaran, penerimaan dana, atau pengeluaran). Null jika sudah dihapus. */
    public function cocok()
    {
        return $this->morphTo('cocok');
    }

    public function isMasuk(): bool
    {
        return BigDecimal::of((string) $this->kredit)->isGreaterThan(0);
    }

    public function getNominalAttribute(): string
    {
        return $this->isMasuk() ? (string) $this->kredit : (string) $this->debit;
    }

    /** Cocok, tetapi transaksinya kemudian dibatalkan atau dihapus. Perlu dilepas dan dicocokkan ulang. */
    public function cocokBermasalah(): bool
    {
        if ($this->status !== self::COCOK) {
            return false;
        }

        $t = $this->cocok;

        return match (true) {
            $t === null => true,
            $t instanceof Pembayaran => $t->status !== Pembayaran::TERVERIFIKASI,
            $t instanceof Pengeluaran => $t->status !== Pengeluaran::DISETUJUI,
            default => false,
        };
    }

    public function scopeBermasalah(Builder $q): Builder
    {
        return $q->where('status', self::COCOK)->where(function ($w) {
            $w->where(fn ($x) => $x->where('cocok_type', Pembayaran::class)
                    ->whereNotIn('cocok_id', Pembayaran::select('id')->where('status', Pembayaran::TERVERIFIKASI)))
              ->orWhere(fn ($x) => $x->where('cocok_type', PenerimaanDana::class)
                    ->whereNotIn('cocok_id', PenerimaanDana::select('id')))
              ->orWhere(fn ($x) => $x->where('cocok_type', Pengeluaran::class)
                    ->whereNotIn('cocok_id', Pengeluaran::select('id')->where('status', Pengeluaran::DISETUJUI)));
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'cocok_type', 'cocok_id', 'cara_cocok', 'alasan_abaikan'])
            ->logOnlyDirty()
            ->useLogName('mutasi_bank');
    }
}
