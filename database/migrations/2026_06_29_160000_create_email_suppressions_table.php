<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_suppressions — pazarlama e-postası gönderilMEYECEK adresler.
 * Abonelikten çıkan (one-click unsubscribe), sert bounce veya şikayet eden adresler.
 * UnifiedSender her e-posta gönderiminden önce bu listeyi kontrol eder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('reason', 30)->default('unsubscribe'); // unsubscribe|bounce|complaint|manual
            $table->string('source')->nullable();                 // hangi otomasyon/kampanya
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
