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
        Schema::create('pembayaran', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_kwitansi', 30)->nullable()->unique();
            $table->foreignId('tagihan_id')->constrained('tagihan');
            $table->foreignId('mahasiswa_id')->constrained('mahasiswa');
            $table->date('tanggal_bayar');
            $table->decimal('nominal', 15, 2);
            $table->string('metode', 30);                    // Transfer Bank | Tunai
            $table->string('referensi', 100)->nullable();    // berita transfer / no. referensi
            $table->string('bukti_path')->nullable();
            $table->string('status', 15)->default('pending'); // pending | terverifikasi | ditolak
            $table->text('alasan_tolak')->nullable();
            $table->foreignId('diinput_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('diverifikasi_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diverifikasi_pada')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'tanggal_bayar']);
            $table->index(['tagihan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayaran');
    }
};
