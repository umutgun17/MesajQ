<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\TicimaxService;
use App\Support\TurkishText;
use App\Support\CargoTracking;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncCrmOrders extends Command
{
    protected $signature = 'crm:sync-orders
                            {--limit=200 : Sayfa basi kayit sayisi}
                            {--pages=0 : Max sayfa (0=hepsi)}
                            {--channel= : Belirli kanal ID}
                            {--full : Tum siparisleri bastan cek}
                            {--from= : Siparis tarihi baslangic (Y-m-d)}
                            {--to= : Siparis tarihi bitis (Y-m-d)}';

    protected $description = 'E-ticaret platformlarindan siparisleri senkronize eder';

    protected int $totalNew = 0;
    protected int $totalUpdated = 0;
    protected int $totalItems = 0;

    protected int $tenantId = 0;
    protected int $channelId = 0;

    /** Bu koşuda siparişi değişen müşteriler — toplamlar yalnız bunlar için yeniden hesaplanır. */
    protected array $touchedCustomerIds = [];

    public function handle(): int
    {
        $query = Channel::withoutGlobalScopes()
            ->where('is_active', true)
            ->where('type', 'like', '%-ecommerce');

        if ($channelId = $this->option('channel')) {
            $query->where('id', (int) $channelId);
        }

        $channels = $query->get();

        if ($channels->isEmpty()) {
            $this->error('Aktif e-ticaret kanali bulunamadi.');

            return 1;
        }

        foreach ($channels as $channel) {
            $this->info("Kanal #{$channel->id} ({$channel->type}, Tenant #{$channel->tenant_id}) isleniyor...");

            $this->syncOrders($channel);
        }

        $this->info("Tamamlandi: {$this->totalNew} yeni, {$this->totalUpdated} guncellendi, {$this->totalItems} kalem");
        Log::info('SyncCrmOrders: completed', [
            'new' => $this->totalNew,
            'updated' => $this->totalUpdated,
            'items' => $this->totalItems,
        ]);

        return 0;
    }

    protected function syncOrders(Channel $channel): void
    {
        if (! str_starts_with($channel->type, 'ticimax')) {
            $this->warn("  Kanal #{$channel->id}: Desteklenmeyen platform tipi ({$channel->type})");

            return;
        }

        $service = TicimaxService::fromChannel($channel);

        if (! $service->isAvailable()) {
            $this->warn("  Kanal #{$channel->id}: Ticimax servisi kullanilamiyor");

            return;
        }

        $this->tenantId = $channel->tenant_id;
        $this->channelId = $channel->id;

        $limit = (int) $this->option('limit');
        $maxPages = (int) $this->option('pages');
        $isFull = $this->option('full');

        $filters = [];

        // Date range filter
        if ($from = $this->option('from')) {
            $filters['SiparisTarihiBas'] = $from . 'T00:00:00';
        }
        if ($to = $this->option('to')) {
            $filters['SiparisTarihiSon'] = $to . 'T23:59:59';
        }

        // Incremental sync: only fetch orders updated since last sync
        $settings = $channel->settings ?? [];
        $lastSync = $settings['last_order_sync_at'] ?? null;

        if (! $isFull && ! $from && $lastSync) {
            $filters['DuzenlemeTarihiBas'] = $lastSync;
        }

        $syncStartedAt = now()->format('Y-m-d\TH:i:s');
        $page = 1;
        $processed = 0;

        while (true) {
            $result = $service->getOrders($filters, $page, $limit);

            if (! $result['success']) {
                $this->error("  Sayfa {$page}: API hatasi — " . ($result['error'] ?? 'Bilinmeyen hata'));
                break;
            }

            $orders = $result['data']['SelectSiparisResult']['WebSiparis'] ?? [];

            // Single result comes as associative array, not list
            if (! empty($orders) && isset($orders['ID'])) {
                $orders = [$orders];
            }

            if (empty($orders)) {
                if ($page === 1) {
                    $this->info('  Hic siparis bulunamadi.');
                }
                break;
            }

            foreach ($orders as $orderData) {
                $this->syncOrder($orderData, $channel);
                $processed++;
            }

            $this->info("  Sayfa {$page}: " . count($orders) . " siparis islendi (toplam: {$processed})");

            $page++;

            if ($maxPages > 0 && ($page - 1) >= $maxPages) {
                break;
            }

            if (count($orders) < $limit) {
                break;
            }

            usleep(300000); // 0.3s rate limit
        }

        // Update last sync timestamp (separate from last_synced_at which is for products)
        $settings['last_order_sync_at'] = $syncStartedAt;
        $channel->update(['settings' => $settings]);

        // Recalculate customer totals after sync
        if ($processed > 0) {
            $this->recalculateCustomerTotals($channel);
        }
    }

    protected function syncOrder(array $data, Channel $channel): void
    {
        $platformId = (string) ($data['ID'] ?? '');
        if (empty($platformId) || $platformId === '0') {
            return;
        }

        $platformCustomerId = (string) ($data['UyeID'] ?? '');
        $customerId = null;
        if ($platformCustomerId && $platformCustomerId !== '0') {
            $customerId = Customer::withoutGlobalScopes()
                ->where('tenant_id', $channel->tenant_id)
                ->where('platform_customer_id', $platformCustomerId)
                ->value('id');
        }

        // Parse addresses
        $billingAddress = $this->parseAddress($data['FaturaAdresi'] ?? null);
        $shippingAddress = $this->parseAddress($data['TeslimatAdresi'] ?? null);

        // Parse items (may be empty if UrunGetir=false)
        $items = $this->extractItems($data);
        $hasItems = ! empty($items);

        $mapped = [
            'platform_order_id' => $platformId,
            'platform_customer_id' => $platformCustomerId ?: null,
            'customer_id' => $customerId,
            'order_no' => trim($data['SiparisNo'] ?? '') ?: null,
            'order_code' => trim($data['SiparisKodu'] ?? '') ?: null,
            'status' => (int) ($data['Durum'] ?? 0),
            'status_label' => trim($data['SiparisDurumu'] ?? '') ?: null,
            'subtotal' => (float) ($data['Tutar'] ?? 0),
            'discount_amount' => (float) ($data['IndirimTutari'] ?? 0),
            'shipping_cost' => (float) ($data['KargoTutari'] ?? 0),
            'gift_card_amount' => (float) ($data['HediyeCekiTutari'] ?? 0),
            // Kullanılan hediye çeki / kupon kodu — çark kupon-sipariş eşleşmesi için.
            'coupon_code' => trim((string) ($data['HediyeCeki'] ?? '')) ?: null,
            'points_discount' => (float) ($data['PuanIndirimi'] ?? 0),
            'tax_amount' => (float) ($data['ToplamKdv'] ?? 0),
            'total_amount' => (float) ($data['SiparisToplamTutari'] ?? 0),
            'paid_amount' => (float) ($data['OdenenTutar'] ?? 0),
            'currency' => trim($data['ParaBirimi'] ?? 'TRY') ?: 'TRY',
            'customer_name' => TurkishText::fix(trim($data['AdiSoyadi'] ?? '')) ?: null,
            'customer_email' => ! empty($data['Mail']) ? strtolower(trim($data['Mail'])) : null,
            'cargo_company' => trim($data['KargoFirmaTanim'] ?? '') ?: null,
            'cargo_tracking_no' => trim($data['KargoTakipNo'] ?? '') ?: null,
            'cargo_tracking_url' => $this->buildCargoUrl($data['KargoFirmaTanim'] ?? '', $data['KargoTakipNo'] ?? ''),
            'payment_type' => (int) ($data['Kaynak'] ?? 0),
            'payment_status' => (((float) ($data['OdenenTutar'] ?? 0)) > 0 || ((int) ($data['Durum'] ?? 0)) > 0) ? 1 : 0,
            'order_source' => trim($data['SiparisKaynagi'] ?? '') ?: null,
            'billing_address' => $billingAddress,
            'shipping_address' => $shippingAddress,
            'ip_address' => trim($data['IPAdresi'] ?? '') ?: null,
            'order_date' => $this->parseDate($data['SiparisTarihi'] ?? null),
            'status_updated_at' => $this->parseDate($data['DurumGuncellemeTarihi'] ?? null),
            'platform_updated_at' => $this->parseDate($data['DuzenlemeTarihi'] ?? null),
            'payments' => $this->parsePayments($data['Odemeler'] ?? []),
        ];

        // payments: OdemeGetir=false çağrılarda (tarih aralığı yeniden çekimi) boş gelir — mevcut
        // ödeme kaydını null ile ezme (vade farkı / kapıda ödeme kırılımı buradan okunur).
        if ($mapped['payments'] === null) {
            unset($mapped['payments']);
        }

        // items_count: sadece ürün verisi varsa güncelle (UrunGetir=false ise mevcut değeri koru)
        if ($hasItems) {
            $mapped['items_count'] = count($items);
        }

        $existing = Order::withoutGlobalScopes()
            ->where('tenant_id', $channel->tenant_id)
            ->where('channel_id', $channel->id)
            ->where('platform_order_id', $platformId)
            ->first();

        if ($existing?->customer_id) {
            $this->touchedCustomerIds[$existing->customer_id] = true;
        }
        if ($customerId) {
            $this->touchedCustomerIds[$customerId] = true;
        }

        // İndirim kırılımı (kupon yüzdesi, sepet kampanyası adı, vade farkı girdileri) — CRM sipariş
        // kartındaki "Toplam" açıklaması buradan okunur. Diğer metadata anahtarlarına dokunulmaz.
        $discounts = $this->extractDiscountMeta($data, $items);
        // Kampanya bölüşümü kalemlerden hesaplanır: ürünsüz çağrıda (UrunGetir=false) eskisini koru
        if (! $hasItems && ! empty($existing?->metadata['discounts']['campaign_split'])) {
            $discounts['campaign_split'] = $existing->metadata['discounts']['campaign_split'];
        }
        $mapped['metadata'] = array_merge(
            is_array($existing?->metadata) ? $existing->metadata : [],
            ['discounts' => $discounts]
        );

        if ($existing) {
            $existing->update($mapped);
            $order = $existing;
            $this->totalUpdated++;
        } else {
            $mapped['tenant_id'] = $channel->tenant_id;
            $mapped['channel_id'] = $channel->id;
            if (! $hasItems) {
                $mapped['items_count'] = 0;
            }
            $order = Order::withoutGlobalScopes()->create($mapped);
            $this->totalNew++;
        }

        // Sync order items: sadece ürün verisi varsa (UrunGetir=false ise mevcut kalemlere dokunma)
        if ($hasItems) {
            $this->syncOrderItems($order, $items, $channel);
        }
    }

    /**
     * Ticimax'ın sipariş başlığındaki indirim ayrıntıları. IndirimTutari (discount_amount) kupon +
     * kampanya toplamıdır; burada kırılımı ve kampanya adları saklanır (KampanyaGetir=true gerekir).
     */
    protected function extractDiscountMeta(array $data, array $items): array
    {
        $campaigns = $data['Kampanyalar']['WebSiparisKampanya'] ?? [];
        if (isset($campaigns['KampanyaID'])) {
            $campaigns = [$campaigns];
        }
        $names = [];
        foreach ((array) $campaigns as $c) {
            $name = TurkishText::fix(trim((string) ($c['KampanyaTanim'] ?? '')));
            if ($name !== '') {
                $names[] = ['id' => (int) ($c['KampanyaID'] ?? 0), 'name' => $name, 'type' => (int) ($c['KampanyaTipi'] ?? 0)];
            }
        }

        $lineCampaign = 0.0;
        $lineItem = 0.0;
        foreach ($items as $it) {
            $lineCampaign += (float) ($it['KampanyaIndirimTutari'] ?? 0);
            $lineItem += (float) ($it['KalemBazliIndirimTutari'] ?? 0);
        }

        return array_filter([
            'campaigns' => $names,
            'campaign_split' => $this->splitCampaignDiscount($names, $items),
            'cart_campaign' => round((float) ($data['SepetKampanyasiIndirimi'] ?? 0), 2),
            'line_campaign' => round($lineCampaign, 2),
            'line_item' => round($lineItem, 2),
            'payment_discount' => round((float) ($data['OdemeIndirimi'] ?? 0), 2),
            'coupon_percent' => round((float) ($data['HediyeCekiYuzdeIndirim'] ?? 0), 2),
            'coupon_source' => trim((string) ($data['HediyeCekiOlusturmaTipiTanim'] ?? '')) ?: null,
            'gift_wrap' => round((float) ($data['HediyePaketiTutari'] ?? 0), 2),
            'products_total' => round((float) ($data['ToplamTutar'] ?? 0), 2),
        ], fn ($v) => $v !== null && $v !== 0.0 && $v !== []);
    }

    /**
     * Kalem bazlı kampanya indirimini (KampanyaIndirimTutari + KampanyaIDList) kampanyalara böler:
     * sepet tutarı kademeleri (KampanyaTipi=1, ör. "2.500 TL üzerine ek %5") tek grup "tier",
     * ürün kampanyaları (ör. "1 Alana 1 Bedava", tip 2) kendi satırı.
     *
     * Ticimax X-al-Y-bedava'da EN UCUZ birimleri bedava sayar ama indirimi kalemlere eşit oranda
     * (%50) dağıtır; kademe indirimi de aynı kalemlere karışır. Bu yüzden ürün kampanyası payı
     * sipariş düzeyinde hesaplanır: kampanyadaki birimler liste fiyatına (SatisAniSatisFiyat+Kdv)
     * göre sıralanır, en ucuz floor(adet × oran) birim bedava; kalan kampanya indirimi kademe payıdır.
     * (697415: 1+1 3.299,85 + kademe 604,47 = 3.904,32 — Ticimax toplamıyla birebir.)
     *
     * @return list<array{kind:string, ids:list<int>, amount:float, items:int, ratio?:float, free?:int}>
     */
    protected function splitCampaignDiscount(array $campaigns, array $items): array
    {
        $byId = [];
        foreach ($campaigns as $c) {
            $byId[$c['id']] = $c;
        }

        $lines = [];
        foreach ($items as $it) {
            $ki = (float) ($it['KampanyaIndirimTutari'] ?? 0);
            if ($ki <= 0) {
                continue;
            }
            $ids = array_map('intval', (array) ($it['KampanyaIDList']['int'] ?? []));
            if (! $ids && ! empty($it['KampanyaID'])) {
                $ids = [(int) $it['KampanyaID']];
            }
            $tier = array_values(array_filter($ids, fn ($id) => (int) ($byId[$id]['type'] ?? 0) === 1));
            $lines[] = [
                'ki' => $ki,
                'qty' => max(1, (int) ($it['Adet'] ?? 1)),
                'unit' => (float) ($it['SatisAniSatisFiyat'] ?? 0) + (float) ($it['SatisAniSatisFiyatKdv'] ?? 0),
                'tier' => $tier,
                'product' => array_values(array_diff($ids, $tier)),
            ];
        }

        $groups = [];
        $tierIds = [];
        $tierItems = 0;
        $tierAmount = 0.0;
        $done = [];
        foreach ($lines as $i => $l) {
            $tierIds = array_merge($tierIds, $l['tier']);
            if ($l['tier']) {
                $tierItems += $l['qty'];
            }
            if (! $l['product']) {
                $tierAmount += $l['ki'];
                $done[$i] = true;
            }
        }

        // Ürün kampanyaları (tek ürün kampanyalı kalemler) — sipariş düzeyinde en ucuz birim bedava
        $byProduct = [];
        foreach ($lines as $i => $l) {
            if (count($l['product']) === 1) {
                $byProduct[$l['product'][0]][] = $i;
            }
        }
        foreach ($byProduct as $pid => $idx) {
            $ki = array_sum(array_map(fn ($i) => $lines[$i]['ki'], $idx));
            $units = [];
            foreach ($idx as $i) {
                $units = array_merge($units, array_fill(0, $lines[$i]['qty'], $lines[$i]['unit']));
                $done[$i] = true;
            }
            $hasTier = collect($idx)->contains(fn ($i) => $lines[$i]['tier'] !== []);
            $ratio = $this->freeRatio((string) ($byId[$pid]['name'] ?? ''));
            $amount = $ki;
            $free = null;
            if ($ratio !== null) {
                sort($units);
                $free = (int) floor(count($units) * $ratio + 1e-9);
                if ($hasTier && min($units) > 0) {
                    $amount = min($ki, array_sum(array_slice($units, 0, $free)));
                }
            }
            $tierAmount += $ki - $amount;
            $groups[] = array_filter(['kind' => 'campaign', 'ids' => [$pid], 'amount' => $amount, 'items' => count($units), 'ratio' => $ratio, 'free' => $free], fn ($v) => $v !== null);
        }

        // Birden fazla ürün kampanyalı kalemler ayrıştırılamaz
        $mixed = array_filter($lines, fn ($l, $i) => empty($done[$i]), ARRAY_FILTER_USE_BOTH);
        if ($mixed) {
            $groups[] = [
                'kind' => 'mixed',
                'ids' => array_values(array_unique(array_merge(...array_map(fn ($l) => array_merge($l['product'], $l['tier']), $mixed)))),
                'amount' => array_sum(array_column($mixed, 'ki')),
                'items' => array_sum(array_column($mixed, 'qty')),
            ];
        }
        if ($tierAmount >= 0.01) {
            $groups[] = ['kind' => 'tier', 'ids' => array_values(array_unique($tierIds)), 'amount' => $tierAmount, 'items' => $tierItems];
        }

        return array_values(array_map(function ($g) {
            $g['amount'] = round($g['amount'], 2);

            return $g;
        }, array_filter($groups, fn ($g) => $g['amount'] >= 0.01)));
    }

    /** "1 Alana 1 Bedava" → 0.5, "3 Al 2 Öde" → 1/3; bilinmeyen ad → null */
    protected function freeRatio(string $name): ?float
    {
        $n = mb_strtolower($name);
        if (preg_match('/(\d+)\s*(?:alana|al)\s*(\d+)\s*(?:bedava|hediye)/u', $n, $m) && ($m[1] + $m[2]) > 0) {
            return $m[2] / ($m[1] + $m[2]);
        }
        if (preg_match('/(\d+)\s*al\s*(\d+)\s*öde/u', $n, $m) && $m[1] > $m[2]) {
            return ($m[1] - $m[2]) / $m[1];
        }

        return null;
    }

    protected function extractItems(array $data): array
    {
        $items = $data['Urunler']['WebSiparisUrun'] ?? [];

        if (empty($items)) {
            return [];
        }

        // Single item comes as associative array
        if (isset($items['UrunID'])) {
            $items = [$items];
        }

        return $items;
    }

    protected function syncOrderItems(Order $order, array $items, Channel $channel): void
    {
        // Sil + yeniden yaz TEK işlemde ve sipariş satırı kilitli: new/refresh/safety
        // komutları aynı siparişi aynı anda yazarsa kalemler ikilenmesin, panelde
        // ara anda "ürün yok" görünmesin.
        DB::transaction(function () use ($order, $items) {
            Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->value('id');
            $order->items()->delete();
            $this->insertOrderItems($order, $items);
        });
    }

    protected function insertOrderItems(Order $order, array $items): void
    {
        foreach ($items as $itemData) {
            // Ticimax: UrunKartiID = product card ID, UrunID = variant-level product ID
            $platformProductId = (string) ($itemData['UrunKartiID'] ?? '');
            $platformVariantId = (string) ($itemData['UrunID'] ?? '');

            $productId = null;
            $variantId = null;

            if ($platformProductId && $platformProductId !== '0') {
                $productId = Product::withoutGlobalScopes()
                    ->where('channel_id', $this->channelId)
                    ->where('platform_product_id', $platformProductId)
                    ->value('id');
            }

            if ($platformVariantId && $platformVariantId !== '0' && $productId) {
                $variantId = ProductVariant::where('product_id', $productId)
                    ->where('platform_variant_id', $platformVariantId)
                    ->value('id');
            }

            // Build variant info from EkSecenekList
            $variantInfo = $this->buildVariantInfo($itemData);

            OrderItem::create([
                'tenant_id' => $this->tenantId,
                'order_id' => $order->id,
                'platform_product_id' => $platformProductId ?: null,
                'platform_variant_id' => $platformVariantId ?: null,
                'product_name' => trim($itemData['UrunAdi'] ?? '') ?: null,
                'image_url' => trim($itemData['ResimYolu'] ?? '') ?: null,
                'variant_info' => $variantInfo,
                'sku' => trim($itemData['StokKodu'] ?? '') ?: null,
                'barcode' => trim($itemData['Barkod'] ?? '') ?: null,
                'quantity' => (int) ($itemData['Adet'] ?? 1),
                'unit_price' => round((float) ($itemData['Tutar'] ?? 0) + (float) ($itemData['KdvTutari'] ?? 0), 2),
                'total_price' => round(((float) ($itemData['Tutar'] ?? 0) + (float) ($itemData['KdvTutari'] ?? 0)) * (int) ($itemData['Adet'] ?? 1), 2),
                'discount_amount' => (float) ($itemData['ToplamIndirim'] ?? 0),
                'tax_rate' => (float) ($itemData['KdvOrani'] ?? 0),
                'tax_amount' => round((float) ($itemData['KdvTutari'] ?? 0) * (int) ($itemData['Adet'] ?? 1), 2),
                'status' => (int) ($itemData['Durum'] ?? 0),
                'status_label' => trim($itemData['IslemAd'] ?? '') ?: (trim($itemData['DurumAd'] ?? '') ?: null),
                'product_id' => $productId,
                'product_variant_id' => $variantId,
            ]);

            $this->totalItems++;
        }
    }

    protected function buildVariantInfo(array $itemData): ?string
    {
        $options = $itemData['EkSecenekList']['WebSiparisUrunEkSecenekOzellik'] ?? [];

        if (empty($options)) {
            return null;
        }

        // Single option comes as associative array
        if (isset($options['Tanim'])) {
            $options = [$options];
        }

        $parts = array_map(fn($o) => trim($o['Tanim'] ?? ''), $options);
        $parts = array_filter($parts);

        return ! empty($parts) ? implode(' / ', $parts) : null;
    }

    protected function recalculateCustomerTotals(Channel $channel): void
    {
        // Yalnız bu koşuda siparişi değişen müşteriler (eskiden her 5 dk'da 150k+
        // müşterinin tamamı yeniden yazılıyordu). Tanım değişmedi: tüm siparişler.
        $ids = array_keys($this->touchedCustomerIds);
        $this->touchedCustomerIds = [];
        if ($ids === []) {
            return;
        }

        $affected = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $affected += DB::update("
                UPDATE customers c
                SET
                    total_orders = COALESCE(sub.order_count, 0),
                    total_spent = COALESCE(sub.total_spent, 0)
                FROM (
                    SELECT
                        cid.id AS customer_id,
                        COUNT(o.id) as order_count,
                        COALESCE(SUM(o.total_amount), 0) as total_spent
                    FROM unnest(ARRAY[{$in}]::bigint[]) AS cid(id)
                    LEFT JOIN orders o ON o.customer_id = cid.id AND o.tenant_id = ?
                    GROUP BY cid.id
                ) sub
                WHERE c.id = sub.customer_id
                  AND c.tenant_id = ?
                  AND (c.total_orders IS DISTINCT FROM COALESCE(sub.order_count, 0)
                       OR c.total_spent IS DISTINCT FROM COALESCE(sub.total_spent, 0))
            ", [$channel->tenant_id, $channel->tenant_id]);
        }

        $this->info("  Musteri toplamlari guncellendi: {$affected} kayit");
    }

    protected function parseAddress($address): ?array
    {
        if (empty($address) || ! is_array($address)) {
            return null;
        }

        // Name field: AliciAdi (shipping) or FirmaAdi (billing)
        $name = TurkishText::fix(trim($address['AliciAdi'] ?? $address['FirmaAdi'] ?? '')) ?: null;
        // Country is an object with Tanim
        $country = is_array($address['Ulke'] ?? null)
            ? trim($address['Ulke']['Tanim'] ?? '') ?: null
            : (trim($address['Ulke'] ?? '') ?: null);

        return [
            'name' => $name,
            'phone' => trim($address['AliciTelefon'] ?? '') ?: null,
            'city' => trim($address['Il'] ?? '') ?: null,
            'district' => trim($address['Ilce'] ?? '') ?: null,
            'neighborhood' => trim($address['Semt'] ?? $address['Mahalle'] ?? '') ?: null,
            'address' => trim($address['Adres'] ?? '') ?: null,
            'postal_code' => trim($address['PostaKodu'] ?? '') ?: null,
            'country' => $country,
            'tax_office' => trim($address['VergiDairesi'] ?? '') ?: null,
            'tax_no' => trim($address['VergiNo'] ?? '') ?: null,
        ];
    }

    /**
     * Takip linki TEK kaynaktan (App\Support\CargoTracking) kurulur.
     * 8 Eyl 2026: burada üretilen Sürat adresi (gonderi-takip?code=…) Sürat'ın
     * site yenilemesiyle 404'e döndü ve 184 bin siparişe yanlış link yazıldı.
     */
    protected function buildCargoUrl(string $company, string $trackingNo): ?string
    {
        // Takip numarası yoksa link YAZILMAZ: sipariş henüz kargoya çıkmamışken
        // panelde/SMS'te takip linki göstermek yanlış beklenti yaratır.
        if (trim($trackingNo) === '') {
            return null;
        }

        return CargoTracking::url($company, $trackingNo);
    }

    protected function parsePayments($odemeler): ?array
    {
        if (empty($odemeler)) {
            return null;
        }

        $payments = $odemeler['WebSiparisOdeme'] ?? [];

        if (empty($payments)) {
            return null;
        }

        // Single payment comes as associative array
        if (isset($payments['ID'])) {
            $payments = [$payments];
        }

        return array_map(function ($p) {
            $posRef = trim($p['PosReferansID'] ?? '');
            $codAmount = round((float) ($p['KapidaOdemeTutari'] ?? 0), 2);

            // Onaylandi=1 sadece işlemin yapıldığını gösterir, başarı değil.
            // Gerçek başarı: POS referans varsa veya kapıda ödeme tutarı > 0
            $approved = ! empty($posRef) || $codAmount > 0;

            return [
                'id' => (int) ($p['ID'] ?? 0),
                'type' => (int) ($p['OdemeTipi'] ?? 0),
                'amount' => round((float) ($p['Tutar'] ?? 0), 2),
                'currency' => trim($p['ParaBirimi'] ?? 'TRY'),
                'date' => $this->parseDate($p['Tarih'] ?? null),
                'approved' => $approved,
                'installments' => (int) ($p['TaksitSayisi'] ?? 0),
                'pos_reference' => $posRef ?: null,
                'order_reference' => trim($p['OdemeSiparisID'] ?? '') ?: null,
                'note' => trim($p['OdemeNotu'] ?? '') ?: null,
                'bank_commission' => round((float) ($p['BankaKomisyonu'] ?? 0), 2),
                'cod_amount' => $codAmount,
            ];
        }, $payments);
    }

    protected function parseDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            $parsed = Carbon::parse($date);

            // Ticimax returns "0001-01-01T00:00:00" for empty dates
            if ($parsed->year < 1900) {
                return null;
            }

            return $parsed->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
