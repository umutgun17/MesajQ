<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Panelden "Test Et" ile yapılan çevirmeler gerçek kupon/kayıt üretir ama is_test
        // işaretlenir → raporlara/istatistiğe karılmaz, toplu temizlenebilir.
        Schema::table('wheel_leads', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->index()->after('wheel_campaign_id');
        });
        Schema::table('wheel_spins', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->index()->after('wheel_slice_id');
        });
    }

    public function down(): void
    {
        Schema::table('wheel_leads', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
        Schema::table('wheel_spins', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
