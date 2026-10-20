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
        Schema::create('mahasiswa', function (Blueprint $table) {
            $table->id();
            $table->string('nim', 30)->unique();
            $table->string('nama');
            $table->foreignId('program_studi_id')->constrained('program_studi');
            $table->unsignedSmallInteger('angkatan');
            $table->string('status_akademik', 30)->default('Aktif'); // Aktif, Cuti, Lulus, Nonaktif, Tidak Ada di Import
            $table->string('semester_aktif', 10)->nullable();
            $table->string('sia_id', 50)->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['program_studi_id', 'angkatan']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mahasiswa');
    }
};
