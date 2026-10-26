<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class JurnalDetail extends Model
{
    protected $table = 'jurnal_detail';

    protected $fillable = ['jurnal_id', 'chart_of_account_id', 'debit', 'kredit', 'keterangan'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Detail jurnal tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Detail jurnal tidak dapat dihapus.'));
    }

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'kredit' => 'decimal:2'];
    }

    public function jurnal()
    {
        return $this->belongsTo(Jurnal::class);
    }

    public function akun()
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id')->withTrashed();
    }
}
