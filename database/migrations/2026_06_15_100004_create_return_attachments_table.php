<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('return_request_events')->nullOnDelete();

            $table->string('kind')->default('reject_evidence')->comment('reject_evidence | customer_upload | inspection_photo');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('return_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_attachments');
    }
};
