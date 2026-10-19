<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Chatbot (site içi sohbet) — bkz docs/ai-chatbot.md.
 * Bot fazı bu tablolarda yaşar; handoff'ta konuşma conversations'a köprülenir
 * (chat_sessions.conversation_id) ve personel mevcut Mesajlar panelinden cevaplar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('session_key', 64)->unique();
            $table->string('status', 16)->default('bot'); // bot | handoff | closed
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('contact')->nullable();   // {name, email, phone} — handoff için toplanır
            $table->jsonb('context')->nullable();   // bot durum makinesi (awaiting_contact, low_conf_streak, order_attempts...)
            $table->string('page_url', 500)->nullable();
            $table->unsignedInteger('user_message_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'last_activity_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_session_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16); // user | assistant | agent | system
            $table->text('content');
            // payload: products[], quick_replies[], action, confidence, provider, search_id...
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->index(['chat_session_id', 'id']); // polling: after_id sorgusu
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_sessions');
    }
};
