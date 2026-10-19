<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Müşteri listeleri (toplu kampanya hedef kitleleri)
        Schema::create('marketing_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('contacts_count')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('marketing_list_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('list_id');
            $table->string('phone', 20); // normalize: 905XXXXXXXXX
            $table->string('name', 190)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->unique(['list_id', 'phone']);
            $table->index(['tenant_id', 'phone']);
            $table->foreign('list_id')->references('id')->on('marketing_lists')->cascadeOnDelete();
        });

        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('list_id')->nullable();
            $table->string('name', 150);
            $table->string('channel', 20)->default('whatsapp');
            // draft | sending | paused | completed | cancelled
            $table->string('status', 20)->default('draft');
            $table->string('template_name', 190)->nullable();
            $table->string('template_lang', 10)->default('tr');
            // [{source: 'name'|'coupon'|'static', value?: string}, ...] — {{1}}..{{n}} sırasıyla
            $table->jsonb('template_params')->nullable();
            // {enabled: bool, target_url: string} — dinamik URL butonu = tık takibi
            $table->jsonb('url_button')->nullable();
            $table->string('coupon_code', 64)->nullable();
            $table->unsignedSmallInteger('send_window_hours')->default(3);
            $table->unsignedSmallInteger('cooldown_days')->default(2);
            $table->unsignedSmallInteger('attribution_days')->default(7);
            $table->unsignedInteger('recipients_total')->default(0);
            $table->string('paused_reason', 190)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('marketing_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('campaign_id');
            $table->string('phone', 20);
            $table->string('name', 190)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            // pending | sent | skipped | failed
            $table->string('status', 20)->default('pending');
            $table->string('skip_reason', 60)->nullable();
            $table->unsignedBigInteger('message_id')->nullable(); // marketing_messages.id
            $table->string('tracking_token', 64)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->decimal('revenue', 12, 2)->default(0);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->boolean('coupon_used')->default(false);
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'phone']);
            $table->index(['campaign_id', 'status']);
            $table->index(['tenant_id', 'phone']);
            $table->foreign('campaign_id')->references('id')->on('marketing_campaigns')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_recipients');
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('marketing_list_contacts');
        Schema::dropIfExists('marketing_lists');
    }
};
