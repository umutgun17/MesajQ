<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Panelden düzenlenebilen HTML e-posta şablonu (GrapesJS ile).
 * Otomasyonlar `key` ile şablonu bulur ve değişkenleri yerleştirir.
 */
class MarketingEmailTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'key',
        'name',
        'category',
        'subject',
        'preheader',
        'html',
        'design',
        'is_active',
        'is_system',
        'variables',
    ];

    protected function casts(): array
    {
        return [
            'design' => 'array',
            'variables' => 'array',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Şablonu değişkenlerle render et: {{ key }} → değer.
     * Tanımsız değişkenler boş string ile değiştirilir.
     *
     * @param array<string,string|int|float|null> $vars
     * @return array{subject:string,html:string,preheader:?string}
     */
    public function render(array $vars = []): array
    {
        return [
            'subject' => self::interpolate($this->subject ?? '', $vars),
            'html' => self::interpolate($this->html ?? '', $vars),
            'preheader' => $this->preheader ? self::interpolate($this->preheader, $vars) : null,
        ];
    }

    public static function interpolate(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
            return isset($vars[$m[1]]) ? (string) $vars[$m[1]] : '';
        }, $text);
    }
}
