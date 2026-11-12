<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // FR-102: grafik tren dan laporan mengelompokkan penerimaan menurut tanggal
    public function up(): void
    {
        Schema::table('penerimaan_dana', function (Blueprint $table) {
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::table('penerimaan_dana', function (Blueprint $table) {
            $table->dropIndex(['tanggal']);
        });
    }
};
