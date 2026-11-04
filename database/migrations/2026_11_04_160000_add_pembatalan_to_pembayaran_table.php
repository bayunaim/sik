<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->text('alasan_batal')->nullable()->after('alasan_tolak');
            $table->foreignId('dibatalkan_oleh')->nullable()->after('diverifikasi_pada')->constrained('users')->nullOnDelete();
            $table->timestamp('dibatalkan_pada')->nullable()->after('dibatalkan_oleh');
        });
    }

    public function down(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dibatalkan_oleh');
            $table->dropColumn(['alasan_batal', 'dibatalkan_pada']);
        });
    }
};
