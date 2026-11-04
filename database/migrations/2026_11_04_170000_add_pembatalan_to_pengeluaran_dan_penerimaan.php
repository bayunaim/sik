<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengeluaran', function (Blueprint $table) {
            $table->text('alasan_batal')->nullable()->after('alasan_tolak');
            $table->foreignId('dibatalkan_oleh')->nullable()->after('diproses_pada')->constrained('users')->nullOnDelete();
            $table->timestamp('dibatalkan_pada')->nullable()->after('dibatalkan_oleh');
        });

        // penerimaan_dana tidak punya kolom status; waktu pembatalan = deleted_at
        Schema::table('penerimaan_dana', function (Blueprint $table) {
            $table->text('alasan_batal')->nullable()->after('keterangan');
            $table->foreignId('dibatalkan_oleh')->nullable()->after('alasan_batal')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('penerimaan_dana', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dibatalkan_oleh');
            $table->dropColumn('alasan_batal');
        });

        Schema::table('pengeluaran', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dibatalkan_oleh');
            $table->dropColumn(['alasan_batal', 'dibatalkan_pada']);
        });
    }
};
