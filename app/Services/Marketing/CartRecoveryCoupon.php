<?php

namespace App\Services\Marketing;

use App\Models\Channel;
use App\Models\MarketingJourney;
use App\Services\TicimaxService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sepet kurtarma için tek kullanımlık indirim kuponu (Ticimax hediye çeki) üretir.
 *
 * SMS adımında benzersiz bir kod oluşturulur ve journey.meta'ya yazılır; WhatsApp
 * adımı AYNI kodu kullanır. Kullanım/ciro takibi sipariş coupon_code eşleşmesiyle
 * (dönüşüm tespitinde) yapılır.
 */
class CartRecoveryCoupon
{
    /** Bir journey için kupon kodu döndürür; yoksa üretir (Ticimax'a yazar) ve kaydeder. */
    public function ensureFor(MarketingJourney $journey, float $amount, int $validHours = 48, float $minBasket = 0, bool $forceNew = false): ?string
    {
        $meta = $journey->meta ?? [];
        if (! $forceNew && ! empty($meta['coupon_code'])) {
            return $meta['coupon_code']; // zaten üretilmiş (WhatsApp adımı tekrar kullanır)
        }

        // Yenileme: eski kod geçmişe alınır (müşteri eski SMS'teki kodu kullanırsa
        // dönüşüm eşleşmesi bozulmasın — findConversion geçmişi de kontrol eder)
        if ($forceNew && ! empty($meta['coupon_code'])) {
            $meta['coupon_code_history'] = array_values(array_unique(array_merge(
                $meta['coupon_code_history'] ?? [], [$meta['coupon_code']]
            )));
        }

        $service = $this->resolveTicimax($journey->tenant_id);
        if (! $service) {
            Log::warning('[cart-recovery] Ticimax kanalı yok, kupon üretilemedi', ['journey' => $journey->id]);

            return null;
        }

        $code = 'SEPET-' . strtoupper(Str::random(6));
        $res = $service->createGiftVoucher($this->request($code, $amount, $validHours, $minBasket));

        if (! ($res['success'] ?? false)) {
            Log::error('[cart-recovery] kupon oluşturulamadı', [
                'journey' => $journey->id, 'error' => $res['error'] ?? '?',
            ]);

            return null;
        }

        $code = $res['code'] ?: $code; // Ticimax kendi kodunu döndürebilir
        $meta['coupon_code'] = $code;
        $meta['coupon_id'] = $res['id'] ?? null;
        $meta['coupon_amount'] = $amount;
        $meta['coupon_min_basket'] = $minBasket;
        $meta['coupon_issued_at'] = now()->toIso8601String();
        // WhatsApp adımı "kalan saat"i buradan hesaplar; süresi geçmiş koda mesaj atılmaz
        $meta['coupon_expires_at'] = now()->addHours(max(1, $validHours))->toIso8601String();
        $journey->update(['meta' => $meta]);

        Log::info('[cart-recovery] kupon üretildi', ['journey' => $journey->id, 'code' => $code, 'amount' => $amount]);

        return $code;
    }

    /** @return array<string,mixed> Ticimax WebHediyeCekiOlusturRequest */
    protected function request(string $code, float $amount, int $validHours, float $minBasket): array
    {
        return [
            'Kod' => $code,
            'IndirimTipi' => 0,            // 0 = Tutar (TL)
            'IndirimDegeri' => $amount,
            'MinimumUrunTutari' => $minBasket,
            'MinTutarKosuldaGecerli' => $minBasket > 0,
            'KargoUcretsiz' => false,
            'KullanimSayisi' => 1,
            'UyeMaksimumKullanimSayisi' => 1,
            'TarihBaslagic' => now()->setTimezone('Europe/Istanbul')->format('Y-m-d\TH:i:s'),
            'TarihBitis' => now()->addHours(max(1, $validHours))->setTimezone('Europe/Istanbul')->format('Y-m-d\TH:i:s'),
            'WebAktif' => true,
            'MobilAktif' => true,
            'AndroidAktif' => true,
            'IOSAktif' => true,
            'GrupAdi' => 'Sepet Kurtarma',
            'IndirimliUrunleKullanilabilir' => true,
            'BaskaKampanyalarlaBilestirilebilir' => true,
            'SepetteKosulsuzGoster' => false,
        ];
    }

    protected function resolveTicimax(int $tenantId): ?TicimaxService
    {
        $channel = Channel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('type', 'like', 'ticimax%')
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN type = 'ticimax-ecommerce' THEN 0 ELSE 1 END")
            ->first();

        if (! $channel) {
            return null;
        }

        $service = TicimaxService::fromChannel($channel);

        return $service->isAvailable() ? $service : null;
    }
}
