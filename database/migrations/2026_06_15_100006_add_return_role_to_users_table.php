<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * İade modülü görev ayrılığı:
     *   none    — iade yetkisi yok
     *   staff   — iade personeli (1. onay / işleme)
     *   manager — yönetici (2. onay → para iadesini tetikler)
     *
     * Sayfa görünürlüğü 'returns' allowed_pages ile, para onay seviyesi bu alan ile yönetilir.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('return_role')->default('none')->after('can_use_ai')
                ->comment('none | staff | manager — iade onay seviyesi');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('return_role');
        });
    }
};
