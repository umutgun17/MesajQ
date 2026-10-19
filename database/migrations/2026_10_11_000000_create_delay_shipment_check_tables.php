<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM › Gecikmeler › Kargo kontrolü.
 * cargo_shipments       = Sürat carilerinden (normal + kapıda ödeme) çekilen gönderilerin yerel kopyası
 *                         (GonderilenKargoDetayi). Sipariş no'suz/yanlış cariden çıkışı yakalamak için tüm liste tutulur.
 * order_shipment_checks = geciken siparişin Nebim + Sürat kontrol sonucu (sipariş başına tek satır).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargo_shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('provider', 16)->default('surat');
            $table->string('account_key', 16);          // normal | cod
            $table->string('account_code', 32);
            $table->string('tracking_no', 40);
            $table->string('web_order_code', 64)->nullable();
            $table->string('web_order_norm', 64)->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_norm')->nullable();
            $table->text('address')->nullable();
            $table->string('city', 64)->nullable();
            $table->string('district', 64)->nullable();
            $table->string('payment_type', 16)->nullable();
            $table->string('status', 64)->nullable();
            $table->string('delivery_status', 64)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->timestamp('shipped_at')->nullable();   // OlusturulmaTarihi (TR yerel)
            $table->jsonb('raw')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'account_code', 'tracking_no']);
            $table->index(['tenant_id', 'web_order_norm']);
            $table->index(['tenant_id', 'shipped_at']);
        });

        Schema::create('order_shipment_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('verdict', 24);                 // shipped | wrong_account | possible_manual | invoiced | not_shipped | error
            $table->string('nebim_status', 16)->nullable(); // found | not_found | error
            $table->string('nebim_order_number', 40)->nullable();
            $table->string('nebim_invoice_number', 40)->nullable();
            $table->timestamp('nebim_checked_at')->nullable();
            $table->jsonb('findings')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipment_checks');
        Schema::dropIfExists('cargo_shipments');
    }
};
