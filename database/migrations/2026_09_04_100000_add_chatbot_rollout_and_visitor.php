<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Chatbot kademeli açılım (rollout) — bkz docs/ai-chatbot.md §Kademeli açılım.
 *
 * Widget herkese birden açılmaz: panelden verilen kotaya (chat_rollout_limit)
 * kadar FARKLI ziyaretçi botu görür/yazar, kota dolunca yeni ziyaretçilerde
 * widget hiç render edilmez. Ziyaretçi kimliği tarayıcıda üretilir (visitor_id,
 * localStorage) ve slot ilk MESAJDA tahsis edilir — sadece sayfayı açıp yazmayan
 * ziyaretçi kotayı yakmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_rollout_visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'visitor_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->string('visitor_id', 64)->nullable()->after('session_key');
            $table->index(['tenant_id', 'visitor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'visitor_id']);
            $table->dropColumn('visitor_id');
        });
        Schema::dropIfExists('chat_rollout_visitors');
    }
};
