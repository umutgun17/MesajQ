<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Services\EmbeddingService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AiTrainingData extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'ai_training_data';

    protected $fillable = [
        'tenant_id',
        'source_type',
        'source_id',
        'channel_type',
        'question',
        'answer',
        'context',
        'quality_score',
        'is_active',
        'used_count',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'quality_score' => 'decimal:2',
            'is_active' => 'boolean',
            'used_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $model) {
            // Generate embedding when question or answer changes, or is newly created
            if (! $model->wasRecentlyCreated && ! $model->wasChanged(['question', 'answer'])) {
                return;
            }

            if (empty($model->question)) {
                return;
            }

            $embeddingService = EmbeddingService::forTenant($model->tenant_id);
            if (! $embeddingService->isAvailable()) {
                return;
            }

            $embedding = $embeddingService->embed($model->buildEmbeddingText());
            if ($embedding) {
                DB::table('ai_training_data')
                    ->where('id', $model->id)
                    ->update(['embedding' => json_encode($embedding)]);
            }
        });
    }

    public function buildEmbeddingText(): string
    {
        $question = trim($this->question ?? '');
        $answer = trim($this->answer ?? '');

        if ($answer !== '') {
            return "{$question} → {$answer}";
        }

        return $question;
    }
}
