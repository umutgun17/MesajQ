<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personel kaynaklı talepler (eksik/hatalı ürün iadesi — ReturnType::Shortage): talebi
 * panelden açan kullanıcı. Müşteri kaynaklı taleplerde NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('rejected_at')
                ->constrained('users')->nullOnDelete()
                ->comment('Talebi panelden açan personel (eksik/hatalı ürün iadesi)');
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
