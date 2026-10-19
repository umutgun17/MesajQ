<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('source', 20)->default('netgsm')->after('channel_id');
            $table->text('vapi_transcript')->nullable()->after('ai_metadata');
            $table->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn(['source', 'vapi_transcript']);
        });
    }
};
