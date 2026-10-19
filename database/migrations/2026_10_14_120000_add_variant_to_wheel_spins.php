<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A/B: aynı kampanyada ziyaretçiye hangi oyun gösterildi? wheel (çark) | scratch (kazı kazan).
        // Widget ziyaretçi bazında kalıcı atar; istatistik/ciro bu kolona göre kırılır.
        Schema::table('wheel_spins', function (Blueprint $table) {
            $table->string('variant', 16)->default('wheel')->index()->after('is_test');
        });
    }

    public function down(): void
    {
        Schema::table('wheel_spins', function (Blueprint $table) {
            $table->dropColumn('variant');
        });
    }
};
