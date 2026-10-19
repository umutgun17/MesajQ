<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Temsilci (agent) kullanıcılar için ürün→sayfa yetki matrisi.
     * null = eski davranış (yalnız Mesaj ürününün operasyonel sayfaları).
     * Örnek: {"inbox": ["messages", "comments"], "ads": ["ads"]}
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('page_permissions')->nullable()->after('return_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('page_permissions');
        });
    }
};
