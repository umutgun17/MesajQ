<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // sms | email | whatsapp | push
            $table->string('channel', 20);
            // telefon / e-posta / push endpoint kimliği
            $table->string('recipient');

            // Kaynak: otomasyon anahtarı / kampanya / 'test' / transactional
            $table->string('source')->nullable();
            $table->string('source_type', 30)->default('manual'); // automation|campaign|transactional|test|manual
            $table->string('template_id')->nullable();

            $table->string('subject')->nullable();
            $table->text('body')->nullable();

            // queued|sent|delivered|opened|clicked|converted|failed|skipped
            $table->string('status', 20)->default('queued');
            // skipped ise: consent|iys|quiet_hours|frequency_cap|channel_disabled|channel_unsupported
            $table->string('skip_reason', 40)->nullable();

            $table->string('provider', 30)->nullable();        // netgsm | meta | exim ...
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();

            $table->decimal('cost', 10, 4)->nullable();
            $table->decimal('revenue', 12, 2)->default(0);      // attribution (dönüşüm)

            $table->jsonb('meta')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'channel', 'status']);
            $table->index(['tenant_id', 'source']);
            $table->index(['tenant_id', 'recipient']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_messages');
    }
};
