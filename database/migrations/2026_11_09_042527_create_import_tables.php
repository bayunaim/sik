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
        Schema::create('import_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nama_file');
            $table->string('file_path')->nullable();
            $table->json('mapping')->nullable();
            $table->string('status', 12)->default('pratinjau'); // pratinjau | selesai | gagal
            $table->unsignedInteger('jumlah_baru')->default(0);
            $table->unsignedInteger('jumlah_berubah')->default(0);
            $table->unsignedInteger('jumlah_sama')->default(0);
            $table->unsignedInteger('jumlah_gagal')->default(0);
            $table->unsignedInteger('jumlah_prodi_baru')->default(0);
            $table->unsignedInteger('jumlah_hilang')->default(0);
            $table->string('error_path')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        Schema::create('import_template', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 50)->unique();
            $table->json('mapping');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_template');
        Schema::dropIfExists('import_log');
    }
};
