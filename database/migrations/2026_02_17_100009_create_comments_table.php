<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('post_title');
            $table->string('post_url')->nullable();
            $table->string('platform_comment_id')->nullable();
            $table->string('platform_post_id')->nullable();
            $table->text('content');
            $table->enum('status', ['pending', 'replied', 'hidden'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
