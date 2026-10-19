<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;
use SoapClient;
use SoapFault;

class TicimaxService
{
    protected ?SoapClient $client = null;
    protected array $clients = [];
    protected string $wsdlUrl;
    protected string $authCode;
    protected string $baseUrl = '';
    protected int $callCount = 0;

    public function __construct()
    {
        $this->wsdlUrl = config('ticimax.wsdl_url', '');
        $this->authCode = config('ticimax.auth_code', '');
        $this->baseUrl = $this->deriveBaseUrl($this->wsdlUrl);
    }

    public static function fromChannel(Channel $channel): self
    {
        $instance = new self();
        $creds = $channel->credentials ?? [];
        $instance->wsdlUrl  = $creds['wsdl_url'] ?? '';
        $instance->authCode = $creds['auth_code'] ?? '';
        $instance->client   = null;
        $instance->baseUrl  = $instance->deriveBaseUrl($instance->wsdlUrl);

        return $instance;
    }

    protected function deriveBaseUrl(string $wsdlUrl): string
    {
        // "https://www.tonnyblack.com.tr/Servis/CustomServis.svc?wsdl" → "https://www.tonnyblack.com.tr/Servis/"
        $url = preg_replace('/[^\/]+\.svc(\?wsdl)?$/i', '', $wsdlUrl);

        return rtrim($url, '/') . '/';
    }

    protected function getWsdlForService(string $service): string
    {
        return match ($service) {
            'custom'  => $this->wsdlUrl,
            'product' => $this->baseUrl . 'UrunServis.svc?wsdl',
            'order'   => $this->baseUrl . 'SiparisServis.svc?wsdl',
            'member'  => $this->baseUrl . 'UyeServis.svc?wsdl',
            default   => throw new \InvalidArgumentException("Unknown Ticimax service: {$service}"),
        };
    }

    public function isAvailable(): bool
    {
        return ! empty($this->authCode) && ! empty($this->wsdlUrl);
    }

