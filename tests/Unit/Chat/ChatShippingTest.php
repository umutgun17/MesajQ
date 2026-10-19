<?php

namespace Tests\Unit\Chat;

use App\Services\Chat\ChatShipping;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Chatbot'un kargoya-çıkış cümlesi, ürün detayındaki "Bugün Kargoda" rozetiyle
 * (public/widget/shipping.js nextShip) AYNI takvimi kullanmak zorundadır.
 * 8 Eyl 2026'da müşteri farkı yakaladı: "Aynı gün kargo bildirgesi olmasına
 * rağmen nasıl 1-3 iş günü diyebiliyorsunuz?"
 *
 * Aşağıdaki takvim t1'in (Tonny Black) canlı ayarıdır:
 * Pzt-Cum 11:00 kesim, Cumartesi kargosu için Cuma 23:59'a kadar, Pazar yok.
 */
class ChatShippingTest extends TestCase
{
    /** @return array<int, array{d: int, h: int, m: int, prev: bool}> */
    protected function schedule(): array
    {
        return [
            ['d' => 1, 'h' => 11, 'm' => 0, 'prev' => false],
            ['d' => 2, 'h' => 11, 'm' => 0, 'prev' => false],
            ['d' => 3, 'h' => 11, 'm' => 0, 'prev' => false],
            ['d' => 4, 'h' => 11, 'm' => 0, 'prev' => false],
            ['d' => 5, 'h' => 11, 'm' => 0, 'prev' => false],
            ['d' => 6, 'h' => 23, 'm' => 59, 'prev' => true],
        ];
    }

    protected function at(string $t): CarbonImmutable
    {
        return CarbonImmutable::parse($t, ChatShipping::TZ);
    }

    public function test_kesimden_once_ayni_gun_kargoda(): void
    {
        // Salı 09:30 → kesim bugün 11:00, kargo bugün
        $ns = ChatShipping::nextShipFor($this->schedule(), $this->at('2026-09-08 09:30'));
        $this->assertSame(0, $ns['offset']);
        $this->assertSame('2026-09-08 11:00', $ns['deadline']->format('Y-m-d H:i'));
        $this->assertStringContainsString('Bugün saat 11:00', ChatShipping::lineFor($this->schedule(), $this->at('2026-09-08 09:30')));
    }

    public function test_kesimden_sonra_ertesi_kargo_gunu(): void
    {
        // Salı 11:30 → Çarşamba
        $ns = ChatShipping::nextShipFor($this->schedule(), $this->at('2026-09-08 11:30'));
        $this->assertSame(1, $ns['offset']);
        $this->assertSame('2026-09-09', $ns['ship']->format('Y-m-d'));
        $this->assertStringContainsString('Yarın saat 11:00', ChatShipping::lineFor($this->schedule(), $this->at('2026-09-08 11:30')));
    }

    public function test_cuma_ogleden_sonra_cumartesi_kargosuna_yetisir(): void
    {
        // prev=true satırı: Cumartesi kargosunun kesimi CUMA 23:59
        $ns = ChatShipping::nextShipFor($this->schedule(), $this->at('2026-09-11 14:00'));
        $this->assertSame('2026-09-12', $ns['ship']->format('Y-m-d'));         // Cumartesi
        $this->assertSame('2026-09-11 23:59', $ns['deadline']->format('Y-m-d H:i'));
        // 23:59 kesiminde saat söylemek yanıltıcı olur
        $line = ChatShipping::lineFor($this->schedule(), $this->at('2026-09-11 14:00'));
        $this->assertStringContainsString('gün sonuna kadar', $line);
        $this->assertStringContainsString('yarın kargoya', $line);
    }

    public function test_cumartesi_ve_pazar_pazartesiye_atlar(): void
    {
        foreach (['2026-09-12 12:00', '2026-09-13 10:00', '2026-09-13 23:00'] as $t) {
            $ns = ChatShipping::nextShipFor($this->schedule(), $this->at($t));
            $this->assertSame('2026-09-14', $ns['ship']->format('Y-m-d'), "girdi: {$t}");
        }
    }

    public function test_takvim_yoksa_soz_verilmez(): void
    {
        $this->assertNull(ChatShipping::nextShipFor([], $this->at('2026-09-08 09:30')));
        $this->assertNull(ChatShipping::lineFor([], $this->at('2026-09-08 09:30')));
    }
}
