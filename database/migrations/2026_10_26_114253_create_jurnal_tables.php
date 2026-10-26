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
        Schema::create('jurnal', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->nullable()->unique();
            $table->date('tanggal');
            $table->foreignId('tahun_anggaran_id')->constrained('tahun_anggaran');
            $table->string('jenis', 12)->default('otomatis'); // otomatis | penyesuaian | pembalik
            $table->string('keterangan');
            $table->nullableMorphs('sumber');
            $table->string('kunci_sumber', 80)->nullable()->unique(); // contoh: pembayaran:12 (cegah jurnal ganda)
            $table->foreignId('jurnal_asal_id')->nullable()->unique()->constrained('jurnal'); // hanya untuk pembalik
            $table->decimal('total_debit', 15, 2);
            $table->decimal('total_kredit', 15, 2);
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('tanggal');
        });

        Schema::create('jurnal_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jurnal_id')->constrained('jurnal');
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('kredit', 15, 2)->default(0);
            $table->string('keterangan')->nullable();
            $table->timestamps();

            $table->index('chart_of_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurnal_detail');
        Schema::dropIfExists('jurnal');
    }
};
