<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akıllı Arama — aranan her sorgunun analitik + anlama kaydı.
 * Append-only (tek satır = tek arama olayı). Ticimax API bu veriyi VERMEZ;
 * storefront widget'ından toplanır. Asıl farklılaşma yakıtı burası.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('channel_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('session_id', 64)->nullable()->index();

            $table->string('raw_query', 160);                       // kullanıcının yazdığı ham metin
            $table->string('normalized_query', 160)->nullable();    // deascii + tr-lower
            $table->string('corrected_query', 160)->nullable();     // typo düzeltildiyse
            $table->jsonb('parsed_facets')->nullable();             // {renk:.., beden:.., kategori:..}
            $table->string('intent', 40)->nullable();               // product|category|brand|...

            $table->unsignedInteger('results_count')->default(0);
            $table->boolean('has_results')->default(true);
            $table->string('source', 20)->default('search');        // search|autocomplete
            $table->string('device', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('is_test')->default(false);             // test trafiği ayrımı (çark deseni)

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'has_results']);
            $table->index(['tenant_id', 'normalized_query']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
