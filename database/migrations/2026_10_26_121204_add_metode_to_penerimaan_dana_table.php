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
        Schema::table('penerimaan_dana', function (Blueprint $table) {
            $table->string('metode', 30)->nullable()->after('nominal'); // Transfer Bank | Tunai (penerimaan manual)
        });
    }

    public function down(): void
    {
        Schema::table('penerimaan_dana', function (Blueprint $table) {
            $table->dropColumn('metode');
        });
    }
};
