<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aynı e-posta farklı firmalarda ayrı personel hesabı olabilsin diye
     * users.email global unique'i tenant kapsamına çekildi. Süper adminler
     * (tenant_id NULL) kendi aralarında hâlâ benzersiz — Postgres'te NULL'lar
     * composite unique'te çakışmadığından kısmi index ile sağlanır.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->unique(['tenant_id', 'email']);
        });

        DB::statement('CREATE UNIQUE INDEX users_email_superadmin_unique ON users (email) WHERE tenant_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_superadmin_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->unique(['email']);
        });
    }
};
