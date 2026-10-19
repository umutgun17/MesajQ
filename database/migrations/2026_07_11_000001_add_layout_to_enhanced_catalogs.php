<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enhanced_catalogs', function (Blueprint $table) {
            // Serbest tasarım editörünün eleman düzeni ({canvas, elements[]}).
            // Doluysa render preset şablon yerine bu düzeni kullanır.
            $table->jsonb('layout')->nullable();
            // layout kullanıldığında preset şablon zorunlu olmasın
            $table->unsignedBigInteger('template_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('enhanced_catalogs', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
