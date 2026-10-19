<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use App\Services\EmbeddingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'platform_product_id',
        'name',
        'description',
        'sku',
        'barcode',
        'brand',
        'category',
        'category_path',
        'price',
        'discounted_price',
        'cost_price',
        'currency',
        'trendyol_price',
        'trendyol_original_price',
        'trendyol_promotions',
        'trendyol_discount_percent',
        'trendyol_in_stock',
        'trendyol_rating',
        'trendyol_product_url',
        'trendyol_price_synced_at',
        'tax_rate',
        'stock_quantity',
        'variant_stock_labels',
        'performance_label',
        'performance_score',
        'performance_score_breakdown',
        'images',
        'url',
        'is_active',
        'metadata',
        'specifications',
        'google_product_category',
        'platform_created_at',
        'platform_updated_at',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'metadata' => 'array',
            'specifications' => 'array',
            'variant_stock_labels' => 'array',
            'performance_score' => 'decimal:2',
            'performance_score_breakdown' => 'array',
            'is_active' => 'boolean',
            'price' => 'decimal:2',
            'discounted_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'trendyol_price' => 'decimal:2',
            'trendyol_original_price' => 'decimal:2',
            'trendyol_promotions' => 'array',
            'trendyol_discount_percent' => 'decimal:2',
            'trendyol_in_stock' => 'boolean',
            'trendyol_rating' => 'decimal:3',
            'trendyol_price_synced_at' => 'datetime',
            'tax_rate' => 'decimal:2',
            'platform_created_at' => 'datetime',
            'platform_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $embeddingFields = ['name', 'category_path', 'description', 'brand', 'specifications'];

        static::saved(function (self $model) use ($embeddingFields) {
            if (! $model->wasRecentlyCreated && ! $model->wasChanged($embeddingFields)) {
                return;
            }

            if (empty($model->name)) {
                return;
            }

            $embeddingService = EmbeddingService::forTenant($model->tenant_id);
            if (! $embeddingService->isAvailable()) {
                return;
            }

            $embedding = $embeddingService->embed($model->buildEmbeddingText());
            if ($embedding) {
                DB::table('products')
                    ->where('id', $model->id)
                    ->update(['embedding' => json_encode($embedding)]);
            }
        });
    }

    public function buildEmbeddingText(): string
    {
        $parts = [$this->name];

        if ($this->brand) {
            $parts[] = $this->brand;
        }

        if ($this->category_path) {
            $parts[] = $this->category_path;
        }

        if ($this->description) {
            $parts[] = mb_substr(strip_tags($this->description), 0, 500);
        }

        $specs = $this->specifications;
        if (is_array($specs) && ! empty($specs)) {
            $specParts = [];
            foreach ($specs as $key => $value) {
                if (! empty($value) && $value !== 'Evet' && $value !== 'Hayır') {
                    $specParts[] = "{$key}: {$value}";
                }
            }
            if (! empty($specParts)) {
                $parts[] = implode(', ', $specParts);
            }
        }

        // Review summary
        $summary = $this->relationLoaded('reviewSummary') ? $this->reviewSummary : null;
        if ($summary) {
            if ($summary->ai_summary) {
                $parts[] = 'Müşteri Yorumları: ' . mb_substr($summary->ai_summary, 0, 300);
            }
            if ($summary->avg_rate && $summary->rate_count) {
                $parts[] = "Puan: {$summary->avg_rate}/5 ({$summary->rate_count} değerlendirme)";
            }
        }

        // Variant info: sizes and colors (static attributes only — price/stock excluded as they change frequently)
        $variants = $this->relationLoaded('variants') ? $this->variants : null;
        if ($variants && $variants->isNotEmpty()) {
            $activeVariants = $variants->where('is_active', true);

            $sizes = [];
            $colors = [];
            foreach ($activeVariants as $v) {
                $attrs = $v->getAttribute('attributes');
                if (is_array($attrs)) {
                    if (! empty($attrs['Beden'])) {
                        $sizes[$attrs['Beden']] = true;
                    }
                    if (! empty($attrs['Renk'])) {
                        $colors[$attrs['Renk']] = true;
                    }
                }
            }
            if (! empty($sizes)) {
                $parts[] = 'Bedenler: ' . implode(', ', array_keys($sizes));
            }
            if (! empty($colors)) {
                $parts[] = 'Renkler: ' . implode(', ', array_keys($colors));
            }
        }

        return implode(' | ', $parts);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function reviewSummary(): HasOne
    {
        return $this->hasOne(ProductReviewSummary::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function feedOverride(): HasOne
    {
        return $this->hasOne(ProductFeedOverride::class);
    }

    public function feedAiContents(): HasMany
    {
        return $this->hasMany(FeedAiContent::class);
    }

    public function feedAiContent(): HasOne
    {
        return $this->hasOne(FeedAiContent::class)->whereNull('feed_profile_id');
    }
}
