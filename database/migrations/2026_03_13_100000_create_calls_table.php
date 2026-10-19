<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            // NetGSM CDR fields
            $table->string('common_id')->comment('NetGSM commonID - groups ring attempts');
            $table->string('caller')->comment('Arayan numara');
            $table->string('called')->comment('Aranan numara / dahili');
            $table->string('extension')->nullable()->comment('Dahili numarası (101-110)');
            $table->string('direction')->default('inbound')->comment('inbound / outbound');
            $table->string('status')->default('answered')->comment('answered / missed / busy / no_answer');

            $table->timestamp('started_at')->comment('Çağrı başlangıç zamanı');
            $table->integer('duration')->default(0)->comment('Konuşma süresi (saniye)');
            $table->integer('ring_duration')->default(0)->comment('Çalma süresi (saniye)');

            // Recording
            $table->string('recording_url')->nullable()->comment('NetGSM ses kaydı URL');
            $table->string('recording_path')->nullable()->comment('Sunucuya indirilen ses dosyası');

            // Transcription & AI Analysis
            $table->text('transcription')->nullable()->comment('Whisper speech-to-text');
            $table->text('ai_summary')->nullable()->comment('Claude AI özet');
            $table->string('ai_sentiment')->nullable()->comment('positive / neutral / negative');
            $table->string('ai_category')->nullable()->comment('AI tarafından belirlenen kategori');
            $table->json('ai_metadata')->nullable()->comment('Ek AI analiz verileri');
            $table->timestamp('transcribed_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->unique(['tenant_id', 'common_id']);
            $table->index(['tenant_id', 'started_at']);
            $table->index(['tenant_id', 'extension']);
            $table->index(['tenant_id', 'status']);
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
