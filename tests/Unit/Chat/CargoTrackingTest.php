<?php

namespace Tests\Unit\Chat;

use App\Support\CargoTracking;
use PHPUnit\Framework\TestCase;

/**
 * 8 Eyl 2026 canlı doğrulama: Sürat Kargo takip sayfasını yeniledi.
 *   www.suratkargo.com.tr/gonderi-takip?code=…   → HTTP 404 (eski, kırık)
 *   www.suratkargo.com.tr/KargoTakip/            → HTTP 200 (güncel)
 * Hepsijet'te de /gonderi-takip/ → 404, /gonderi-takibi/ → 200.
 */
class CargoTrackingTest extends TestCase
{
    public function test_surat_kargo_guncel_takip_adresini_uretir(): void
    {
        $this->assertSame(
            'https://www.suratkargo.com.tr/KargoTakip/?kargotakipno=11342076118841',
            CargoTracking::url('Sürat Kargo', '11342076118841')
        );
        // Diyakritiksiz yazım da tanınmalı (kayıtlarda ikisi de geçiyor)
        $this->assertStringContainsString('KargoTakip', (string) CargoTracking::url('surat kargo', '123'));
    }

    public function test_surat_takip_no_yoksa_siparis_referansina_duser(): void
    {
        $this->assertSame(
            'https://www.suratkargo.com.tr/KargoTakip/?kargotakipno=TB4151997146',
            CargoTracking::url('Sürat Kargo', null, 'TB4151997146')
        );
    }

    public function test_hepsijet_dogru_yolu_kullanir(): void
    {
        $this->assertSame(
            'https://www.hepsijet.com/gonderi-takibi/123456',
            CargoTracking::url('Hepsijet', '123456')
        );
    }

    public function test_bilinmeyen_firmada_link_uretmez(): void
    {
        $this->assertNull(CargoTracking::url('DHL Express', '123456'));
        $this->assertNull(CargoTracking::url('', '123456'));
        $this->assertNull(CargoTracking::url('MNG Kargo', ''));   // takip no yoksa link yok
    }

    public function test_cumle_takip_numarasini_da_icerir(): void
    {
        // Sürat sayfası artık numarayı ÖN DOLDURMUYOR (captcha'lı yeni ekran) —
        // müşterinin kutuya elle yazabilmesi için numara metinde geçmek zorunda.
        $sentence = CargoTracking::sentence('Sürat Kargo', '11342076118841');
        $this->assertStringContainsString('11342076118841', $sentence);
        $this->assertStringContainsString('KargoTakip', $sentence);
        $this->assertStringContainsString('Sürat Kargo', $sentence);
    }
}
