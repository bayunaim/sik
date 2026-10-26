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
        Schema::create('penerimaan_dana', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pembayaran_id')->nullable()->unique()->constrained('pembayaran');
            $table->string('sumber', 20)->default('mahasiswa'); // mahasiswa | hibah | donasi | lainnya
            $table->foreignId('anggaran_id')->constrained('anggaran');
            $table->foreignId('tahun_anggaran_id')->constrained('tahun_anggaran');
            $table->date('tanggal');
            $table->decimal('nominal', 15, 2);
            $table->string('keterangan')->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('anggaran_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penerimaan_dana');
    }
};