    /**
     * Get support tickets with pagination.
     * Uses GetSupportTickets with -1 sentinel values (Ticimax convention for "no filter").
     *
     * @param int $destekId Ticket ID filter (-1 = all)
     * @param int $uyeId Member ID filter (-1 = all)
     * @param int $page Page number (1-indexed)
     * @param int $pageSize Records per page
     */
    public function getTickets(int $destekId = -1, int $uyeId = -1, int $page = 1, int $pageSize = 50): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'request' => [
                'DestekId' => $destekId,
                'UyeID' => $uyeId,
            ],
            'sayfalama' => [
                'SayfaNo' => $page,
                'KayitSayisi' => $pageSize,
                'SiralamaDegeri' => 'EklemeTarihi',
                'SiralamaYonu' => 'DESC',
            ],
        ];

        return $this->callMethod('GetSupportTickets', $params);
    }

    /**
     * Get messages (conversation thread) for a specific ticket.
     */
    public function getTicketMessages(int $ticketId, int $uyeId = -1): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'request' => [
                'DestekId' => $ticketId,
                'UyeID' => $uyeId,
            ],
        ];

        return $this->callMethod('GetSupportTicketMessages', $params);
    }

    /**
     * Send an answer to a support ticket.
     *
     * @param int $ticketId Destek talebi ID
     * @param string $message Cevap mesaji
     * @param int $uyeId Cevaplayan kullanici ID (0 = admin/merchant)
     */
    public function answerTicket(int $ticketId, string $message, int $uyeId = 1): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'request' => [
                'DestekId' => $ticketId,
                'Mesaj' => $message,
                'UyeId' => $uyeId,
            ],
        ];

        return $this->callMethod('SaveSupportTicketAnswer', $params);
    }

    /**
     * Update ticket status.
     *
     * @param int $ticketId Destek talebi ID (KonuId in WSDL)
     * @param int $newStatus Yeni durum ID
     */
    public function updateTicketStatus(int $ticketId, int $newStatus): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'request' => [
                'KonuId' => $ticketId,
                'YeniDurum' => $newStatus,
            ],
        ];

        return $this->callMethod('UpdateTicketStatus', $params);
    }

    /**
     * Get available support ticket subjects/categories.
     */
    public function getSubjects(): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'request' => [],
        ];

        return $this->callMethod('GetSupportTicketSubject', $params);
    }

    // ── Product Methods (UrunServis.svc) ──

    /**
     * Get product cards with pagination.
     * WSDL: SelectUrun(UyeKodu, f: UrunFiltre, s: UrunSayfalama)
     * UrunServis uses BaslangicIndex (0-based offset) — different from CustomServis's SayfaNo.
     */
    public function getProductCards(array $filters = [], int $page = 1, int $pageSize = 100): array
    {
        $offset = ($page - 1) * $pageSize;

        $params = [
            'UyeKodu' => $this->authCode,
            'f' => array_merge(['Aktif' => -1], $filters),
            's' => [
                'BaslangicIndex' => $offset,
                'KayitSayisi' => $pageSize,
                'KayitSayisinaGoreGetir' => true,
                'SiralamaDegeri' => 'ID',
                'SiralamaYonu' => 'ASC',
            ],
        ];

        return $this->callMethod('SelectUrun', $params, 'product');
    }

    /**
     * Get total product count.
     * WSDL: SelectUrunCount(UyeKodu, f: UrunFiltre)
     */
    public function getProductCount(array $filters = []): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'f' => array_merge(['Aktif' => -1], $filters),
        ];

        return $this->callMethod('SelectUrunCount', $params, 'product');
    }

    /**
     * Get product variants with pagination.
     * WSDL: SelectVaryasyon(UyeKodu, f: VaryasyonFiltre, s: UrunSayfalama, varyasyonAyar)
     */
    public function getVariants(array $filters = [], int $page = 1, int $pageSize = 100): array
    {
        $offset = ($page - 1) * $pageSize;

        $params = [
            'UyeKodu' => $this->authCode,
            'f' => array_merge(['Aktif' => -1], $filters),
            's' => [
                'BaslangicIndex' => $offset,
                'KayitSayisi' => $pageSize,
                'KayitSayisinaGoreGetir' => true,
                'SiralamaDegeri' => 'ID',
                'SiralamaYonu' => 'ASC',
            ],
            'varyasyonAyar' => [
                'KategoriGetir' => false,
            ],
        ];

        return $this->callMethod('SelectVaryasyon', $params, 'product');
    }

    /**
     * Get categories.
     * WSDL: SelectKategori(UyeKodu, kategoriID, dil, parentID)
     */
    public function getCategories(int $id = -1, string $lang = 'tr', int $parentId = -1): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'kategoriID' => $id,
            'dil' => $lang,
            'parentID' => $parentId,
        ];

        return $this->callMethod('SelectKategori', $params, 'product');
    }

    /**
     * Get brands.
     * WSDL: SelectMarka(UyeKodu, markaID, kategoriID)
     */
    public function getBrands(int $id = -1, int $categoryId = -1): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'markaID' => $id,
            'kategoriID' => $categoryId,
        ];

        return $this->callMethod('SelectMarka', $params, 'product');
    }

    /**
     * Get technical detail properties (specification names).
     * WSDL: SelectTeknikDetayOzellik(UyeKodu, teknikDetayOzellikID)
     */
    public function getTechSpecProperties(int $id = -1): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'teknikDetayOzellikID' => $id,
        ];

        return $this->callMethod('SelectTeknikDetayOzellik', $params, 'product');
    }

    /**
     * Get technical detail values (specification values).
     * WSDL: SelectTeknikDetayDeger(UyeKodu, teknikDetayDegerID)
     */
    public function getTechSpecValues(int $id = -1): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'teknikDetayDegerID' => $id,
        ];

        return $this->callMethod('SelectTeknikDetayDeger', $params, 'product');
    }

    /**
     * Ürün↔kategori atamalarını okur (çoklu kategori + kategori-içi Sira).
     * WSDL: SelectUrunKategori(UyeKodu, KategoriID, UrunKartiID) — -1 = filtre yok.
     * Dönüş: SelectUrunKategoriResult.UrunKategori[] { KategoriID, Sira, UrunKartiID }
     * (tek kayıtta SOAP tek nesne döndürür — çağıran diziye sarmalı).
     */
    public function getProductCategoryAssignments(int $kategoriId = -1, int $urunKartiId = -1): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'KategoriID' => $kategoriId,
            'UrunKartiID' => $urunKartiId,
        ];

        return $this->callMethod('SelectUrunKategori', $params, 'product');
    }

    /**
     * Ürün↔kategori atamalarını yazar (Kategori Denetçisi + Akıllı Kategori Sıralaması).
     * WSDL: SaveUrunKategori(UyeKodu, list: ArrayOfUrunKategori) — batch.
     * Var olan atamada yalnız Sira güncellenir; olmayan atama EKLENİR (ürün kartı ezilmez).
     *
     * ⛔ CANLI YAZMA: mağaza vitrinini değiştirir. dry-run kararı çağıran katmanda.
     *
     * @param array<int,array{UrunKartiID:int, KategoriID:int, Sira:int}> $list
     */
    public function saveProductCategories(array $list): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'list' => ['UrunKategori' => array_values($list)],
        ];

        return $this->callMethod('SaveUrunKategori', $params, 'product');
    }

    /**
     * Ürün↔kategori atamalarını SİLER (yanlış kategoriden çıkarma).
     * WSDL: DeleteUrunKategori(UyeKodu, list: ArrayOfUrunKategori)
     *
     * ⛔ CANLI YAZMA. @see saveProductCategories
     *
     * @param array<int,array{UrunKartiID:int, KategoriID:int}> $list
     */
    public function deleteProductCategories(array $list): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'list' => ['UrunKategori' => array_values($list)],
        ];

        return $this->callMethod('DeleteUrunKategori', $params, 'product');
    }

    /**
     * Yeni kategori açar / mevcut kategoriyi günceller (ID>0 = güncelleme).
     * WSDL: SaveKategori(UyeKodu, kategori: Kategori{ID, PID, Tanim, Aktif, ...})
     *
     * ⛔ CANLI YAZMA: mağaza kategori ağacını değiştirir — yalnız ONAYLI
     * create_category önerileri için (Kategori Denetçisi asla otomatik çağırmaz).
     */
    public function saveCategory(array $kategori): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'kategori' => $kategori,
        ];

        return $this->callMethod('SaveKategori', $params, 'product');
    }

    /**
     * Kategori dil alanlarını KISMİ günceller (yalnız Ayar'da true olan alanlar yazılır).
     * WSDL: UpdateKategoriDil(UyeKodu, request: UpdateKategoriDilRequest{Ayar: KategoriDilAyar, Dil, Liste: KategoriDil[]})
     * KategoriDil{ID, Tanim, SeoSayfaBaslik, SeoSayfaAciklama, SeoAnahtarKelime, Icerik}
     * KategoriDilAyar{TanimGuncelle, SeoSayfaBaslikGuncelle, SeoSayfaAciklamaGuncelle, SeoAnahtarKelimeGuncelle, IcerikGuncelle}
     *
     * ⛔ CANLI YAZMA — SEO Aksiyon Planı kullanır; dry-run kararı çağıran katmanda.
     */
    public function updateCategoryLang(array $liste, array $ayar, string $dil = 'tr'): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'request' => [
                'Ayar' => $ayar,
                'Dil' => $dil,
                'Liste' => ['KategoriDil' => $liste],
            ],
        ];

        return $this->callMethod('UpdateKategoriDil', $params, 'product');
    }

    /**
     * Ürün kartını KISMİ günceller — yalnız ayar bayrağı true olan alanlar yazılır
     * (Ticimax deseni: alan + yanındaki ...Guncelle bayrağı).
     * WSDL: UrunKartiGuncelle(UyeKodu, urunKarti: UrunKarti, urunKartiAyar: UrunKartiAyar)
     * Kategori Denetçisi kullanımı: AnaKategoriGuncelle + KategoriGuncelle (+ isteğe bağlı
     * OncekiKategoriEslestirmeleriniTemizle) ile ürünün kategori üyeliği düzeltilir.
     *
     * ⛔ CANLI YAZMA. dry-run kararı çağıran katmanda.
     */
    public function updateProductCard(array $urunKarti, array $ayar): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'urunKarti' => $urunKarti,
            'urunKartiAyar' => $ayar,
        ];

        return $this->callMethod('UrunKartiGuncelle', $params, 'product');
    }

    /**
     * Ürün kartına video bağlar / videoyu kaldırır.
     * WSDL: SaveUrunVideo(UyeKodu, request: SaveUrunKartiVideoRequest{EkliVideoKaldir, UrunKartiId, VideoAyar: UrunKartiVideo})
     * VideoAyar.VideoLink doğrudan MP4 adresidir (VideoTagTipi 'video'); kart başına tek video tutulur.
     *
     * ⛔ CANLI YAZMA. dry-run kararı çağıran katmanda.
     */
    public function saveProductVideo(array $request): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'request' => $request,
        ];

        return $this->callMethod('SaveUrunVideo', $params, 'product');
    }

    /**
     * Tek varyasyonun stok adedini günceller (Stok Aynalama).
     * WSDL: VaryasyonGuncelle(UyeKodu, urun: Varyasyon{ID, StokAdedi}, ayar: VaryasyonAyar{StokAdediGuncelle})
     * Yalnız StokAdediGuncelle bayrağı açılır; fiyat/aktiflik/barkod gibi diğer alanlara dokunulmaz.
     * Dönüş: VaryasyonGuncelleResult = 1 başarı.
     *
     * ⛔ CANLI YAZMA. dry-run kararı çağıran katmanda.
     */
    public function updateVariantStock(int $variantId, float $stock): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'urun' => ['ID' => $variantId, 'StokAdedi' => $stock],
            'ayar' => ['StokAdediGuncelle' => true],
        ];

        $res = $this->callMethod('VaryasyonGuncelle', $params, 'product');
        if ($res['success'] && (int) ($res['data']['VaryasyonGuncelleResult'] ?? 0) !== 1) {
            return ['success' => false, 'error' => 'VaryasyonGuncelleResult=' . json_encode($res['data']['VaryasyonGuncelleResult'] ?? null)];
        }

        return $res;
    }

    /**
     * Kampanya tanımı (CustomServis GetKampanyaV2) — iade hesabında sepet kademesi oranı ve eşiği
     * için. ADINA GÜVENİLMEZ: Tonny'de "5.000 TL üzerine ek %10" kampanyasının değeri 5'tir
     * (kademeler üst üste biner). 10 dk önbellek; okunamazsa null.
     *
     * @return array{id:int, name:string, type:int, op:int, value:float, min:float}|null
     */
    public function getCampaign(int $id): ?array
    {
        return \Illuminate\Support\Facades\Cache::remember('ticimax:campaign:' . md5($this->baseUrl) . ':' . $id, 600, function () use ($id) {
            $res = $this->callMethod('GetKampanyaV2', ['UyeKodu' => $this->authCode, 'KampanyaId' => $id]);
            $k = $res['data']['GetKampanyaV2Result']['WebKampanyaTicimax'] ?? null;
            if (! ($res['success'] ?? false) || ! is_array($k) || empty($k['ID'])) {
                return null;
            }

            return [
                'id' => (int) $k['ID'],
                'name' => trim((string) ($k['Tanim'] ?? '')),
                'type' => (int) ($k['Tip'] ?? 0),               // 1 = sepet kampanyası
                'op' => (int) ($k['IslemTipi'] ?? 0),           // 1 = yüzde
                'value' => (float) ($k['IslemDegeri'] ?? 0),
                'min' => (float) ($k['MinAlisverisTutari'] ?? 0),
            ];
        }) ?: null;
    }

    // ── Favorite Methods (CustomServis.svc) ──

    /**
     * Get favorite products with pagination.
     * WSDL: GetFavoriUrunler(UyeKodu, request: WebFavoriUrunlerRequest)
     */
    public function getFavorites(array $filters = [], int $page = 1, int $pageSize = 200): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'request' => array_merge([
                'BaslangicTarihi' => '2020-01-01T00:00:00',
                'BitisTarihi' => now()->format('Y-m-d\TH:i:s'),
                'KayitSayisi' => $pageSize,
                'SayfaNo' => $page,
                'UyeID' => -1,
            ], $filters),
        ];

        return $this->callMethod('GetFavoriUrunler', $params);
    }

    // ── Order Methods (SiparisServis.svc) ──

    /**
     * Get orders with pagination.
     * WSDL: SelectSiparis(UyeKodu, f: SiparisFiltre, s: SiparisSayfalama)
     * SiparisServis uses BaslangicIndex (0-based offset) like UrunServis.
     * All int filter fields MUST be -1 (sentinel for "no filter").
     *
     * @param bool $withProducts Include order items (UrunGetir)
     */
    public function getOrders(array $filters = [], int $page = 1, int $pageSize = 200, bool $withProducts = true, string $sortDir = 'ASC'): array
    {
        // BaslangicIndex = page index (NOT row offset). Actual row = BaslangicIndex × KayitSayisi.
        $offset = $page - 1;

        // WSDL: WebSiparisFiltre — only valid fields per SiparisServis.svc WSDL
        $params = [
            'UyeKodu' => $this->authCode,
            'f' => array_merge([
                'SiparisID' => -1,
                'SiparisDurumu' => -1,
                'OdemeTipi' => -1,
                'OdemeDurumu' => -1,
                'OdemeTamamlandi' => -1,
                'KargoFirmaID' => -1,
                'PaketlemeDurumu' => -1,
                'PazaryeriIhracat' => -1,
                'EFaturaURL' => -1,
                'EntegrasyonAktarildi' => -1,
                'KargoEntegrasyonTakipDurumu' => -1,
                'TeslimatMagazaID' => -1,
                'UyeID' => -1,
                'UrunGetir' => $withProducts,
                'OdemeGetir' => false,
                'KampanyaGetir' => true, // kampanya adları (CRM sipariş kartında indirim kırılımı)
                'IptalEdilmisUrunler' => true,
            ], $filters),
            's' => [
                'BaslangicIndex' => $offset,
                'KayitSayisi' => $pageSize,
                'SiralamaDegeri' => 'ID',
                // DESC → en yeni sipariş önce (durum tazeleme: yeni ödeme onayları hızlı gelsin)
                'SiralamaYonu' => strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC',
            ],
        ];

        return $this->callMethod('SelectSiparis', $params, 'order');
    }

    /**
     * Tek bir siparişi SiparisNo ile getirir (iade modülü public lookup).
     * WebSiparisFiltre, SiparisNo filtresini destekler (WSDL'de doğrulandı).
     *
     * @return array{success:bool, order?:array, error?:string}
     */
    public function getOrderByNo(string $siparisNo): array
    {
        $siparisNo = trim($siparisNo);
        if ($siparisNo === '') {
            return ['success' => false, 'error' => 'empty_order_no'];
        }

        // OdemeGetir: iade hesabı brüt tahsilatı ödeme satırlarından okur (panel iadesi OdenenTutar'ı düşürür).
        $result = $this->getOrders(['SiparisNo' => $siparisNo, 'OdemeGetir' => true], 1, 5, true);

        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'lookup_failed'];
        }

        $orders = $result['data']['SelectSiparisResult']['WebSiparis'] ?? [];
        // Tek sonuç geldiğinde SOAP tek nesne döndürebilir — diziye sar
        if (isset($orders['SiparisNo']) || isset($orders['SiparisID'])) {
            $orders = [$orders];
        }

        // SiparisNo birebir eşleşeni seç (filtre gevşek davranabilir)
        foreach ($orders as $o) {
            if ((string) trim($o['SiparisNo'] ?? '') === $siparisNo) {
                return ['success' => true, 'order' => $o];
            }
        }

        return ['success' => false, 'error' => 'not_found'];
    }

    /**
     * Tek bir siparişi Ticimax SiparisID ile getirir (personel ekranı: ID veya No ile arama).
     *
     * @return array{success:bool, order?:array, error?:string}
     */
    public function getOrderById(int $siparisId): array
    {
        if ($siparisId <= 0) {
            return ['success' => false, 'error' => 'empty_order_id'];
        }

        // OdemeGetir: iade hesabı brüt tahsilatı ödeme satırlarından okur (panel iadesi OdenenTutar'ı düşürür).
        $result = $this->getOrders(['SiparisID' => $siparisId, 'OdemeGetir' => true], 1, 5, true);
        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'lookup_failed'];
        }

        $orders = $result['data']['SelectSiparisResult']['WebSiparis'] ?? [];
        if (isset($orders['SiparisNo']) || isset($orders['SiparisID']) || isset($orders['ID'])) {
            $orders = [$orders];
        }
        foreach ($orders as $o) {
            if ((int) ($o['ID'] ?? $o['SiparisID'] ?? 0) === $siparisId) {
                return ['success' => true, 'order' => $o];
            }
        }

        return ['success' => false, 'error' => 'not_found'];
    }

    /**
     * Sipariş ÖZEL ALANLARINI (OzelAlan1..3) günceller — Ticimax panelde sipariş detayında görünür.
     * WSDL: UpdateSiparisOzelAlanlar(UyeKodu, request: SetSiparisOzelAlanlarRequest{ SiparisId,
     *   OzelAlan1..3, SiparisOzelAlan1..3Guncelle }). Yalnız verilen alanlar güncellenir.
     *
     * Ticimax'ta "personel notu" yazan bir API metodu YOK (SiparisNotu müşteri notudur ve yalnız
     * SaveSiparis ile — tüm siparişi yeniden yazarak — değişir); iade modülü bilgi notlarını bu
     * yüzden özel alana yazar.
     *
     * @param  array<int,string>  $fields  [1 => 'metin', 3 => 'metin'] (alan no → yeni değer)
     * @return array{success:bool, error:?string}
     */
    public function updateOrderCustomFields(int $siparisId, array $fields): array
    {
        $req = ['SiparisId' => $siparisId];
        foreach ([1, 2, 3] as $n) {
            $req["OzelAlan{$n}"] = array_key_exists($n, $fields) ? (string) $fields[$n] : '';
            $req["SiparisOzelAlan{$n}Guncelle"] = array_key_exists($n, $fields);
        }

        $res = $this->callMethod('UpdateSiparisOzelAlanlar', [
            'UyeKodu' => $this->authCode,
            'request' => $req,
        ], 'order');

        return ['success' => (bool) ($res['success'] ?? false), 'error' => $res['error'] ?? null];
    }

    /**
     * Siparişin ÖDEME bloğunu (WebSiparisOdeme) getirir — karta iade (Faz 4 original) için sağlayıcı +
     * iade referansı kaynağı. `OdemeGetir=true` ile çağrılır (salt-okuma).
     *
     * Normalize alanlar: id (Ticimax ödeme kayıt no), odeme_tipi (sağlayıcı/yöntem kodu),
     * odeme_siparis_id (sağlayıcıya gönderilen sipariş referansı = PayTR merchant_oid / iyzico conv.),
     * pos_referans_id (POS/banka referansı), onaylandi, tutar, para_birimi, ek_bilgi.
     * Birden çok ödeme varsa rankPaymentsBySuccess() ile en olası başarılı satır tercih edilir.
     *
     * @return array{success:bool, payment?:array, payments?:array, error?:string}
     */
    public function getOrderPayment(int $siparisId): array
    {
        if ($siparisId <= 0) {
            return ['success' => false, 'error' => 'invalid_order_id'];
        }

        // UrunGetir=true ŞART: Ticimax bazı siparişlerde (ör. iptal edilmiş) UrunGetir=false
        // istendiğinde kaydı HİÇ döndürmüyor → ödeme bloğu okunamaz. Canlı doğrulandı (630729).
        $result = $this->getOrders(['SiparisID' => $siparisId, 'OdemeGetir' => true], 1, 5, true);
        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'lookup_failed'];
        }

        $orders = $result['data']['SelectSiparisResult']['WebSiparis'] ?? [];
        if (isset($orders['ID']) || isset($orders['SiparisID'])) {
            $orders = [$orders];
        }
        $order = $orders[0] ?? null;
        if (! is_array($order)) {
            return ['success' => false, 'error' => 'not_found'];
        }

        // Odemeler.WebSiparisOdeme — tek ödemede nesne, çoklu ödemede dizi.
        $raw = $order['Odemeler']['WebSiparisOdeme'] ?? ($order['Odemeler'] ?? null);
        if (! is_array($raw)) {
            return ['success' => false, 'error' => 'no_payment'];
        }
        $list = isset($raw[0]) ? $raw : [$raw];

        $payments = [];
        foreach ($list as $p) {
            if (! is_array($p)) {
                continue;
            }
            $payments[] = [
                'id' => isset($p['ID']) ? (int) $p['ID'] : null,
                'odeme_tipi' => isset($p['OdemeTipi']) ? (int) $p['OdemeTipi'] : null,
                'odeme_siparis_id' => isset($p['OdemeSiparisID']) ? (string) $p['OdemeSiparisID'] : null,
                'pos_referans_id' => isset($p['PosReferansID']) ? (string) $p['PosReferansID'] : null,
                'onaylandi' => isset($p['Onaylandi']) ? (int) $p['Onaylandi'] : null,
                // KAPIDA ÖDEME ayracı: kapıda ödeme hizmet bedeli (canlı doğrulama 2026-09-01 —
                // OdemeTipi 2 (kapıda nakit) / 3 (kapıda kart) satırlarında 100, kartlı ödemede 0).
                // CodDetector bu alanı okur; kapıda ödemede karta iade edilecek işlem YOKTUR.
                'kapida_odeme_tutari' => isset($p['KapidaOdemeTutari']) ? round((float) $p['KapidaOdemeTutari'], 2) : 0.0,
                'tutar' => isset($p['Tutar']) ? round((float) $p['Tutar'], 2) : null,
                'para_birimi' => isset($p['ParaBirimi']) ? (string) $p['ParaBirimi'] : null,
                'ek_bilgi' => is_array($p['EkBilgi'] ?? null) ? $p['EkBilgi'] : null,
            ];
        }
        if (! $payments) {
            return ['success' => false, 'error' => 'no_payment'];
        }

        $payments = self::rankPaymentsBySuccess($payments);

        return ['success' => true, 'payment' => $payments[0], 'payments' => $payments];
    }

    /**
     * Ödeme satırlarını başarı olasılığına göre sıralar (en olası başarılı → [0]).
     *
     * ⛔ Onaylandi TEK BAŞINA GÜVENİLMEZ: başarısız denemede 2 (canlı kanıt SiparisID 642295,
     * PosRefID="Ödeme Başarısız...") ve terk edilmiş denemede 5 gelebilir (canlı kanıt SiparisID
     * 644125 — PosRefID BOŞ + Onaylandi=5 iki terk satırı, "büyük Onaylandi önce" kuralıyla gerçek
     * başarının [Onaylandi=1] önüne geçip PayTR'ye yanlış merchant_oid gönderdi, err 005). Her iki
     * canlı vakada da GERÇEK başarı Onaylandi=1. Sıra:
     *   1. PosReferansID hata metni olan satırlar ("başarısız deneme") EN SONA,
     *   2. Tutar<=0 satırlar (iade/düzeltme kayıtları, örn. panelden karta iade -794.45) EN SONA,
     *   3. PosReferansID numerik (iyzico onaylı paymentId — kesin başarı) EN ÖNE,
     *   4. Onaylandi=1 satırlar diğer değerlerden (2=red, 5=terk) ÖNCE,
     *   5. eşitse sonraki deneme (id büyük) önce.
     */
    public static function rankPaymentsBySuccess(array $payments): array
    {
        $rank = function (array $p): array {
            $posRef = trim((string) ($p['pos_referans_id'] ?? ''));
            $isFailureText = $posRef !== '' && ! ctype_digit($posRef);
            $isConfirmed = $posRef !== '' && ctype_digit($posRef);
            $isNonPositive = array_key_exists('tutar', $p) && (float) $p['tutar'] <= 0;

            return [
                $isFailureText ? 1 : 0,
                $isNonPositive ? 1 : 0,
                $isConfirmed ? 0 : 1,
                ((int) ($p['onaylandi'] ?? 0)) === 1 ? 0 : 1,
                -(int) ($p['id'] ?? 0),
            ];
        };
        usort($payments, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $payments;
    }

    // ── Member Methods (UyeServis.svc) ──

    /**
     * Get members with pagination.
     * WSDL: SelectUyeler(UyeKodu, f: UyeFiltre, s: UyeSayfalama)
     */
    public function getMembers(array $filters = [], int $page = 1, int $pageSize = 100): array
    {
        // All int fields MUST be -1 (sentinel for "no filter").
        // WCF defaults int to 0, which acts as a real filter value.
        $params = [
            'UyeKodu' => $this->authCode,
            'filtre' => array_merge([
                'Aktif' => -1,
                'AlisverisYapti' => -1,
                'Cinsiyet' => -1,
                'IlID' => -1,
                'IlceID' => -1,
                'MailIzin' => -1,
                'SmsIzin' => -1,
                'UyeID' => -1,
            ], $filters),
            'sayfalama' => [
                'SayfaNo' => $page,
                'KayitSayisi' => $pageSize,
                'SiralamaDegeri' => 'ID',
                'SiralamaYonu' => 'ASC',
            ],
        ];

        return $this->callMethod('SelectUyeler', $params, 'member');
    }

    // ── Gift Voucher / Discount Coupon Methods (SiparisServis.svc) ──
    // Ticimax "Hediye Çeki" = tek kullanımlık indirim kuponu. Çark modülü her
    // çevirme için BENZERSİZ bir kod üretip burada Ticimax'ta gerçek kupon açar;
    // SelectHediyeCeki ile kullanım takibi (kim/ne kadar kullandı) yapılır.

    /**
     * Ticimax'ta yeni bir hediye çeki (indirim kuponu) oluşturur.
     * WSDL: HediyeCekiOlustur(UyeKodu, request: WebHediyeCekiOlusturRequest)
     * Dönüş: WebHediyeCekiOlusturResponse { HediyeCekiId, HediyeCekiKodu }
     *
     * @param array $request WebHediyeCekiOlusturRequest alanları (Kod, IndirimTipi,
     *                       IndirimDegeri, MinimumUrunTutari, TarihBaslagic/Bitis, ...)
     * @return array{success:bool, id?:int, code?:string, error?:string}
     */
    public function createGiftVoucher(array $request): array
    {
        $params = [
            'UyeKodu' => $this->authCode,
            'request' => $request,
        ];

        $res = $this->callMethod('HediyeCekiOlustur', $params, 'order');

        if (! ($res['success'] ?? false)) {
            return ['success' => false, 'error' => $res['error'] ?? 'Bilinmeyen hata'];
        }

        $data = $res['data']['HediyeCekiOlusturResult'] ?? [];
        $id = (int) ($data['HediyeCekiId'] ?? 0);
        $code = (string) ($data['HediyeCekiKodu'] ?? '');

        // Ticimax hata yerine 0 / boş kod döndürebilir — başarı sayma.
        if ($id <= 0 || $code === '') {
            return ['success' => false, 'error' => 'Hediye çeki oluşturulamadı (id/kod boş döndü)'];
        }

        return ['success' => true, 'id' => $id, 'code' => $code];
    }

    /**
     * Bir hediye çekinin güncel durumunu Ticimax'tan okur (kullanım takibi/rapor).
     * WSDL: SelectHediyeCeki(memberCode, giftVoucherCode)
     * Dönüş alanları: Quantity (toplam kullanım hakkı), RemainingUse (kalan),
     *   DiscountValue, MinProductAmount, StartDate, EndDate, CreatedDate ...
     *
     * @return array{success:bool, voucher?:array, error?:string}
     */
    public function getGiftVoucher(string $giftVoucherCode, string $memberCode = ''): array
    {
        // NOT: SelectHediyeCeki'nin `memberCode` alanı API yetki anahtarını (UyeKodu) bekler;
        // boş bırakılırsa "YetkiBulunamadi" döner. Çağıran ayrıca üye kodu verirse o kullanılır.
        $params = [
            'memberCode' => $memberCode !== '' ? $memberCode : $this->authCode,
            'giftVoucherCode' => $giftVoucherCode,
        ];

        $res = $this->callMethod('SelectHediyeCeki', $params, 'order');

        if (! ($res['success'] ?? false)) {
            return ['success' => false, 'error' => $res['error'] ?? 'Bilinmeyen hata'];
        }

        $v = $res['data']['SelectHediyeCekiResult'] ?? [];

        if (! is_array($v) || empty($v['Code'])) {
            return ['success' => false, 'error' => 'Hediye çeki bulunamadı'];
        }

        return ['success' => true, 'voucher' => $v];
    }

    /**
     * Hediye çekini "kullanıldı" işaretler (kalan kullanım hakkını tüketir) — test
     * kuponlarını pasifleştirmek için (Ticimax'ta silme API'si yok).
     * WSDL: SetHediyeCekiKullanildi(UyeKodu, request: { HediyeCekiId })
     *
     * @return array{success:bool, error?:string}
     */
    public function markGiftVoucherUsed(int $hediyeCekiId): array
    {
        $res = $this->callMethod('SetHediyeCekiKullanildi', [
            'UyeKodu' => $this->authCode,
            'request' => ['HediyeCekiId' => $hediyeCekiId],
        ], 'order');

        return ['success' => (bool) ($res['success'] ?? false), 'error' => $res['error'] ?? null];
    }

    /**
     * Sipariş durumunu değiştirir (iptal akışı — Faz 5 Ticimax ayağı).
     * WSDL: SetSiparisDurum(UyeKodu, request: SetSiparisDurumRequest{ Durum(WebSiparisDurumlari),
     *   SiparisID, SiparisNo, MailBilgilendir, PreventStockOperation, KargoTakipNo, KargoTakipLink }).
     * `Durum` string enum: Iptal, Iade, TeslimOncesiIptal, IptalTalebi, KismiIadeTalebi, ... (xsd doğrulandı).
     *
     * ⛔ CANLI YAZMA: gerçek siparişi günceller + (MailBilgilendir=true ise) müşteriye mail gider.
     * dry-run kararı çağıran katmanda (ReturnController) verilir; bu metot saf SOAP çağrısıdır.
     *
     * @return array{success:bool, error:?string, data?:array}
     */
    public function setOrderStatus(int $siparisId, string $durum, bool $mailBilgilendir = false, string $siparisNo = '', ?string $kargoTakipNo = null): array
    {
        $request = [
            'Durum' => $durum,
            'SiparisID' => $siparisId,
            'SiparisNo' => $siparisNo,
            'MailBilgilendir' => $mailBilgilendir,
            'PreventStockOperation' => false,   // stok iade edilsin (iptal → ürün stoğa döner)
        ];
        // Kargoya Verildi'ye geçerken takip no (CRM › Gecikmeler)
        if ($kargoTakipNo !== null && $kargoTakipNo !== '') {
            $request['KargoTakipNo'] = $kargoTakipNo;
        }

        $res = $this->callMethod('SetSiparisDurum', [
            'UyeKodu' => $this->authCode,
            'request' => $request,
        ], 'order');

        return [
            'success' => (bool) ($res['success'] ?? false),
            'error' => $res['error'] ?? null,
            'data' => $res['data'] ?? null,
        ];
    }

    /**
     * Sipariş kalemlerini KALEM-BAZLI iptal/iade eder (kısmi iptal — Faz 5).
     * WSDL: SiparisUrunIptalIade(UyeKodu, request: SiparisUrunIptalIadeRequest{ SiparisId, TumuIptal,
     *   SiparisUrunListe: ArrayOfIptalIadeSiparisUrun{ IptalIadeSiparisUrun[]{ SiparisUrunID, Adet,
     *   Islem, DurumID, IadeNedenID } }, KartaIade, KargoTutariIade, IadeTutari }).
     *
     * ⚠ İPTAL için Islem/DurumID/IadeNedenID kodları tenant'ın Ticimax tanımına bağlı (bu tenant'ta
     * kalem 'İptal' durumu henüz TANIMLI DEĞİL) → kodlar çağırana parametre verilir; eksik/0 ise çağıran
     * katman canlı çağrıyı yapmamalıdır. KartaIade=false (para iadesi Faz 4'te ayrı yürür).
     *
     * @param array<int,array{SiparisUrunID:int, Adet:float}> $items
     * @return array{success:bool, error:?string, data?:array}
     */
    public function cancelOrderItems(int $siparisId, array $items, int $islem, int $durumId, int $iadeNedenId = 0): array
    {
        $list = [];
        foreach ($items as $it) {
            $list[] = [
                'SiparisUrunID' => (int) $it['SiparisUrunID'],
                'Adet' => (float) $it['Adet'],
                'Islem' => $islem,
                'DurumID' => $durumId,
                'IadeNedenID' => $iadeNedenId,
            ];
        }

        $res = $this->callMethod('SiparisUrunIptalIade', [
            'UyeKodu' => $this->authCode,
            'request' => [
                'SiparisId' => $siparisId,
                'TumuIptal' => false,
                'KartaIade' => false,         // para iadesi Faz 4'te ayrı
                'KargoTutariIade' => false,
                'SiparisUrunListe' => ['IptalIadeSiparisUrun' => $list],
            ],
        ], 'order');

        return [
            'success' => (bool) ($res['success'] ?? false),
            'error' => $res['error'] ?? null,
            'data' => $res['data'] ?? null,
        ];
    }

    /**
     * TEK sipariş kalemine (ürün kırılımı) statü yazar — Ticimax panelde "Sipariş Ürün Durumları".
     * WSDL: SetSiparisUrunDurum(UyeKodu, request: SetSiparisUrunDurumRequest{ SiparisID, SiparisUrunID,
     *   Adet, DurumID, Islem, IadeNedenID, KartaIadeYap, KargoTutariIade, BankaKomisyonuIade, MailGonder }).
     *
     * DurumID/Islem kodları tenant'ın SelectSiparisUrunDurumlari tanımından gelir (çağıran verir).
     * ⚠ Para alanları DAİMA kapalı: KartaIadeYap/KargoTutariIade/BankaKomisyonuIade=false —
     * para iadesi Yunbi RefundService'te yürür, Ticimax'a tetikletilmez. MailGonder=false.
     *
     * @return array{success:bool, error:?string, data?:array}
     */
    public function setOrderItemStatus(int $siparisId, int $siparisUrunId, float $adet, int $durumId, int $islem, int $iadeNedenId = 0): array
    {
        $res = $this->callMethod('SetSiparisUrunDurum', [
            'UyeKodu' => $this->authCode,
            'request' => [
                'SiparisID' => $siparisId,
                'SiparisUrunID' => $siparisUrunId,
                'Adet' => $adet,
                'DurumID' => $durumId,
                'Islem' => $islem,
                'IadeNedenID' => $iadeNedenId,
                'KartaIadeYap' => false,
                'KargoTutariIade' => false,
                'BankaKomisyonuIade' => false,
                'MailGonder' => false,
            ],
        ], 'order');

        return [
            'success' => (bool) ($res['success'] ?? false),
            'error' => $res['error'] ?? null,
            'data' => $res['data'] ?? null,
        ];
    }

    /**
     * General SOAP call wrapper with error handling and logging.
     *
     * @param string $service Service type: 'custom', 'product', 'order', 'member'
     */
    /**
     * Reset cached SOAP clients to free memory.
     */
    public function resetClients(): void
    {
        $this->client = null;
        $this->clients = [];
    }

    protected function callMethod(string $method, array $params, string $service = 'custom'): array
    {
        // Periodically reset SOAP clients to prevent memory leaks in long-running processes
        $this->callCount++;
        if ($this->callCount % 5000 === 0) {
            $this->resetClients();
            gc_collect_cycles();
        }

        try {
            $client = $this->getClient($service);

            if (! $client) {
                return ['success' => false, 'error' => 'SOAP client unavailable'];
            }

            $oldTimeout = ini_get('default_socket_timeout');
            ini_set('default_socket_timeout', 120);
            $response = $client->__soapCall($method, [$params]);
            ini_set('default_socket_timeout', $oldTimeout);

            // SOAP responses are typically stdClass objects — convert to array
            $result = json_decode(json_encode($response), true) ?? [];

            // Check for Ticimax API-level errors (IsError field in response)
            $resultKey = $method . 'Result';
            $inner = $result[$resultKey] ?? reset($result) ?: [];
            if (is_array($inner) && ! empty($inner['IsError'])) {
                $errorMsg = $inner['ErrorMessage'] ?? 'Unknown API error';
                Log::error("TicimaxService: API error on {$method}", ['error' => $errorMsg]);

                return ['success' => false, 'error' => $errorMsg];
            }

            return ['success' => true, 'data' => $result];
        } catch (SoapFault $e) {
            Log::error("TicimaxService: SOAP fault on {$method}", [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Exception $e) {
            Log::error("TicimaxService: Exception on {$method}", [
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lazy-initialize the SOAP client for the given service.
     */
    protected function getClient(string $service = 'custom'): ?SoapClient
    {
        // Backward compat: default 'custom' service uses $this->client
        if ($service === 'custom' && $this->client) {
            return $this->client;
        }

        if (isset($this->clients[$service])) {
            return $this->clients[$service];
        }

        try {
            $wsdl = $this->getWsdlForService($service);

            $client = new SoapClient($wsdl, [
                'trace' => app()->environment('local'),
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_DISK,
                'connection_timeout' => 30,
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                    'http' => [
                        'timeout' => 120,
                    ],
                ]),
            ]);

            $this->clients[$service] = $client;

            if ($service === 'custom') {
                $this->client = $client;
            }

            return $client;
        } catch (\Exception $e) {
            Log::error('TicimaxService: Failed to create SOAP client', [
                'service' => $service,
                'wsdl' => $this->getWsdlForService($service),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
