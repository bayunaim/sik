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
        Schema::create('anggaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_anggaran_id')->constrained('tahun_anggaran');
            $table->string('kode', 20);
            $table->char('tipe', 1);                       // D = penerimaan, K = pengeluaran
            $table->string('nama');
            $table->string('sub_anggaran')->nullable();
            $table->decimal('jumlah', 15, 2)->default(0);
            $table->string('status', 10)->default('draft'); // draft | approved
            $table->text('catatan')->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disetujui_pada')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tahun_anggaran_id', 'kode']);
            $table->index(['tahun_anggaran_id', 'tipe', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anggaran');
    }
};
