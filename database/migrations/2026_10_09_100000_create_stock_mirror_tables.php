<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM › Stok Aynalama: Nebim'in beslemediği kopya ürün kartlarının (örn. 1 Alana 1 Bedava
 * TBA9090) stoklarını kaynak karttan (TBA9060) barkod/renk+beden eşleşmesiyle Ticimax'a yazar.
 * stock_mirror_rules = kaynak→hedef eşleme (stok kodu bazlı, tüm renk kartları kapsanır)
 * stock_mirror_runs  = her çalıştırma özeti (cron/elle, eşleşen/değişen/hata/eşleşmeyen)
 * stock_mirror_logs  = varyasyon bazında yazılan her değişiklik (eski → yeni stok)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_mirror_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('source_code', 64);
            $table->string('target_code', 64);
            $table->string('match_mode', 24)->default('barcode_then_attrs'); // barcode | barcode_then_attrs | attrs
            $table->boolean('is_active')->default(true);
            $table->boolean('zero_unmatched')->default(false);
            $table->text('note')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable(); // ok | error | partial
            $table->json('last_summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'target_code']);
        });

        Schema::create('stock_mirror_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('rule_id')->index();
            $table->string('trigger', 16)->default('cron'); // cron | manual | preview
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->string('status', 16)->default('ok'); // ok | error | partial
            $table->unsignedInteger('source_count')->default(0);
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('changed')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->json('unmatched')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('stock_mirror_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('rule_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('trigger', 16)->default('cron');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source_code', 64);
            $table->string('target_code', 64);
            $table->unsignedBigInteger('source_variant_id')->nullable();
            $table->unsignedBigInteger('target_variant_id');
            $table->unsignedBigInteger('target_card_id')->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('color', 64)->nullable();
            $table->string('size', 32)->nullable();
            $table->string('match_by', 16)->nullable(); // barcode | attrs | none
            $table->decimal('old_stock', 12, 2)->nullable();
            $table->decimal('new_stock', 12, 2)->nullable();
            $table->string('status', 16)->default('ok'); // ok | error | dry
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_mirror_logs');
        Schema::dropIfExists('stock_mirror_runs');
        Schema::dropIfExists('stock_mirror_rules');
    }
};
