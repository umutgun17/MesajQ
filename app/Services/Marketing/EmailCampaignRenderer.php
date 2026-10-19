<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * E-posta kampanyası içerik üretici — dispatcher ve test gönderimi aynı çıktıyı kullanır.
 *
 * Yaptıkları:
 *  - {{ad}} / {{kupon}} yer tutucularını doldurur (konu + gövde),
 *  - gövdedeki http(s) linklerini tık-takip ucuna sarar (/e/c/{token}?u=şifreli-hedef),
 *  - açılma pikseli ekler (/e/o/{token}.gif),
 *  - alta abonelikten-çıkış footer'ı ekler + List-Unsubscribe için URL döner.
 */
class EmailCampaignRenderer
{
    /**
     * @return array{subject:string, html:string, body:string, unsubscribe_url:string}
     */
    public static function render(MarketingCampaign $campaign, ?string $name, string $email, string $token): array
    {
        $vars = [
            '{{ad}}' => trim((string) $name) !== '' ? trim((string) $name) : 'Değerli müşterimiz',
            '{{kupon}}' => (string) ($campaign->coupon_code ?? ''),
        ];

        $subject = strtr((string) $campaign->subject, $vars);
        $html = strtr((string) $campaign->body_html, $vars);

        $base = rtrim((string) config('app.url'), '/');
        $unsub = UnsubscribeLink::for($campaign->tenant_id, $email);

        // Tık takibi: http(s) linkleri /e/c/{token}?u=<şifreli hedef> üzerinden geçer.
        // Takip/çıkış linklerine dokunulmaz (çift sarma + unsubscribe kırılması olmasın).
        $html = preg_replace_callback(
            '/href\s*=\s*"(https?:\/\/[^"]+)"/i',
            function (array $m) use ($base, $token) {
                $url = html_entity_decode($m[1]);
                if (str_starts_with($url, $base.'/e/') || str_starts_with($url, $base.'/u')) {
                    return $m[0];
                }

                return 'href="'.$base.'/e/c/'.$token.'?u='.rawurlencode(Crypt::encryptString($url)).'"';
            },
            $html,
        ) ?? $html;

        // Abonelikten çıkış footer'ı (gövdede zaten yoksa)
        if (! str_contains($html, '/u?')) {
            $footer = '<div style="margin-top:24px;padding-top:12px;border-top:1px solid #e5e5e5;'
                .'font-size:11px;color:#999;text-align:center">'
                .'Bu e-postayı almak istemiyorsanız <a href="'.$unsub.'" style="color:#999">abonelikten çıkabilirsiniz</a>.'
                .'</div>';
            $html = str_contains($html, '</body>')
                ? str_replace('</body>', $footer.'</body>', $html)
                : $html.$footer;
        }

        // Açılma pikseli
        $pixel = '<img src="'.$base.'/e/o/'.$token.'.gif" width="1" height="1" alt="" style="display:none">';
        $html = str_contains($html, '</body>')
            ? str_replace('</body>', $pixel.'</body>', $html)
            : $html.$pixel;

        return [
            'subject' => $subject,
            'html' => $html,
            'body' => Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? ''), 1000),
            'unsubscribe_url' => $unsub,
        ];
    }
}
