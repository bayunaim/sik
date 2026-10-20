<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('komponen_biaya', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();
            $table->string('nama');
            $table->string('jenis', 20); // pendaftaran, spp_ukt, semester, praktikum, ujian, wisuda, lainnya
            $table->foreignId('akun_pendapatan_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->unsignedBigInteger('anggaran_id')->nullable(); // FK ditambah di Fase 2 setelah tabel anggaran ada
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('komponen_biaya');
    }
};
