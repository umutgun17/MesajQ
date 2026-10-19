<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Call extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'customer_id',
        'source',
        'common_id',
        'caller',
        'called',
        'extension',
        'direction',
        'status',
        'started_at',
        'duration',
        'ring_duration',
        'recording_url',
        'recording_path',
        'transcription',
        'ai_summary',
        'ai_sentiment',
        'ai_category',
        'ai_metadata',
        'vapi_transcript',
        'transcribed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'transcribed_at' => 'datetime',
            'ai_metadata' => 'array',
            'duration' => 'integer',
            'ring_duration' => 'integer',
        ];
    }

    // ── Relationships ──

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'extension', 'extension');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Telefon numarasını son 10 haneye normalize et.
     * 05xx... → 5xx..., 905xx... → 5xx...
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return substr($digits, -10);
    }

    // ── Helpers ──

    public function formattedDuration(): string
    {
        $minutes = intdiv($this->duration, 60);
        $seconds = $this->duration % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function isTranscribed(): bool
    {
        return ! is_null($this->transcribed_at);
    }
}
