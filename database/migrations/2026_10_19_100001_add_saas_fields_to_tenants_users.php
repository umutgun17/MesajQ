<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('signup_source', 40)->nullable();      // self|admin|import
            $table->timestamp('onboarded_at')->nullable();
            $table->string('ecommerce_platform_hint', 40)->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false);           // tenant sahibi (faturalama yetkisi)
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('signup_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['signup_source', 'onboarded_at', 'ecommerce_platform_hint']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_owner', 'terms_accepted_at', 'signup_ip']));
    }
};
