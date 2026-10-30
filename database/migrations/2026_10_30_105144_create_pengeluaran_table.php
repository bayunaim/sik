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
        Schema::create('pengeluaran', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->nullable()->unique();
            $table->date('tanggal');
            $table->foreignId('anggaran_id')->constrained('anggaran');
            $table->foreignId('tahun_anggaran_id')->constrained('tahun_anggaran');
            $table->foreignId('akun_beban_id')->constrained('chart_of_accounts');
            $table->string('keperluan');
            $table->decimal('nominal', 15, 2);
            $table->string('metode', 30);                      // Transfer Bank | Tunai (sumber kas/bank)
            $table->string('lampiran_path')->nullable();
            $table->string('status', 12)->default('pending');  // pending | disetujui | ditolak
            $table->text('alasan_tolak')->nullable();
            $table->foreignId('diajukan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('diproses_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diproses_pada')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'tanggal']);
            $table->index(['anggaran_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengeluaran');
    }
};
