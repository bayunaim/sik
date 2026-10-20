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
        Schema::create('tarif_biaya', function (Blueprint $table) {
            $table->id();
            $table->foreignId('komponen_biaya_id')->constrained('komponen_biaya');
            $table->foreignId('program_studi_id')->nullable()->constrained('program_studi'); // null = semua prodi
            $table->unsignedSmallInteger('angkatan')->nullable();                            // null = semua angkatan
            $table->decimal('nominal', 15, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['komponen_biaya_id', 'program_studi_id', 'angkatan']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tarif_biaya');
    }
};
