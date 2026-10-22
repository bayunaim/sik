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
        Schema::create('tagihan', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->nullable()->unique();
            $table->foreignId('mahasiswa_id')->constrained('mahasiswa');
            $table->foreignId('komponen_biaya_id')->constrained('komponen_biaya');
            $table->foreignId('anggaran_id')->constrained('anggaran');
            $table->foreignId('tahun_anggaran_id')->constrained('tahun_anggaran');
            $table->string('semester', 20);            // Ganjil | Genap | Pendek
            $table->string('tahun_ajaran', 9);         // 2025/2026
            $table->decimal('nominal', 15, 2);
            $table->decimal('terbayar', 15, 2)->default(0);
            $table->date('tanggal_tagihan');
            $table->date('jatuh_tempo');
            $table->string('metode_bayar', 30)->nullable();
            $table->string('keterangan')->nullable();
            $table->string('status', 12)->default('belum_bayar'); // belum_bayar | sebagian | pending | lunas
            $table->string('kunci_unik', 150)->nullable()->unique(); // NULL saat dibatalkan
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->text('alasan_batal')->nullable();
            $table->timestamp('dibatalkan_pada')->nullable();
            $table->foreignId('dibatalkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'jatuh_tempo']);
            $table->index(['tahun_ajaran', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tagihan');
    }
};
