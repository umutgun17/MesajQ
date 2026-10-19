<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rec_events.surface — öneri yüzeyi ayrımı:
 *   atc      = Sepete Ekleme Önerisi (ürün sepete eklendiği an)
 *   checkout = Ödeme Adımı Önerisi (checkout'a giderken, sepetin tamamına göre)
 * Analitik ve atıf yüzey bazında raporlanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_events', function (Blueprint $table) {
            $table->string('surface', 16)->default('atc')->index();
        });
    }

    public function down(): void
    {
        Schema::table('rec_events', function (Blueprint $table) {
            $table->dropColumn('surface');
        });
    }
};
