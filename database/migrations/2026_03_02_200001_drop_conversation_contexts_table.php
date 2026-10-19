<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('conversation_contexts');
    }

    public function down(): void
    {
        // Table was orphaned — no rollback needed
    }
};
