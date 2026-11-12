<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FR-120: tagihan belum lunas yang dibawa ke tahun anggaran berikutnya saat Tutup Tahun.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            $table->boolean('carry_over')->default(false)->after('status');
            $table->foreignId('tahun_asal_id')->nullable()->after('carry_over')
                ->constrained('tahun_anggaran')->nullOnDelete();
            $table->timestamp('carry_over_pada')->nullable()->after('tahun_asal_id');
        });
    }

    public function down(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tahun_asal_id');
            $table->dropColumn(['carry_over', 'carry_over_pada']);
        });
    }
};
