<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('message_id')->unique(); // Message-ID header
            $table->string('in_reply_to')->nullable(); // In-Reply-To header
            $table->text('references')->nullable(); // References header
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('to_email');
            $table->string('subject')->nullable();
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();
            $table->string('ai_category', 30)->default('other'); // customer_request, spam, notification, auto_reply, other
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'ai_category']);
            $table->index('conversation_id');
            $table->index(['from_email', 'created_at']);
        });

        // Add CHECK constraint for ai_category
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_ai_category_check CHECK (ai_category::text = ANY (ARRAY['customer_request', 'spam', 'notification', 'auto_reply', 'other']::text[]))");

        // Add 'email' to ai_training_data source_type CHECK constraint
        DB::statement("ALTER TABLE ai_training_data DROP CONSTRAINT IF EXISTS ai_training_data_source_type_check");
        DB::statement("ALTER TABLE ai_training_data ADD CONSTRAINT ai_training_data_source_type_check CHECK (source_type::text = ANY (ARRAY['conversation', 'manual', 'trendyol', 'hepsiburada', 'ticimax', 'email']::text[]))");
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');

        // Restore previous source_type CHECK constraint
        DB::statement("ALTER TABLE ai_training_data DROP CONSTRAINT IF EXISTS ai_training_data_source_type_check");
        DB::statement("ALTER TABLE ai_training_data ADD CONSTRAINT ai_training_data_source_type_check CHECK (source_type::text = ANY (ARRAY['conversation', 'manual', 'trendyol', 'hepsiburada', 'ticimax']::text[]))");
    }
};
