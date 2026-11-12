<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rekening bank kampus (boleh lebih dari satu). Tidak dihapus, hanya dinonaktifkan.
        Schema::create('rekening_bank', function (Blueprint $table) {
            $table->id();
            $table->string('nama_bank', 50);
            $table->string('nomor', 40);
            $table->string('atas_nama', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['nama_bank', 'nomor']);
        });

        // Riwayat import mutasi (FR-080), satu baris per file.
        Schema::create('mutasi_import', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rekening_bank_id')->constrained('rekening_bank');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nama_file');
            $table->string('file_path')->nullable();
            $table->json('mapping')->nullable();
            $table->string('status', 12)->default('pratinjau'); // pratinjau | selesai | gagal | dibatalkan
            $table->unsignedInteger('jumlah_baru')->default(0);
            $table->unsignedInteger('jumlah_duplikat')->default(0);
            $table->unsignedInteger('jumlah_gagal')->default(0);
            $table->date('tanggal_awal')->nullable();
            $table->date('tanggal_akhir')->nullable();
            $table->string('error_path')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        Schema::create('mutasi_bank', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rekening_bank_id')->constrained('rekening_bank');
            $table->foreignId('mutasi_import_id')->nullable()->constrained('mutasi_import');
            $table->date('tanggal');
            $table->string('keterangan', 500);
            $table->string('referensi', 100)->nullable();
            $table->decimal('debit', 15, 2)->default(0);   // uang keluar
            $table->decimal('kredit', 15, 2)->default(0);  // uang masuk
            $table->decimal('saldo', 15, 2)->nullable();
            $table->char('kunci', 40);                      // sidik jari baris, mencegah mutasi dobel saat file tumpang tindih
            $table->string('status', 12)->default('belum_cocok'); // belum_cocok | cocok | diabaikan
            $table->string('cocok_type', 60)->nullable();
            $table->unsignedBigInteger('cocok_id')->nullable();
            $table->string('cara_cocok', 10)->nullable();   // otomatis | manual
            $table->foreignId('dicocokkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dicocokkan_pada')->nullable();
            $table->string('alasan_abaikan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['rekening_bank_id', 'kunci']);
            $table->unique(['cocok_type', 'cocok_id']);     // satu transaksi hanya bisa dicocokkan ke satu mutasi
            $table->index(['rekening_bank_id', 'status', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mutasi_bank');
        Schema::dropIfExists('mutasi_import');
        Schema::dropIfExists('rekening_bank');
    }
};
