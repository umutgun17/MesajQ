<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->string('direction', 10)->default('incoming')->after('ai_category');
        });

        // Add CHECK constraint for direction
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_direction_check CHECK (direction::text = ANY (ARRAY['incoming', 'outgoing']::text[]))");

        // Update ai_category CHECK to include 'outgoing_reply'
        DB::statement("ALTER TABLE email_messages DROP CONSTRAINT IF EXISTS email_messages_ai_category_check");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_ai_category_check CHECK (ai_category::text = ANY (ARRAY['customer_request', 'spam', 'notification', 'auto_reply', 'other', 'outgoing_reply']::text[]))");
    }

    public function down(): void
    {
        // Restore original ai_category CHECK
        DB::statement("ALTER TABLE email_messages DROP CONSTRAINT IF EXISTS email_messages_ai_category_check");
        DB::statement("ALTER TABLE email_messages ADD CONSTRAINT email_messages_ai_category_check CHECK (ai_category::text = ANY (ARRAY['customer_request', 'spam', 'notification', 'auto_reply', 'other']::text[]))");

        DB::statement("ALTER TABLE email_messages DROP CONSTRAINT IF EXISTS email_messages_direction_check");

        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
