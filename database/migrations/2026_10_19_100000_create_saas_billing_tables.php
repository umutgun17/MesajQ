<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS çekirdeği: planlar, abonelikler, faturalar, fatura profili, kullanım sayaçları.
 * Tenant ürün kapısı (tenants.settings.products) plan uygulanınca yazılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('family', 40)->index();          // mesajq | eticaretq
            $table->jsonb('products')->default('[]');        // Tenant::PRODUCTS anahtarları
            $table->jsonb('limits')->default('{}');          // {"ai_messages":2500,...}
            $table->jsonb('features')->default('[]');        // vitrin maddeleri
            $table->decimal('price_monthly', 12, 2)->default(0);
            $table->decimal('price_yearly', 12, 2)->nullable(); // null → aylık×12×(1-indirim)
            $table->string('currency', 3)->default('TRY');
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_trial_default')->default(false);
            $table->boolean('highlight')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('status', 20)->index();           // trialing|active|past_due|canceled|expired
            $table->string('billing_cycle', 10)->default('monthly'); // monthly|yearly
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable()->index();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('gateway', 20)->nullable();        // iyzico|manual
            $table->string('gateway_customer_ref')->nullable(); // iyzico cardUserKey
            $table->text('gateway_card_ref')->nullable();     // iyzico cardToken (şifreli)
            $table->jsonb('card_meta')->nullable();           // {last4, association, family, bank}
            $table->decimal('price', 12, 2)->default(0);      // kilitli dönem fiyatı (KDV hariç)
            $table->string('currency', 3)->default('TRY');
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('last_payment_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type', 12)->default('company');   // company|individual
            $table->string('legal_name')->nullable();
            $table->string('tax_office', 120)->nullable();
            $table->string('tax_number', 20)->nullable();     // VKN / TCKN
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('district', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('country', 2)->default('TR');
            $table->string('postal_code', 12)->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30)->unique();
            $table->string('status', 12)->index();            // open|paid|failed|void
            $table->string('description')->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_rate', 5, 2)->default(20);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('currency', 3)->default('TRY');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('gateway', 20)->nullable();
            $table->string('gateway_payment_id')->nullable();
            $table->string('gateway_token')->nullable()->index(); // iyzico checkout token
            $table->string('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->jsonb('billing_snapshot')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 40);
            $table->string('period', 7);                      // YYYY-MM
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'metric', 'period']);
        });

        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40)->index();
            $table->jsonb('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('billing_profiles');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
