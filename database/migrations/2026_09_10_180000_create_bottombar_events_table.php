<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mobil Alt Bar (CRO) kullanım olayları: view (bar gösterildi) | click (sekme) | sheet_open | sheet_click. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bottombar_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('session_id', 64)->nullable();
            $table->string('type', 16);
            $table->string('item', 32)->nullable();        // home|categories|search|cart|account|favorites|campaign|custom:<i>
            $table->string('page_path', 200)->nullable();  // tıklamanın yapıldığı sayfa
            $table->string('page_type', 16)->nullable();   // home|category|product|cart|account|other
            $table->string('value', 160)->nullable();      // sheet_click: kategori url
            $table->boolean('is_test')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bottombar_events');
    }
};
