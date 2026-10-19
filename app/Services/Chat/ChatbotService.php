<?php

namespace App\Services\Chat;

use App\Models\Channel;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\CargoTracking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Chatbot orkestrası — bkz docs/ai-chatbot.md.
 *
 * Akış: guardrail'ler → deterministik kısa devreler (temsilci isteği, sipariş
 * doğrulama, iletişim toplama) → LLM (JSON protokol) → aksiyon (ürün arama /
 * handoff / sipariş bilgisi isteme). Ürün kartlarının sunumu controller'dan
 * closure ile enjekte edilir (present() PublicSearchController'da yaşar).
 *
 * Güvenlik ilkeleri (mesaj-AI v3.3 dersleri): stok taahhüdü yok, CRM'siz durum
 * iddiası yok, sipariş bilgisi yalnız no+iletişim eşleşmesiyle, PII asla
 * doğrulamasız verilmez, enumeration'a karşı generic hata.
 */
class ChatbotService
{
    /**
     * Kargoya çıkmamış sipariş durumları (Ticimax kodları): alındı / paketleniyor
     * / hazırlanıyor. Bu durumlarda müşteriye kargo çıkış günü söylenir.
     * Ödemesi tamamlanmamış sipariş (3) BİLEREK DIŞARIDA — hazırlığa girmediği
     * için ona kargo çıkış günü söylemek yanlış beklenti yaratır.
     */
    protected const PRE_SHIPMENT_STATUSES = [0, 4, 5];

    /**
     * Müşterinin yazabileceği renk → KATALOGDAKİ karşılığı.
     *
     * Neden eşleme: müşteri "kahverengi" yazıyor, katalog "Kahve" diyor —
     * motor renk fasetini tam eşleştirdiği için sonuç 0 çıkıyor ve bot
     * "aradığınız beden ve renk stokta yok" gibi YANLIŞ bir cümle kuruyordu.
     * Uzun kalıplar önce denenir (kahverengi, kahve'den önce).
     * Kısa/yanıltıcı sözcükler (ör. "ten" → "tenis") bilerek listede DEĞİL.
     */
    protected const COLOR_MAP = [
        'kahverengi' => 'kahve', 'kahve' => 'kahve', 'brown' => 'kahve', 'taba' => 'taba',
        'siyah' => 'siyah', 'black' => 'siyah',
        'beyaz' => 'beyaz', 'white' => 'beyaz', 'ekru' => 'ekru', 'krem' => 'krem',
        'kırmızı' => 'kırmızı', 'kirmizi' => 'kırmızı', 'red' => 'kırmızı', 'bordo' => 'bordo',
        'lacivert' => 'lacivert', 'laci' => 'lacivert', 'navy' => 'lacivert',
        'mavi' => 'mavi', 'blue' => 'mavi', 'saks' => 'saks', 'sax' => 'sax',
        'yeşil' => 'yeşil', 'yesil' => 'yeşil', 'green' => 'yeşil', 'haki' => 'haki',
        'antrasit' => 'antrasit', 'füme' => 'füme', 'fume' => 'füme', 'gri' => 'gri',
        'bej' => 'bej', 'vizon' => 'vizon', 'nude' => 'nude', 'pudra' => 'pudra', 'somon' => 'somon',
        'hardal' => 'hardal', 'sarı' => 'sarı', 'sari' => 'sarı',
        'turuncu' => 'turuncu', 'oranj' => 'oranj',
        'fuşya' => 'fuşya', 'fusya' => 'fuşya', 'pembe' => 'pembe', 'lila' => 'lila', 'mor' => 'mor',
        'gümüş' => 'gümüş', 'gumus' => 'gümüş', 'altın' => 'altın', 'altin' => 'altın', 'gold' => 'altın',
    ];

    public function __construct(protected ChatLlm $llm)
    {
    }

    /**
     * Bir kullanıcı mesajını işler; widget'a dönecek yeni mesajları döndürür.
     *
     * @param  callable(string): array{products: array, search_id: int|null}  $presentProducts
     * @return array{messages: array<int, array>, status: string}
     */
    public function handle(Tenant $tenant, ChatSession $session, string $userMessage, callable $presentProducts): array
    {
        $settings = TenantChatSettings::get($tenant->id);
        $userMessage = mb_substr(trim($userMessage), 0, (int) config('chat.max_message_length', 1000));

        $this->store($session, 'user', $userMessage);
        $session->increment('user_message_count');
        $session->forceFill(['last_activity_at' => now(), 'last_seen_at' => now()])->save();

        // Handoff sonrası: LLM devre dışı, mesaj personelin konuşmasına akar
        if ($session->status === 'handoff' && $session->conversation_id) {
            $this->forwardToConversation($session, $userMessage);

            return $this->respond($session, $this->postHandoffAck($tenant, $session));
        }

        $ctx = $session->context ?? [];

        // Devir toplama adımındayken müşteri konuyu değiştirdiyse (vazgeçti ya da
        // yeni bir ürün soruyor) akışta hapsolmasın — 4 Eyl kaydında müşteri
        // "Başka şık ayakkabı bul" dedikçe bot iletişim bilgisi istiyordu.
        // İletişim bilgisi VERİLİYORSA akıştan çıkma — numaranın içindeki
        // rakamlar "soru" gibi görünse bile müşteri devri sürdürüyor demektir.
        $givingContact = $this->extractPhone($userMessage) !== null || $this->extractEmail($userMessage) !== null;

        if ((! empty($ctx['awaiting_detail']) || ! empty($ctx['awaiting_contact']))
            && ! $givingContact
            && $this->isEscalationAbort($userMessage, $tenant->id)) {
            unset($ctx['awaiting_detail'], $ctx['awaiting_contact'], $ctx['escalation_reason'], $ctx['contact_ask_count'], $ctx['pending_name']);
            $session->forceFill(['context' => $ctx])->save();
        }

        // Devir öncesi: önce sorunun detayı, sonra iletişim bilgisi
        if (! empty($ctx['awaiting_detail'])) {
            return $this->respond($session, $this->collectDetail($tenant, $session, $userMessage, $ctx));
        }

        if (! empty($ctx['awaiting_contact'])) {
            return $this->respond($session, $this->collectContactAndHandoff($tenant, $session, $userMessage, $ctx));
        }

        // Oturum freni
        if ($session->user_message_count > (int) config('chat.max_user_messages', 40)) {
            return $this->respond($session, $this->beginEscalation(
                $tenant, $session, 'mesaj_limiti',
                'Bu sohbet oldukça uzadı; size daha iyi yardımcı olabilmesi için müşteri temsilcimize aktarıyorum.'
            ));
        }

        // Deterministik: müşteri açıkça temsilci istiyor
        if (preg_match('/temsilci|müşteri hizmetleri|gerçek (biri|insan)|canlı destek|yetkili(ye|yle)? (bağla|görüş)/iu', $userMessage)) {
            return $this->respond($session, $this->beginEscalation($tenant, $session, 'musteri_istegi'));
        }

        // Deterministik: ürün sohbetinin devamı ("başka", "daha farklı", "beğenmedim").
        // Model bunları çoğu kez topic=diger sayıp temsilciye devrediyordu.
        // Ürün sohbetinde bütçe söylemek ("600 TL altı yok mu?") de takip
        // isteğidir; modele bırakıldığında turların üçte birinde topic=diger
        // deyip devrediyordu.
        $priceCeiling = $this->extractPriceCeiling($userMessage);

        // Müşterinin sohbet boyunca geçerli kalan tercihleri (beden/numara, renk).
        // Modelin her turda search_query'ye doğru bedeni yazacağına GÜVENİLMEZ;
        // kısıt hem prompt'a hem de motora YAPISAL FİLTRE olarak geçer.
        $prefsBefore = (array) ($ctx['prefs'] ?? []);
        $prefs = $this->capturePrefs($userMessage, $prefsBefore);
        if ($prefs !== $prefsBefore) {
            $ctx['prefs'] = $prefs;
            $session->forceFill(['context' => $ctx])->save();
        }

        // ── ÇOK SORULAN SORULAR (üstteki çipler + aynı sorunun müşteri yazımı).
        // Cevap LLM'e HİÇ gitmez: mağazanın onaylı metni birebir gönderilir ya
        // da akış (vitrin / sipariş sorgusu) devralır. Bkz ChatIntents — 5 Eyl'de
        // üç çipin üçü de yanlış/boş cevap üretebiliyordu.
        $intent = ChatIntents::detect($userMessage);
        if ($intent !== null) {
            $handled = $this->handleIntent(
                $tenant, $session, $intent, $userMessage, $ctx, $settings, $presentProducts, $priceCeiling
            );
            if ($handled !== null) {
                return $this->respond($session, $handled);
            }
        }

        // Deterministik: "en çok satan / ne önerirsin / ne alacağımı bilmiyorum".
        // Model bunu serbest aramaya çevirip sonuç bulamayınca "aradığınız
        // özellikte ürün bulamadım" diyordu — oysa istenen tam da vitrindeki
        // en çok tercih edilenler. Sıralama site listeleme sırasıyla aynı
        // sinyalden gelir (products.performance_score: satış+sepet+favori+görüntülenme).
        if ($settings['allow_products'] && $this->isBestSellerIntent($userMessage)) {
            [$reply, $extra] = $this->showProducts(
                $session, (string) ($ctx['last_product_query'] ?? ''), $presentProducts,
                '', ['topic' => 'urun', 'best_sellers' => true], $priceCeiling, true
            );

            return $this->respond($session, [$this->assistant($session, $reply, $extra)]);
        }

        // Deterministik: mesaj yalnız bir tercih bildiriyor ("siyah olanı var mı",
        // "44 numara") ve elimizde arama bağlamı var → listeyi yeni kısıtla tazele.
        // Model bu turlarda kimi zaman "hangi ürünü kastettiniz" diye soruyor ya da
        // "siyah seçenek görünmüyor" deyip aramayı hiç yapmıyordu.
        if ($settings['allow_products']
            && ! empty($ctx['last_product_query'])
            && $prefs !== $prefsBefore
            && $this->isPrefOnlyMessage($userMessage, $prefs)) {
            [$reply, $extra] = $this->showProducts(
                $session, (string) $ctx['last_product_query'], $presentProducts,
                '', ['topic' => 'urun', 'pref_refresh' => true], $priceCeiling, false, false
            );

            return $this->respond($session, [$this->assistant($session, $reply, $extra)]);
        }

        if ($settings['allow_products']
            && ! empty($ctx['last_product_query'])
            && ($this->isMoreProductsIntent($userMessage) || $priceCeiling !== null)) {
            [$reply, $extra] = $this->showProducts($session, (string) $ctx['last_product_query'], $presentProducts, '', ['topic' => 'urun', 'follow_up' => true], $priceCeiling);

            return $this->respond($session, [$this->assistant($session, $reply, $extra)]);
        }

        // Deterministik: ekrandaki kartlar hakkında fiyat/sıra sorusu ("en ucuzu
        // hangisi", "ikincisi kaç para"). Model bu soruyu 3 turdan 1'inde
        // topic=diger sayıp boşuna devrediyordu; cevap zaten elimizde, LLM'e
        // hiç gitmiyoruz (bedava + anında + uydurma riski sıfır).
        if ($settings['allow_products'] && ! empty($ctx['last_products'])) {
            $fromList = $this->answerFromShownProducts($userMessage, (array) $ctx['last_products']);
            if ($fromList !== null) {
                return $this->respond($session, [$this->assistant($session, $fromList, ['topic' => 'urun', 'from_shown_list' => true])]);
            }
        }

        // Deterministik: mesajda sipariş no + iletişim varsa doğrula ve cevapla
        if ($settings['allow_order_status']) {
            $orderReply = $this->tryOrderLookup($tenant, $session, $userMessage, $ctx);
            if ($orderReply !== null) {
                return $this->respond($session, $orderReply);
            }
        }

        // Onaylı metinlerden dayanak ara (grounding). Bulunamazsa bot mağaza
        // bilgisi içeren soruyu CEVAPLAMAZ — bkz ChatKnowledge.
        $grounding = ChatKnowledge::match($tenant->id, $userMessage);

        // Ekranda duran kartlar modele verilir; takip soruları ("en ucuzu
        // hangisi") artık cevaplanabiliyor. Bkz shownProductsBlock().
        $shownProducts = (array) ($ctx['last_products'] ?? []);

        $result = $this->llm->complete(
            $this->buildSystemPrompt($tenant, $settings, $grounding, $shownProducts, $prefs),
            $this->history($session),
            $tenant->id
        );

        if ($result === null) {
            // Bütçe/sağlayıcı sorunu — bot çökmez, insana devreder
            return $this->respond($session, $this->beginEscalation(
                $tenant, $session, 'saglayici_hatasi',
                'Şu anda yoğunluk yaşıyorum ve sorunuza sağlıklı yanıt veremiyorum.'
            ));
        }

        $json = $result['json'];
        $reply = trim((string) ($json['reply'] ?? ''));
        $action = (string) ($json['action'] ?? 'none');
        $topic = (string) ($json['topic'] ?? 'diger');
        $confidence = (float) ($json['confidence'] ?? 0.0);

        $decision = $this->gate($settings, $topic, $action, $confidence, $grounding, $reply);
        if ($decision !== null) {
            // Modelin kendi giriş cümlesini (özür/empati) devir mesajının başına
            // taşı — ama SAYI DENETİMİNDEN geçerse. Öfkeli müşteriye kuru bir
            // "aktarıyorum" yerine önce üzüntü belirtmek doğru davranış.
            $lead = $this->escalationLead($reply, $userMessage, $grounding);

            return $this->respond($session, $this->beginEscalation($tenant, $session, $decision, $lead));
        }

        $extra = [
            'confidence' => $confidence,
            'provider' => $result['provider'],
            'topic' => $topic,
            'sources' => $grounding['sources'] ?? [],
            'kb_score' => $grounding['score'] ?? null,
        ];

        if ($action === 'need_order_info' && $settings['allow_order_status']) {
            // Zaten doğrulanmış bir sipariş varsa bilgiyi TEKRAR istemek yerine
            // güncel durumu ver — model geçmişi okusa da bunu güvenilir yapmıyor.
            $known = $this->verifiedOrderStatus($tenant, $session, $session->context ?? []);
            if ($known !== null) {
                return $this->respond($session, $known);
            }

            $reply = $this->askOrderInfoText($session->context ?? []);
            // Sonraki mesajda yalnız telefon/e-posta gelirse "hangi sipariş" diye
            // sorabilmek için sipariş akışında olduğumuzu işaretle.
            $c = $session->context ?? [];
            $c['awaiting_order_info'] = 1;
            $session->forceFill(['context' => $c])->save();
        }

        if ($action === 'show_products' && $settings['allow_products']) {
            $query = trim((string) ($json['search_query'] ?? ''));
            [$reply, $extra] = $this->showProducts($session, $query, $presentProducts, $reply, $extra, $this->extractPriceCeiling($userMessage));
        }

        if ($reply === '') {
            return $this->respond($session, $this->beginEscalation($tenant, $session, 'bos_cevap'));
        }

        // Son doğruluk kapısı: cevaptaki sayısal iddialar dayanağa uymalı
        if (! $this->numbersGrounded($reply, $userMessage, $grounding, $extra['products'] ?? $shownProducts)) {
            Log::warning('ChatbotService: ungrounded number in reply, escalating', [
                'session' => $session->id,
                'reply' => $reply,
            ]);

            return $this->respond($session, $this->beginEscalation($tenant, $session, 'sayi_dogrulanamadi'));
        }

        return $this->respond($session, [$this->assistant($session, $reply, $extra)]);
    }

    /**
     * Ürün kartlarını hazırlar ve cevabı kurar. Hem LLM'in show_products aksiyonu
     * hem de deterministik "başka var mı" kısa devresi buradan geçer.
     *
     * Oturum belleği: gösterilen ürün id'leri `shown_product_ids`'te birikir ve
     * bir sonraki aramada HARİÇ tutulur — 4 Eyl konuşmalarında müşteri "başka",
     * "daha şık" dedikçe bot aynı 4 kartı tekrar tekrar gösteriyordu.
     *
     * @param  callable(string, array): array  $presentProducts
     * @param  array<string, mixed>  $extra
     * @return array{0: string, 1: array<string, mixed>}
     */
    /**
     * Çok sorulan bir sorunun deterministik cevabı.
     *
     * null dönerse "bu soruyu ben daha iyi cevaplayamam" demektir ve normal akış
     * (LLM + doğruluk kapıları + devir) devam eder — niyet yakalandı diye
     * müşteriye zorla hazır cevap verilmez.
     *
     * @param  callable(string, array, array): array  $presentProducts
     * @return array<int, array>|null
     */
    protected function handleIntent(Tenant $tenant, ChatSession $session, string $intent, string $userMessage, array $ctx, array $settings, callable $presentProducts, ?float $priceCeiling): ?array
    {
        $action = ChatIntents::CATALOG[$intent]['action'] ?? 'faq';

        // ── "Ürün önerisi istiyorum" (tarifsiz istek)
        if ($action === 'products') {
            // Müşteri ne aradığını TARİF ETTİYSE ("44 numara gri spor ayakkabı
            // önerir misiniz") vitrin yanlış cevap olur — aramayı normal akış yapar.
            if (! $settings['allow_products'] || ! ChatIntents::isBareAsk($userMessage, $intent)) {
                return null;
            }

            [$reply, $extra] = $this->showProducts(
                $session, (string) ($ctx['last_product_query'] ?? ''), $presentProducts,
                '', ['topic' => 'urun', 'intent' => $intent, 'best_sellers' => true], $priceCeiling, true
            );

            // "Bulamadım" demek yerine en çok tercih edilenleri göster ve TEK
            // soruyla daralt — müşteri henüz hiçbir kriter söylemedi.
            if (! empty($extra['products'])) {
                $reply = trim($reply) . "\n\nNumara, renk ya da bütçe söylerseniz size göre daraltayım.";
            }

            return [$this->assistant($session, $reply, $extra)];
        }

        // ── "Siparişim nerede?"
        if ($action === 'order') {
            if (! $settings['allow_order_status']) {
                return null;
            }

            // Mesajda sipariş no varsa doğrudan sorgula ("TB123 siparişim nerede")
            $lookup = $this->tryOrderLookup($tenant, $session, $userMessage, $ctx);
            if ($lookup !== null) {
                return $lookup;
            }

            // Bu sohbette zaten doğrulanmış bir sipariş varsa tekrar sorma
            $known = $this->verifiedOrderStatus($tenant, $session, $ctx);
            if ($known !== null) {
                return $known;
            }

            $c = $session->context ?? [];
            $c['awaiting_order_info'] = 1;
            $session->forceFill(['context' => $c])->save();

            return [$this->assistant($session, $this->askOrderInfoText($c), [
                'topic' => 'siparis',
                'intent' => $intent,
                'confidence' => 1.0,
            ])];
        }

        // ── SSS: mağazanın onaylı metni birebir
        if (! $settings['allow_faq']) {
            return null;
        }

        $answer = ChatIntents::faqAnswer($tenant->id, $intent);
        if ($answer === null) {
            return null; // bu tenant'ta onaylı kayıt yok → normal akış
        }

        return [$this->assistant($session, $answer['text'], [
            'topic' => 'sss',
            'intent' => $intent,
            'confidence' => 1.0,
            'sources' => [$answer['source']],
            'kb_score' => 1.0,
            // Takip çipleri: müşterinin sıradaki sorusu da genelde elimizde
            'quick_replies' => ChatIntents::followUps($intent, (array) $settings['quick_replies']),
        ])];
    }

    protected function showProducts(ChatSession $session, string $query, callable $presentProducts, string $reply, array $extra, ?float $priceMax = null, bool $bestSellers = false, bool $excludeShown = true): array
    {
        $ctx = $session->context ?? [];
        // Gösterilmiş ürünler yalnız "başka var mı" akışında elenir. Müşteri
        // kısıtı DARALTIYORSA ("kahverengi olsun") eleme yanlış olur: aradığı
        // ürün zaten ekranda duruyor olabilir ve motor onu bulamayınca bot
        // "aradığınız beden ve renk stokta yok" gibi YANLIŞ bir cümle kuruyordu.
        $shown = $excludeShown
            ? array_values(array_unique(array_map('intval', (array) ($ctx['shown_product_ids'] ?? []))))
            : [];

        // Tavan fiyat sohbet boyunca yapışkandır: müşteri bir kez "600 TL altı"
        // dedikten sonra "başka var mı" dediğinde de bütçesi geçerlidir.
        $priceMax ??= isset($ctx['price_max']) ? (float) $ctx['price_max'] : null;

        if ($priceMax !== null) {
            $query = $this->stripPricePhrases($query) ?: $query;
        }

        // Motora geçen YAPISAL kısıtlar: sohbet boyunca biriken tercihler +
        // bütçe + (istendiyse) en-çok-satan modu. Sorgu metni modelden gelir,
        // kısıtlar buradan — model bedeni yazmayı unutsa da filtre uygulanır.
        $filters = (array) ($ctx['prefs'] ?? []);
        if ($priceMax !== null) {
            $filters['price_max'] = $priceMax;
        }
        if ($bestSellers) {
            $filters['best_sellers'] = true;
        }

        // Renk sorgu METNİNE işlenir (yapısal filtre değil): katalog renkleri
        // bileşik olduğu için tam eşleşme filtresi "Siyah Beyaz"ı kaçırırdı.
        $query = $this->applyColorPref($query, $filters['renk'] ?? null);
        unset($filters['renk']);

        // Beden filtre olarak gidiyor; sorgu metnindeki ESKİ beden ("38 numara")
        // kalırsa hem lexical skoru kirletir hem de kayıtta kafa karıştırır.
        if (! empty($filters['beden'])) {
            $query = trim((string) preg_replace(
                '/\b(1[6-9]|[2-4][0-9]|5[0-4])\s*(?:numara|beden|no)\b|\b(?:numara|beden|no)[a-zçğıöşü]{0,4}\s*[:\s]\s*(1[6-9]|[2-4][0-9]|5[0-4])\b/u',
                ' ', mb_strtolower($query, 'UTF-8')
            )) ?: $query;
            $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        }

        $found = ($query !== '' || $bestSellers)
            ? $presentProducts($query, $shown, $filters)
            : ['products' => [], 'search_id' => null];

        if (empty($found['products'])) {
            // Uydurma yok: hiç sonuç mu, yoksa gösterilecek YENİ ürün mü kalmadı?
            $reply = ! empty($shown)
                ? 'Bu tarife uyan başka ürün göremiyorum. Renk, beden ya da model olarak biraz daha tarif ederseniz yeniden bakayım.'
                : 'Aradığınız kriterlere uygun bir ürün bulamadım. Farklı bir şekilde tarif etmek ister misiniz?';

            return [$reply, $extra];
        }

        $extra['products'] = $found['products'];
        $extra['search_id'] = $found['search_id'];
        $extra['match'] = $found['match'] ?? 'exact';

        // Tam eşleşme YOKSA modelin sunuş cümlesi ("işte aradığınız kırmızı 38'ler")
        // YALAN olur — kartlar başka ürün. Cümleyi sistem devralır.
        if (($found['match'] ?? 'exact') === 'best_sellers') {
            $reply = $this->bestSellersText($filters);
        } elseif (($found['match'] ?? 'exact') !== 'exact') {
            $reply = $this->relaxedProductsText($found['match'], $found['dropped'] ?? []);
        } else {
            $reply = $this->stripFiller($reply);
            if ($reply === '') {
                // Bütçe söylendiyse cümle bunu teyit etsin — "Bunlar da
                // ilginizi çekebilir" müşterinin sorduğu soruyu duymamış gibi.
                $reply = $priceMax !== null
                    ? number_format($priceMax, 0, ',', '.') . ' TL altındaki seçenekler şunlar:'
                    : (empty($shown)
                        ? 'Size uygun olabilecek birkaç ürün buldum:'
                        : 'Bunlar da ilginizi çekebilir:');
            }
        }

        $shown = array_values(array_unique(array_map('intval', (array) ($ctx['shown_product_ids'] ?? []))));
        foreach ($found['products'] as $p) {
            $id = (int) ($p['id'] ?? 0);
            if ($id) {
                $shown[] = $id;
            }
        }

        $ctx['shown_product_ids'] = array_slice(array_values(array_unique($shown)), -60);
        if ($query !== '') {
            $ctx['last_product_query'] = $query;
        }
        if ($priceMax !== null) {
            $ctx['price_max'] = $priceMax;
        }
        // Ekranda DURAN kartların özeti: bir sonraki turda modele verilir ki
        // "en ucuzu hangisi", "ikincisi kaç para", "bunun siyahı var mı" gibi
        // takip soruları cevaplanabilsin (4 Eyl: bot ne gösterdiğini bilmiyordu,
        // müşteri "sen anlamıyorsun beni" deyip temsilciye devretti).
        $ctx['last_products'] = self::chatProductCards($found['products'], 4);
        $session->forceFill(['context' => $ctx])->save();

        return [$reply, $extra];
    }

    /**
     * Mesajdaki tavan fiyat ("600 TL altı", "1000 liradan ucuz", "en fazla 750").
     *
     * 4 Eyl konuşmasında müşteri "fiyatlar yüksek geldi 600 tl altı yok mu?"
     * dedi; kısıt hiç uygulanmadı, üstelik cevap "beden ve renk stokta yok"
     * diyerek sorulmayan soruya cevap verdi.
     */
    protected function extractPriceCeiling(string $message): ?float
    {
        $m = mb_strtolower($message, 'UTF-8');
        // Binlik ayraçlı yazım ("1.250") önce denenir; \d{2,6} tek başına onun
        // yalnız "1"ini görüp eşleşmeyi kaçırıyordu.
        $money = '(\d{1,3}(?:[.,]\d{3})+|\d{2,6})\s*(?:tl|lira|₺)?';

        foreach (self::pricePatterns($money) as $re) {
            if (preg_match($re, $m, $hit)) {
                $val = (float) str_replace(['.', ','], '', $hit[1]);
                if ($val >= 50 && $val <= 500000) {
                    return $val;
                }
            }
        }

        return null;
    }

    /** @return array<int, string> tavan fiyat kalıpları (extract + strip aynı listeyi kullanır) */
    protected static function pricePatterns(string $money): array
    {
        return [
            "/{$money}\\s*(?:tl|lira|₺)?\\s*(?:'?n?in)?\\s*(?:alt[ıi]|alt[ıi]nda|aşağ[ıi]s[ıi]|asag[ıi]s[ıi])/u",
            "/{$money}\\s*(?:tl|lira|₺)?\\s*(?:'?den|'?dan|den|dan)\\s*(?:ucuz|az|d[üu]ş[üu]k)/u",
            "/(?:en\\s*fazla|maksimum|max|bütçem|butcem)\\s*{$money}/u",
            "/{$money}\\s*(?:tl|lira|₺)?\\s*(?:'?ye|'?ya|ye|ya)\\s*kadar/u",
        ];
    }

    /**
     * Fiyat ifadesini ARAMA SORGUSUNDAN siler.
     *
     * Model "600 TL altı gri spor ayakkabı 44 numara" gibi bir search_query
     * üretiyor; "600" ve "TL" metin olarak aramaya girince motor şaşırıyor
     * (canlı testte 44 numara isteyene "İlk Adım" bebek ayakkabısı döndü).
     * Fiyat artık price_max filtresiyle uygulanıyor, metinde işi yok.
     */
    protected function stripPricePhrases(string $query): string
    {
        $q = $query;
        $money = '(\\d{1,3}(?:[.,]\\d{3})+|\\d{2,6})\\s*(?:tl|lira|₺)?';
        foreach (self::pricePatterns($money) as $re) {
            $q = preg_replace($re . 'i', ' ', $q) ?? $q;
        }
        // Geriye kalan yalnız para birimi kalıntıları
        $q = preg_replace('/\\b\\d{2,6}\\s*(?:tl|lira|₺)\\b/iu', ' ', $q) ?? $q;

        return trim(preg_replace('/\\s{2,}/u', ' ', $q) ?? $q);
    }

    /**
     * Devir toplama adımından çıkış işareti: müşteri ya açıkça vazgeçti ya da
     * ürün aramaya döndü. Sipariş/iade/kargo geçen mesajlar KAPSAM DIŞI —
     * onlar gerçek devir detayı olabilir.
     */
    protected function isEscalationAbort(string $message, int $tenantId): bool
    {
        $m = mb_strtolower(trim($message), 'UTF-8');
        if (preg_match('/\b(vazgeçtim|vazgectim|iptal|boş ver|bos ver|gerek yok|önemli değil|onemli degil)\b/u', $m)) {
            return true;
        }

        // Devir adımındayken müşteri CEVABI ELİMİZDE OLAN yeni bir soru sorduysa
        // ("İade kargo ücretsiz mi", "Kargo ücreti ne kadar") akışta tutup telefon
        // istemek müşteriyi kaçırıyor: 4-6 Eyl kayıtlarında başlatılan 42 devrin
        // 19'u tam burada yarıda kaldı. Aşağıdaki konu kelimesi freni bu soruları
        // da "devrin devamı" sayıyordu — önce cevaplanabilirliğe bak.
        if ($this->isAnswerableQuestion($message, $tenantId)) {
            return true;
        }

        if (preg_match('/sipariş|siparis|kargo|iade|değişim|degisim|ödeme|odeme|fatura|şikayet|sikayet/u', $m)) {
            return false;
        }

        // Bütçe söylemek veya listedeki ürünü sormak = alışverişe dönüş.
        // (Devir toplama adımında "en ucuzu hangisi" diyen müşteri hâlâ
        // telefon numarası isteniyordu.)
        if ($this->extractPriceCeiling($message) !== null
            || preg_match('/(en\\s*(ucuz|uygun|pahal[ıi])|ka[çc]\\s*para)/u', $m)) {
            return true;
        }

        return (bool) preg_match('/(öner|oner|bul|göster|goster|bak(ar|abilir)|var mı|var mi|arıyorum|ariyorum)/u', $m);
    }

    /**
     * KISA + SORU BİÇİMİNDE + onaylı bir metne DAYANAN soru mu?
     *
     * Üç şart da aranır, çünkü devir akışından çıkmanın bedeli yüksektir:
     *  • uzun mesaj = anlatılan bir durum (sepette taksit çıkmıyor…) — hazır
     *    cevap orada yanlış olur, insana gitmeli;
     *  • soru biçimi yoksa müşteri zaten soru sormuyordur;
     *  • dayanak yoksa bot uydurmadan cevaplayamaz (kapsam/güven kapısının
     *    aynı ölçütü) — devirde kalması doğrudur.
     * Şikâyetin içinde geçen soru da (bkz ChatIntents::isComplaint) devirde kalır.
     */
    protected function isAnswerableQuestion(string $message, int $tenantId): bool
    {
        $m = trim($message);

        if (mb_strlen($m) > 120 || ChatIntents::isComplaint($m)) {
            return false;
        }

        // KİŞİSEL DURUM ANLATIMI genel soru değildir: "iade kodumu göremiyorum"
        // kalıp olarak "iade nasıl yapılır"a benziyor ama müşterinin ihtiyacı
        // KENDİ talebiyle ilgili — hazır cevapla kapatılırsa insana hiç ulaşamaz.
        if (preg_match('/(göremiyorum|goremiyorum|göremedim|goremedim|alamıyorum|alamiyorum|alamadım|alamadim|bulamıyorum|bulamiyorum|bulamadım|bulamadim|yapamıyorum|yapamiyorum|edemiyorum|giremiyorum|açılmıyor|acilmiyor|çalışmıyor|calismiyor|olmuyor|çıkmıyor|cikmiyor|gelmiyor|oluşturdum|olusturdum|sipariş verdim|siparis verdim|bekliyorum|\bkodum\w*|\btalebim\w*|\bçekim\b|\bcekim\b)/iu', $m)) {
            return false;
        }

        // (a) Çok sorulan sorular kataloğu bu soruyu ZATEN deterministik
        // cevaplıyorsa çıkmak güvenlidir — cevap mağazanın onaylı metnidir.
        // 'order'/'products' niyetleri bilerek hariç: devredilmiş müşteriyi
        // sipariş doğrulama akışına sokmak iletişim toplama adımıyla çakışır.
        $intent = ChatIntents::detect($m);
        if ($intent !== null) {
            return (ChatIntents::CATALOG[$intent]['action'] ?? 'faq') === 'faq'
                && ChatIntents::faqAnswer($tenantId, $intent) !== null;
        }

        // (b) Katalogda yoksa: soru biçiminde OLMALI ve onaylı bir metne
        // DAYANMALI. Dayanaksız soru zaten cevaplanamaz, devirde kalsın.
        // Soru eki Türkçe'de çekimlenir: "mi", "mısınız", "miyim", "mudur"…
        if (! preg_match('/\?|\bm[ıiuü](sınız|siniz|sunuz|sünüz|yım|yim|yum|yüm|yız|yiz|sın|sin|dır|dir|dur|dür)?\b|nasıl|nasil|ne kadar|kaç|kac|nedir|ne zaman|hangi|kaça|kaca/u', mb_strtolower($m, 'UTF-8'))) {
            return false;
        }

        $grounding = ChatKnowledge::match($tenantId, $m);

        return $grounding !== null && (float) ($grounding['score'] ?? 0) >= 0.5;
    }

    /**
     * "Başka / daha farklı / bunlar olmadı" gibi ÜRÜN TAKİP istekleri. Model bunları
     * çoğu kez topic=diger sayıp temsilciye devrediyordu (4 Eyl: "Başka yok mu" →
     * devir). Ürün bağlamı varken deterministik olarak yeni kart göstermek doğru.
     */
    protected function isMoreProductsIntent(string $message): bool
    {
        $m = mb_strtolower(trim($message), 'UTF-8');
        if (mb_strlen($m) > 60) {
            return false; // uzun mesaj = yeni bir talep/şikâyet, takip değil
        }
        if (preg_match('/sipariş|kargo|iade|değişim|ödeme|fatura|kupon|indirim kodu/u', $m)) {
            return false;
        }

        return (bool) preg_match('/\b(başka|baska|daha|farklı|farkli|diğer|diger|alternatif|benzer|yenile|beğenmedim|begenmedim|olmadı|olmadi)\b/u', $m);
    }

    /**
     * Modelin "hemen bakıyorum / arama yapıyorum" gibi dolgu cümleleri. Prompt
     * yasaklıyor ama gpt-4o-mini uymuyor (4 Eyl kayıtları); kartlar zaten aynı
     * mesajda geldiği için bu cümle müşteriyi bekletiyormuş gibi duruyor.
     */
    protected function stripFiller(string $reply): string
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', trim($reply)) ?: [];
        $kept = array_filter($parts, fn ($p) => ! preg_match(
            '/hemen (bakıyor|bakiyor|buluyor|gösteriyor|gosteriyor|listeliyor|arıyor|ariyor)|arama yap|bir saniye|kontrol ediyorum|bakayım|bakalım/iu',
            $p
        ));

        return trim(implode(' ', $kept));
    }

    /**
     * Tam eşleşme bulunamayıp arama gevşetildiğinde kullanılan sunuş cümlesi.
     * Amaç dürüstlük: müşteri "38 kırmızı spor ayakkabı" dediyse ve o kombinasyon
     * stokta yoksa, gösterilen kartların ARADIĞI ŞEY OLMADIĞI açıkça söylenir.
     *
     * @param  array<int, string>  $dropped  gevşetilen faset anahtarları
     */
    /**
     * Sohbet boyunca geçerli kalan müşteri tercihleri (beden/numara, renk).
     *
     * Neden deterministik: 4 Eyl kayıtlarında müşteri "44 numara giyiyorum"
     * dedikten sonra ikinci turda kısıt kayboluyordu — modelin her turda
     * search_query'ye bedeni yazmasına güvenilemez. Burada yakalanan tercih
     * hem prompt'a (model tekrar sormasın) hem motora (filtre) gider.
     *
     * @param  array<string, string>  $prefs  mevcut tercihler
     * @return array<string, string>  güncellenmiş tercihler (yeni söylenen ESKİYİ EZER)
     */
    protected function capturePrefs(string $message, array $prefs): array
    {
        $m = mb_strtolower($message, 'UTF-8');

        // Fiyat cümlelerini at: "600 tl", "1.250 lira" beden sanılmasın.
        $m = $this->stripPricePhrases($m) ?: $m;

        // Ayakkabı/konfeksiyon beden aralığı 16-54; fiyat rakamları bu aralığa
        // düşse bile yanına "numara/beden/giyiyorum" gelmediği sürece alınmaz.
        $size = '(1[6-9]|[2-4][0-9]|5[0-4])';
        foreach ([
            '/\b' . $size . '\s*(?:numara|beden|no)\b/u',
            '/\b(?:numara|beden|no)[a-zçğıöşü]{0,4}\s*[:\s]\s*' . $size . '\b/u',
            '/\b' . $size . '\s*(?:numara|beden)?\s*giy/u',
        ] as $re) {
            if (preg_match($re, $m, $hit)) {
                $prefs['beden'] = $hit[1];
                break;
            }
        }

        $renk = $this->colorIn($m);
        if ($renk !== null) {
            $prefs['renk'] = $renk;
        }

        return $prefs;
    }

    /** Metindeki ilk rengi katalog karşılığıyla döndürür (Türkçe ekler tolere edilir). */
    protected function colorIn(string $text): ?string
    {
        foreach (self::COLOR_MAP as $word => $canon) {
            if (preg_match('/\\b' . $word . '[a-zçğıöşü]{0,4}\\b/u', $text)) {
                return $canon;
            }
        }

        return null;
    }

    /**
     * Sohbette söylenen rengi arama sorgusuna işler: sorgudaki ESKİ/eş anlamlı
     * renk ayıklanır, katalogdaki karşılığı başa yazılır ("kahverengi" → "kahve").
     * Renk yapısal filtre olarak geçirilemez — katalog değerleri bileşiktir
     * ("Siyah Beyaz", "Kahve Süet") ve motorun kendi önek eşleştirmesi bunları
     * doğru buluyor; filtre ise tam eşleşme arar.
     */
    protected function applyColorPref(string $query, ?string $renk): string
    {
        if (trim($query) === '') {
            return $query;
        }

        $q = mb_strtolower($query, 'UTF-8');
        $renk ??= $this->colorIn($q);
        if ($renk === null) {
            return $query;
        }

        foreach (array_keys(self::COLOR_MAP) as $word) {
            $q = preg_replace('/\\b' . $word . '[a-zçğıöşü]{0,4}\\b\\s*/u', '', $q);
        }

        return trim($renk . ' ' . trim(preg_replace('/\\s+/u', ' ', $q)));
    }

    /**
     * Mesaj SADECE bir tercih bildiriyor mu? ("siyah olanı var mı", "44 numara")
     *
     * Böyle bir mesajda modele gitmeye gerek yok: elimizde zaten bir arama
     * bağlamı var, kısıtı değiştirip listeyi tazelemek doğru davranış. Renk/beden
     * dışında ANLAMLI bir kelime varsa ("38 beden mont var mı") kısa devre
     * ÇALIŞMAZ — yeni bir talep olabilir, karar modelin.
     */
    protected function isPrefOnlyMessage(string $message, array $prefs): bool
    {
        $m = mb_strtolower(trim($message), 'UTF-8');
        if ($m === '' || mb_strlen($m) > 45) {
            return false;
        }

        $stop = ['var', 'yok', 'mı', 'mi', 'mu', 'mü', 'olan', 'olanı', 'olani', 'olsun', 'olur',
            'peki', 'bunun', 'şunun', 'sunun', 'rengi', 'renginde', 'renk', 'numara', 'numarası',
            'beden', 'bedeni', 'giyiyorum', 'istiyorum', 'lütfen', 'lutfen', 'tamam', 'ama',
            'bir', 'tane', 'sadece', 'de', 'da', 'ben', 'benim', 'no', 'evet', 'hayır', 'hayir',
            'aslında', 'aslinda', 'yine', 'tekrar', 'acaba', 'galiba', 'sanırım', 'sanirim',
            'bunlar', 'şunlar', 'sunlar', 'daha', 'çok', 'cok', 'ise', 'ya', 'yada', 'veya'];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $m, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            if (in_array($w, $stop, true) || is_numeric($w)) {
                continue;
            }
            if ($this->colorIn($w) === null) {
                return false;
            }
        }

        return $prefs !== [];
    }

    /**
     * "En çok satan / ne önerirsin / ne alacağımı bilmiyorum" niyeti.
     *
     * Model bunları serbest aramaya çeviriyor, motor eşleşme bulamayınca
     * "Tam olarak aradığınız özellikte stokta ürün bulamadım" diyordu — oysa
     * müşteri zaten mağazanın vitrinini istiyor. Kısa devre YANLIŞ CÜMLEYİ
     * ve gereksiz LLM turunu birlikte kaldırır.
     */
    protected function isBestSellerIntent(string $message): bool
    {
        $m = mb_strtolower(trim($message), 'UTF-8');
        if (mb_strlen($m) > 80) {
            return false; // uzun mesaj = anlatılan bir durum, vitrin isteği değil
        }
        if (preg_match('/sipariş|siparis|kargo|iade|değişim|degisim|ödeme|odeme|fatura|kupon|indirim kodu|şikayet|sikayet/u', $m)) {
            return false;
        }

        return (bool) preg_match(
            '/(en\s*çok\s*(satan|tercih|beğenilen|sevilen)|en\s*(popüler|populer|beğenilen|begenilen|sevilen)'
            . '|çok\s*satan|cok\s*satan|best\s*seller|çok\s*tercih|cok\s*tercih'
            . '|ne\s*(önerir|onerir|tavsiye)|neler\s*(önerir|onerir)|hangisini\s*(önerir|onerir|tavsiye)'
            . '|öneri(niz|n)?\s*(var|ne)|tavsiye\s*(eder|ne|var)'
            . '|ne\s*al(acağımı|acagimi|ayım|ayim|sam)|karar(sızım|sizim|\s*veremedim))/u',
            $m
        );
    }

    /** En çok satanlar sunuş cümlesi — söylenen kısıtları da teyit eder. */
    protected function bestSellersText(array $filters): string
    {
        $parts = [];
        if (! empty($filters['beden'])) {
            $parts[] = $filters['beden'] . ' numara';
        }
        if (isset($filters['price_max'])) {
            $parts[] = number_format((float) $filters['price_max'], 0, ',', '.') . ' TL altı';
        }

        return $parts === []
            ? 'En çok tercih edilen modellerimiz şunlar:'
            : implode(' / ', $parts) . ' için en çok tercih edilen modellerimiz şunlar:';
    }

    protected function relaxedProductsText(string $match, array $dropped): string
    {
        if ($match === 'similar') {
            return 'Tam olarak aradığınız özellikte stokta bir ürün bulamadım. Bunlar ilginizi çekebilir:';
        }

        if ($dropped === ['fiyat']) {
            return 'Belirttiğiniz fiyatın altında stokta ürün göremedim; en uygun fiyatlı alternatifler şunlar:';
        }

        $labels = ['beden' => 'bedende', 'renk' => 'renkte'];
        $parts = array_values(array_filter(array_map(fn ($k) => $labels[$k] ?? null, $dropped)));

        if ($parts === ['bedende']) {
            return 'Aradığınız bedende stokta ürün göremedim; benzer ürünlerin stokta olan bedenleri şöyle:';
        }
        if (count($parts) > 1) {
            return 'Aradığınız beden ve renk birlikte stokta yok; en yakın alternatifler şunlar:';
        }

        return 'Tam aradığınız kriterlerde stokta ürün yok; en yakın alternatifler şunlar:';
    }

    /**
     * Devir mesajının başına konacak giriş cümlesi. Modelin cevabının YALNIZ
     * ilk cümlesi alınır ve "temsilcimize ulaşın / 0850..." gibi yönlendirme
     * cümleleri atılır — devir cümlesini sistem zaten ekliyor, ikisi üst üste
     * gelince mesaj kendini tekrar ediyor.
     */
    protected function escalationLead(string $reply, string $userMessage, ?array $grounding): string
    {
        $first = trim((string) (preg_split('/(?<=[.!?])\s+/u', trim($reply), 2)[0] ?? ''));

        if ($first === '' || mb_strlen($first) > 160) {
            return '';
        }
        if (preg_match('/iletişime geç|müşteri hizmetleri|temsilci|destek hatt|\b0\d{3}\b|aktar/iu', $first)) {
            return '';
        }
        if (! $this->numbersGrounded($first, $userMessage, $grounding, [])) {
            return '';
        }

        return $first;
    }

    /**
     * KAPSAM VE GÜVEN KAPISI. Bot yalnız panelden açılmış konularda ve yalnız
     * onaylı metne dayanarak cevap verir; kalan her şey insana gider.
     *
     * @return string|null null = cevap verilebilir, aksi halde devir sebebi
     */
    protected function gate(array $settings, string $topic, string $action, float $confidence, ?array $grounding, string $reply): ?string
    {
        if ($action === 'handoff') {
            return 'model_devretti';
        }

        // Selamlama/teşekkür/veda — mağaza bilgisi içermez, dayanak aranmaz
        if ($topic === 'sohbet') {
            return null;
        }

        if ($topic === 'urun') {
            return $settings['allow_products'] ? null : 'kapsam_disi';
        }

        if ($topic === 'siparis') {
            return $settings['allow_order_status'] ? null : 'kapsam_disi';
        }

        if ($topic === 'magaza_bilgi') {
            if (! $settings['allow_faq']) {
                return 'kapsam_disi';
            }
            if ($settings['answer_mode'] === 'strict' && $grounding === null) {
                return 'bilgi_yok';
            }
            if ($confidence < (float) $settings['min_confidence']) {
                return 'dusuk_guven';
            }

            return null;
        }

        // Mağazayla hiç ilgisi olmayan konu: temsilciyi meşgul etmeden kibarca
        // mağaza konularına döndür (hava durumu için insana devretmek anlamsız).
        if ($topic === 'alakasiz') {
            return null;
        }

        // topic=diger — mağazayla ilgili ama cevaplanamayan her şey → insana
        return 'kapsam_disi';
    }

    /**
     * Cevaptaki SAYISAL İDDİALAR dayanakta geçmiyorsa cevap gönderilmez.
     * "14 gün", "1-3 iş günü", "0850 480 75 57", "%10", "250 TL" gibi ifadeler
     * uydurulduğunda müşteriye somut yanlış bilgi gider; en pahalı hata sınıfı bu.
     * Sadece BİRİM/İDDİA taşıyan sayılar denetlenir ("2 seçenek" gibi zararsız
     * kullanımlar serbest).
     */
    protected function numbersGrounded(string $reply, string $userMessage, ?array $grounding, array $products): bool
    {
        $pattern = '/(\d[\d.,]*)\s*(?:-\s*\d[\d.,]*\s*)?(?:%|₺|tl|gün|gun|iş günü|is gunu|hafta|ay|yıl|yil|adet|cm|mm|numara|beden|saat|dakika)\b/iu';

        if (! preg_match_all($pattern, $reply, $m)) {
            // Birimi olmayan ama 3+ haneli sayılar da iddia sayılır (telefon, kod, fiyat)
            if (! preg_match_all('/\b(\d{3,})\b/u', $reply, $m)) {
                return true;
            }
        }

        $haystack = ChatKnowledge::normalize(
            $userMessage . ' ' . ($grounding['context'] ?? '') . ' ' . json_encode($products, JSON_UNESCAPED_UNICODE)
        );
        // Yüzde/binlik ayraçlarını sadeleştir ki "1.299,00" ~ "1299" tutsun
        $haystack = preg_replace('/[.,](?=\d)/', '', $haystack);

        foreach ($m[1] as $num) {
            $clean = preg_replace('/[.,](?=\d)/', '', $num);
            if ($clean === '' || ! str_contains($haystack, $clean)) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Sipariş doğrulama (deterministik, PII-güvenli)
    // ------------------------------------------------------------------

    /** @return array<int, array>|null null = bu tur sipariş akışı değil */
    protected function tryOrderLookup(Tenant $tenant, ChatSession $session, string $message, array $ctx): ?array
    {
        $orderNo = $this->extractOrderNo($message) ?? ($ctx['pending_order_no'] ?? null);
        $email = $this->extractEmail($message) ?: ($ctx['pending_email'] ?? null);
        $phone = $this->extractPhone($message) ?: ($ctx['pending_phone'] ?? null);

        if (! $orderNo) {
            // Sipariş akışındayız ve müşteri YALNIZ iletişim bilgisini yazdı
            // (4 Eyl: "5061974801" → bot aynı cümleyi tekrarladı). Bilgiyi sakla,
            // eksik olan parçayı iste.
            if (! empty($ctx['awaiting_order_info']) && ($email || $phone)) {
                $ctx['pending_email'] = $email;
                $ctx['pending_phone'] = $phone;
                $session->forceFill(['context' => $ctx])->save();

                return [$this->assistant($session, 'İletişim bilginizi aldım. Şimdi sipariş numaranızı da yazar mısınız? (Sipariş onay e-postanızda veya "Siparişlerim" sayfasında yer alır.)')];
            }

            return null;
        }

        // Sipariş no var ama iletişim yok → no'yu hatırla, iletişim iste
        if (! $email && ! $phone) {
            $ctx['pending_order_no'] = $orderNo;
            $session->forceFill(['context' => $ctx])->save();

            return [$this->assistant($session, 'Sipariş bilgilerinizi güvenliğiniz için doğrulamam gerekiyor. Siparişte kayıtlı e-posta adresinizi veya telefon numaranızı yazar mısınız?')];
        }

        // Deneme freni (enumeration koruması)
        $attempts = (int) ($ctx['order_attempts'] ?? 0);
        if ($attempts >= (int) config('chat.order_attempts', 5)) {
            return $this->beginEscalation(
                $tenant, $session, 'siparis_dogrulanamadi',
                'Sipariş doğrulama denemesi sınırına ulaşıldı.'
            );
        }
        $ctx['order_attempts'] = $attempts + 1;
        unset($ctx['pending_order_no'], $ctx['pending_email'], $ctx['pending_phone'], $ctx['awaiting_order_info']);
        $session->forceFill(['context' => $ctx])->save();

        $order = $this->verifyOrder($tenant->id, $orderNo, $email, $phone);
        if (! $order) {
            // Uyuşmayan bilgi akıştan ÇIKMA sebebi değil (5 Eyl): müşteri
            // düzeltmeyi tek parça olarak yazınca ("05xx...") bot sipariş
            // akışını kaybediyor, mesaj LLM'e düşüyordu. Verilenleri sakla,
            // akışta kal — hangi bilginin yanlış olduğunu SIZDIRMADAN söyle.
            $ctx['awaiting_order_info'] = 1;
            $ctx['pending_order_no'] = $orderNo;
            if ($email) {
                $ctx['pending_email'] = $email;
            }
            if ($phone) {
                $ctx['pending_phone'] = $phone;
            }
            $session->forceFill(['context' => $ctx])->save();

            return [$this->assistant($session, 'Bu bilgiler birbiriyle uyuşmadı; eşleşen bir sipariş bulamadım.'
                . ' Sipariş numaranızı ve siparişte kayıtlı e-posta adresinizi ya da telefon numaranızı bir daha yazar mısınız?'
                . ' Yalnızca düzeltmek istediğiniz bilgiyi yazmanız da yeterli.', ['topic' => 'siparis', 'order_mismatch' => true])];
        }

        // DOĞRULANMIŞ sipariş sohbet boyunca hatırlanır. Aksi hâlde takip sorusu
        // ("Kargo nerede", "ne zaman gelir") baştan sipariş no + doğrulama
        // istiyordu ve müşteri "yazdım ya" diyordu (5-6 Eyl kayıtları).
        $ctx['verified_order'] = ['no' => $order->order_no, 'email' => $email, 'phone' => $phone];
        $session->forceFill(['context' => $ctx])->save();

        return [$this->assistant($session, $this->orderStatusText($tenant->id, $order), ['topic' => 'siparis'])];
    }

    /**
     * Bu sohbette daha önce DOĞRULANMIŞ siparişin güncel durumu.
     * Doğrulama tekrar yapılır (durum değişmiş olabilir, kayıt silinmiş olabilir).
     *
     * @return array<int, array>|null
     */
    protected function verifiedOrderStatus(Tenant $tenant, ChatSession $session, array $ctx): ?array
    {
        $v = (array) ($ctx['verified_order'] ?? []);
        if (empty($v['no'])) {
            return null;
        }

        $order = $this->verifyOrder($tenant->id, (string) $v['no'], $v['email'] ?? null, $v['phone'] ?? null);
        if (! $order) {
            return null;
        }

        return [$this->assistant($session, $this->orderStatusText($tenant->id, $order), [
            'topic' => 'siparis',
            'from_verified_order' => true,
        ])];
    }

    protected function verifyOrder(int $tenantId, string $orderNo, ?string $email, ?string $phone): ?object
    {
        $order = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereRaw('UPPER(order_no) = ?', [mb_strtoupper($orderNo)])
            ->orderByDesc('id')
            ->first();

        if (! $order) {
            return null;
        }

        if ($email && mb_strtolower(trim((string) $order->customer_email)) === $email) {
            return $order;
        }

        if ($phone) {
            foreach (['shipping_address', 'billing_address'] as $col) {
                $addr = json_decode((string) $order->{$col}, true) ?: [];
                if ($this->normalizePhone((string) ($addr['phone'] ?? '')) === $phone) {
                    return $order;
                }
            }
        }

        return null;
    }

    /**
     * Sipariş durumu cevabı: DURUM + o durumun ne anlama geldiği.
     *
     * Neden beklenti cümlesi (5 Eyl kullanıcı geri bildirimi): kuru bir
     * "durumu: Siparişiniz Alındı." müşterinin sorusunu cevaplamıyor —
     * sorduğu şey "ne zaman elimde olacak". Metinler panelden yönetilebilir
     * (chat_order_notes), çünkü taahhüdün sahibi mağazadır; kod yalnız
     * makul bir varsayılan taşır.
     */
    protected function orderStatusText(int $tenantId, object $order): string
    {
        $text = "{$order->order_no} numaralı siparişinizin durumu: {$order->status_label}.";

        $note = trim((string) (TenantChatSettings::get($tenantId)['order_notes'][(int) $order->status] ?? ''));
        if ($note !== '') {
            $text .= ' ' . $note;
        }

        // Henüz kargoya çıkmamış sipariş: kargo çıkış günü CANLI takvimden
        // söylenir (8 Eyl). Öncesinde sabit "genellikle ertesi iş günü" yazıyordu
        // ve vitrindeki "bugün kargoda" rozetiyle çelişiyordu.
        if (empty($order->cargo_tracking_no) && in_array((int) $order->status, self::PRE_SHIPMENT_STATUSES, true)) {
            $placedAt = ! empty($order->order_date)
                ? CarbonImmutable::parse($order->order_date, ChatShipping::TZ)
                : null;
            $dispatch = ChatShipping::dispatchNote($tenantId, $placedAt);
            if ($dispatch !== null) {
                $text .= ' ' . $dispatch;
            }
        }

        if (! empty($order->cargo_tracking_no)) {
            // ⚠️ Sürat Kargo takip sayfası artık numarayı ÖN DOLDURMUYOR (captcha'lı
            // yeni ekran) — numara cümlede de geçmek zorunda. Eski link biçimi
            // (gonderi-takip?code=…) 404 veriyordu; bkz App\Support\CargoTracking.
            $text .= ' ' . CargoTracking::sentence(
                $order->cargo_company,
                $order->cargo_tracking_no,
                $order->order_no
            );
        }

        return $text;
    }

    protected function askOrderInfoText(array $ctx): string
    {
        return 'Siparişinizi kontrol edebilmem için sipariş numaranızı ve siparişte kayıtlı e-posta adresinizi veya telefon numaranızı yazar mısınız?';
    }

    // ------------------------------------------------------------------
    // Handoff (müşteri temsilcisine devir)
    // ------------------------------------------------------------------

    /**
     * DEVİR AKIŞININ GİRİŞİ. Bot cevaplamadığı her durumda buradan geçer.
     * Sıra: (1) sorunun detayını iste → (2) iletişim bilgisi iste → (3) devret.
     * Detay adımı kullanıcı isteği (4 Eyl): personel konuşmayı açtığında elinde
     * "temsilci istiyorum"dan fazlası olsun.
     *
     * @return array<int, array>
     */
    protected function beginEscalation(Tenant $tenant, ChatSession $session, string $reason, string $leadText = ''): array
    {
        $settings = TenantChatSettings::get($tenant->id);

        $ctx = $session->context ?? [];
        $ctx['escalation_reason'] = $reason;
        $session->forceFill(['context' => $ctx])->save();

        // Devir kapalıysa bot yine de yanlış cevap vermez; iletişim kanalına yönlendirir
        if (! $settings['handoff_enabled']) {
            return [$this->assistant($session, trim($leadText . ' Bu konuda size müşteri hizmetlerimiz yardımcı olabilir.'), [
                'escalation_reason' => $reason,
            ])];
        }

        if ($settings['ask_detail'] && empty($ctx['detail_collected'])) {
            $ctx['awaiting_detail'] = 1;
            $session->forceFill(['context' => $ctx])->save();

            // Müşteri temsilciyi kendisi istediyse "aktarmam gerekiyor" demek tuhaf kaçar
            $intro = $reason === 'musteri_istegi'
                ? 'Tabii, sizi müşteri temsilcimize aktarıyorum.'
                : 'Bu konuda size doğru bilgiyi verebilmem için müşteri temsilcimize aktarmam gerekiyor.';

            $ask = $intro . ' Temsilcimizin hazırlıklı dönebilmesi için sorunuzu/talebinizi biraz detaylandırır mısınız? '
                . '(Varsa sipariş numaranızı da yazabilirsiniz.)';

            return [$this->assistant($session, trim($leadText) !== '' ? trim($leadText) . ' ' . $ask : $ask, [
                'escalation_reason' => $reason,
            ])];
        }

        return $this->startHandoff($tenant, $session, $leadText);
    }

    /**
     * Müşterinin yazdığı detay alınır ve iletişim adımına geçilir.
     * Detay ne olursa olsun kabul edilir (ısrar edip müşteriyi yormayız).
     *
     * @return array<int, array>
     */
    protected function collectDetail(Tenant $tenant, ChatSession $session, string $message, array $ctx): array
    {
        unset($ctx['awaiting_detail']);
        $ctx['detail_collected'] = 1;
        $ctx['detail'] = mb_substr(trim($message), 0, 1000);
        $session->forceFill(['context' => $ctx])->save();

        return $this->startHandoff($tenant, $session, 'Teşekkür ederim, not aldım.');
    }

    /** @return array<int, array> */
    protected function startHandoff(Tenant $tenant, ChatSession $session, string $leadText = ''): array
    {
        $contact = $session->contact ?? [];
        if (! empty($contact['email']) || ! empty($contact['phone'])) {
            return $this->performHandoff($tenant, $session, $leadText);
        }

        $ctx = $session->context ?? [];
        $ctx['awaiting_contact'] = 1;
        $session->forceFill(['context' => $ctx])->save();

        $ask = $this->contactAskText($tenant);

        return [$this->assistant($session, trim($leadText) !== '' ? trim($leadText) . ' ' . $ask : $ask)];
    }

    /**
     * İletişim bilgisi toplanır ve devir tamamlanır. Müşteri vermek istemezse
     * bir kez daha sorulur, sonra anonim "Web Ziyaretçisi" olarak devredilir
     * (conversations.customer_id NOT NULL).
     *
     * @return array<int, array>
     */
    protected function collectContactAndHandoff(Tenant $tenant, ChatSession $session, string $message, array $ctx): array
    {
        $email = $this->extractEmail($message);
        $phone = $this->extractPhone($message);
        $orderNo = $this->extractOrderNo($message);

        // Ad ve numara AYRI mesajlarda gelebiliyor ("Semiha cumhur" → "05438848991").
        // Önceki turda yazılan ad kayboluyordu; sakla ve numara gelince birleştir.
        $name = $this->extractPersonName($message) ?? ($ctx['pending_name'] ?? null);

        // TELEFON ZORUNLU (4 Eyl kararı): dönüş kanalı SMS/telefon olduğu için
        // numarasız devir "cevaplanamayan kayıt" üretiyor. E-posta yazarsa saklanır
        // ama tek başına yeterli değildir.
        if ($phone) {
            $session->forceFill(['contact' => array_filter([
                'name' => $name ? mb_substr($name, 0, 100) : null,
                'email' => $email ?: ($ctx['pending_email'] ?? null),
                'phone' => $phone,
            ])])->save();

            unset($ctx['awaiting_contact'], $ctx['contact_ask_count'], $ctx['pending_name']);
            $session->forceFill(['context' => $ctx])->save();

            return $this->performHandoff($tenant, $session);
        }

        // Sipariş numarası yazdıysa bu DEĞERLİ bir bilgidir; "geçerli numara
        // göremedim" demek müşteriyi duymamak olur (6 Eyl: TB8639515544 → bot
        // telefon istedi, müşteri şaşırdı). Not al, eksik olanı iste.
        if ($orderNo) {
            $ctx['detail'] = trim((string) ($ctx['detail'] ?? '') . ' Sipariş no: ' . $orderNo);
        }
        if ($name && empty($ctx['pending_name'])) {
            $ctx['pending_name'] = mb_substr($name, 0, 100);
        }
        if ($email) {
            $ctx['pending_email'] = $email;
        }

        // Numara yok — 2 kez daha iste, sonra devri bitirmeden kapat (kayıt açıp
        // ulaşamamaktansa müşteriye ne yapacağını söylemek dürüst olan).
        $asks = (int) ($ctx['contact_ask_count'] ?? 0);
        if ($asks >= 2) {
            unset($ctx['awaiting_contact'], $ctx['contact_ask_count'], $ctx['pending_name']);
            $ctx['handoff_declined'] = 1;
            $session->forceFill(['context' => $ctx])->save();

            return [$this->assistant($session, $this->noPhoneClosingText($tenant))];
        }
        $ctx['contact_ask_count'] = $asks + 1;
        $session->forceFill(['context' => $ctx])->save();

        if ($orderNo) {
            $ask = "Sipariş numaranızı ({$orderNo}) not aldım. Dönüş yapabilmemiz için bir de cep telefonu numaranızı yazar mısınız? (05XX XXX XX XX)";
        } elseif ($email) {
            $ask = 'Teşekkürler, e-postanızı aldım. Dönüşlerimizi telefonla yaptığımız için cep telefonu numaranızı da yazar mısınız? (05XX XXX XX XX)';
        } elseif ($name) {
            $ask = "Teşekkürler {$name}. Dönüş yapabilmemiz için bir de cep telefonu numaranızı yazar mısınız? (05XX XXX XX XX)";
        } else {
            $ask = 'Geçerli bir cep telefonu numarası göremedim. 05XX XXX XX XX biçiminde yazar mısınız? Dönüşü bu numara üzerinden yapacağız.';
        }

        return [$this->assistant($session, $ask)];
    }

    /**
     * Mesajdaki kişi adı ("Semiha Cumhur", "Ahmet kesik").
     *
     * Yalnız harflerden oluşan 1-4 kelimelik mesajlar ad sayılır; e-posta,
     * numara ve soru cümleleri elenir. Amaç ad ile telefonun AYRI mesajlarda
     * gelmesini desteklemek — mükemmel isim tanıma değil.
     */
    protected function extractPersonName(string $message): ?string
    {
        $m = trim((string) preg_replace(
            ['/[\w.+-]+@[\w-]+\.[\w.]+/u', '/[+\d][\d\s()-]{6,}/', '/\s+/u'],
            ['', '', ' '],
            $message
        ));

        if ($m === '' || mb_strlen($m) > 60) {
            return null;
        }
        if (preg_match('/[?\d]|\b(m[ıiuü]|nasıl|nasil|ne|kaç|kac|var|yok|lütfen|lutfen|tamam|evet|hayır|hayir|devam|aktar)\b/iu', $m)) {
            return null;
        }
        if (! preg_match('/^[\p{L}\p{M}\s.\'-]{3,60}$/u', $m)) {
            return null;
        }

        $words = preg_split('/\s+/u', $m) ?: [];
        if (count($words) < 1 || count($words) > 4) {
            return null;
        }

        return mb_convert_case($m, MB_CASE_TITLE, 'UTF-8');
    }

    /** @return array<int, array> */
    protected function performHandoff(Tenant $tenant, ChatSession $session, string $leadText = ''): array
    {
        try {
            $channel = Channel::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'type' => 'webchat'],
                ['name' => 'Site Chat', 'category' => 'message', 'is_active' => true]
            );

            $contact = $session->contact ?? [];
            $customer = $this->resolveCustomer($tenant->id, $channel->id, $contact);

            $conversation = Conversation::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'channel_id' => $channel->id,
                'customer_id' => $customer->id,
                'type' => 'message',
                'status' => 'open',
                'is_read' => false,
                'unread_count' => 1,
                'last_message_at' => now(),
                'platform_conversation_id' => 'webchat-' . $session->session_key,
                'metadata' => [
                    'chat_session_id' => $session->id,
                    'page_url' => $session->page_url,
                    'source' => 'ai_chatbot_handoff',
                    'escalation_reason' => ($session->context['escalation_reason'] ?? null),
                    'customer_detail' => ($session->context['detail'] ?? null),
                    // Mesai dışında devredilen müşteriye bot "SMS ile ileteceğiz"
                    // sözünü verdi. Personel ertesi sabah MESAİ İÇİNDE cevap
                    // yazınca eski kural "mesai içi, widget yeter" deyip SMS'i
                    // atlıyordu ve söz tutulmuyordu (5 Eyl'de 3 gerçek müşteri).
                    // Kararın dayanağı personelin ne zaman yazdığı değil,
                    // müşteriye ne söz verildiğidir.
                    'force_sms' => ! $this->isOpenNow($tenant->id),
                ],
            ]);

            // Transkripti personelin görebileceği şekilde kopyala
            foreach ($session->messages()->whereIn('role', ['user', 'assistant'])->orderBy('id')->get() as $m) {
                // Bot ürün önerdiyse kartlar da taşınır — personel Mesajlar panelinde
                // müşterinin gördüğü görselli kartların AYNISINI görür (yalnız metin
                // taşınınca "Size uygun ürünler buldum:" cümlesi havada kalıyordu).
                $cards = self::chatProductCards($m->payload['products'] ?? []);

                Message::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'conversation_id' => $conversation->id,
                    'sender_type' => $m->role === 'user' ? 'customer' : 'agent',
                    'content' => $m->content,
                    'is_ai_generated' => $m->role === 'assistant',
                    'metadata' => array_filter([
                        'chat_message_id' => $m->id,
                        'chat_products' => $cards ?: null,
                    ]),
                ]);
            }

            $session->forceFill([
                'status' => 'handoff',
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
            ])->save();

            $this->store($session, 'system', 'handoff');

            return [$this->assistant($session, trim($leadText . ' ' . $this->handoffClosingText($tenant)))];
        } catch (\Throwable $e) {
            Log::error('ChatbotService: handoff failed', ['session' => $session->id, 'error' => $e->getMessage()]);

            return [$this->assistant($session, 'Aktarma sırasında bir sorun oluştu. Lütfen müşteri hizmetlerimizle iletişime geçin.')];
        }
    }

    /**
     * Devir kapanış mesajı — MESAİ SAATİNE GÖRE doğru beklenti verir.
     * Mesai içindeyse "kısa süre içinde", dışındaysa ilk açılış saatini söyler.
     * Müşteriye "hemen dönülecek" denip 14 saat beklemesi en sık şikâyet sebebi.
     */
    protected function handoffClosingText(Tenant $tenant): string
    {
        $settings = TenantChatSettings::get($tenant->id);
        $base = 'Talebinizi müşteri temsilcimize ilettim, konuşma geçmişimizi de aktardım.';

        if (! $settings['hours_enabled']) {
            return $base . ' Temsilcimiz en kısa sürede buradan yanıt verecek. Bu sayfayı kapatsanız bile mesajınız bize ulaşmış olacak.';
        }

        $hours = $settings['hours'];
        $now = CarbonImmutable::now(ChatBusinessHours::TZ);

        // Mesai İÇİ: cevap bu sohbete düşer (SMS gönderilmez) → müşteriyi sayfada tut.
        if (ChatBusinessHours::isOpen($hours, $now)) {
            return $base . ' Birazdan bir temsilcimiz bu sohbete bağlanıp size dönüş yapacak; lütfen sayfayı açık tutun.';
        }

        // Mesai DIŞI: personel dönüş yazınca cevap SMS olarak gider (bkz
        // MessageController::sendToWebchat) — müşteriye de aynısı söylenir.
        return $base . ' Şu an mesai saatlerimiz dışındayız ('
            . ChatBusinessHours::summary($hours) . '). '
            . ChatBusinessHours::nextOpenText($hours, $now)
            . ' itibarıyla temsilcimiz dönüş yapacak ve cevabı size SMS ile ileteceğiz; bu sayfayı kapatabilirsiniz.';
    }

    /**
     * DEVİR SONRASI TEYİT. Devredilen müşteri yazmaya devam ettiğinde ekranda
     * hiçbir şey olmuyordu; 6 Eyl kaydında müşteri arka arkaya 4 mesaj yazıp
     * "Bana cevap verir misiniz / Herhalde uyuyorsunuz" dedi (temsilci ertesi
     * sabah döndü). Mesaj personele ULAŞTI, bunu söylemek gerekiyor.
     *
     * En fazla 2 kez teyit verilir — sonrası spam olur, müşteri anlamıştır.
     *
     * @return array<int, array>
     */
    protected function postHandoffAck(Tenant $tenant, ChatSession $session): array
    {
        $ctx = $session->context ?? [];
        $acks = (int) ($ctx['post_handoff_acks'] ?? 0);
        if ($acks >= 2) {
            return [];
        }

        $ctx['post_handoff_acks'] = $acks + 1;
        $session->forceFill(['context' => $ctx])->save();

        $settings = TenantChatSettings::get($tenant->id);
        $open = $this->isOpenNow($tenant->id);

        if ($acks === 0) {
            $text = $open
                ? 'Mesajınızı da temsilcimize ilettim; birazdan buradan dönüş yapacak.'
                : 'Mesajınızı da temsilcimize ilettim. Şu an mesai saatlerimiz dışındayız'
                    . ($settings['hours_enabled'] ? ' (' . ChatBusinessHours::summary($settings['hours']) . ')' : '')
                    . '; dönüş yapıldığında size haber vereceğiz.';

            return [$this->assistant($session, $text, ['topic' => 'devir', 'post_handoff_ack' => true])];
        }

        $text = $open
            ? 'Yazdıklarınızın hepsi temsilcimize iletildi, en kısa sürede dönüş yapılacak. Beklettiğimiz için özür dileriz.'
            : 'Yazdıklarınızın hepsi kaydedildi ve temsilcimize iletildi. '
                . ($settings['hours_enabled']
                    ? ChatBusinessHours::nextOpenText($settings['hours'], CarbonImmutable::now(ChatBusinessHours::TZ)) . ' itibarıyla dönüş yapılacaktır.'
                    : 'En kısa sürede dönüş yapılacaktır.')
                . ' Bu sayfayı kapatabilirsiniz.';

        return [$this->assistant($session, $text, ['topic' => 'devir', 'post_handoff_ack' => true])];
    }

    /** Devir için iletişim isteme cümlesi — dönüş kanalı telefon olduğu için numara istenir. */
    protected function contactAskText(Tenant $tenant): string
    {
        $settings = TenantChatSettings::get($tenant->id);
        $hours = $settings['hours'];
        $open = ! $settings['hours_enabled'] || ChatBusinessHours::isOpen($hours, CarbonImmutable::now(ChatBusinessHours::TZ));

        return $open
            ? 'Sizi temsilcimize bağlayabilmem için adınızı ve cep telefonu numaranızı yazar mısınız? (05XX XXX XX XX)'
            : 'Size SMS ile dönüş yapabilmemiz için adınızı ve cep telefonu numaranızı yazar mısınız? (05XX XXX XX XX)';
    }

    /** Müşteri numara vermedi: kayıt açıp ulaşamamak yerine ne yapacağını söyle. */
    protected function noPhoneClosingText(Tenant $tenant): string
    {
        $settings = TenantChatSettings::get($tenant->id);
        $text = 'Numara olmadan size dönüş yapamıyoruz, bu yüzden talebi temsilciye iletemedim.';

        if ($settings['hours_enabled']) {
            $text .= ' Mesai saatlerimiz: ' . ChatBusinessHours::summary($settings['hours']) . '.';
        }

        return $text . ' Dilediğinizde numaranızı yazarak buradan bağlanabilir ya da bu saatlerde bize ulaşabilirsiniz.';
    }

    protected function resolveCustomer(int $tenantId, int $channelId, array $contact): Customer
    {
        $query = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId);
        $existing = null;
        if (! empty($contact['email'])) {
            $existing = (clone $query)->whereRaw('LOWER(email) = ?', [mb_strtolower($contact['email'])])->first();
        }
        if (! $existing && ! empty($contact['phone'])) {
            $existing = (clone $query)->where('phone', 'LIKE', '%' . substr($contact['phone'], -10))->first();
        }
        if ($existing) {
            return $existing;
        }

        return Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'channel_id' => $channelId,
            'name' => $contact['name'] ?? 'Web Ziyaretçisi',
            'email' => $contact['email'] ?? null,
            'phone' => $contact['phone'] ?? null,
            'platform_type' => 'webchat',
        ]);
    }

    protected function forwardToConversation(ChatSession $session, string $content): void
    {
        try {
            Message::withoutGlobalScopes()->create([
                'tenant_id' => $session->tenant_id,
                'conversation_id' => $session->conversation_id,
                'sender_type' => 'customer',
                'content' => $content,
            ]);

            Conversation::withoutGlobalScopes()
                ->where('id', $session->conversation_id)
                ->update([
                    'status' => 'open',
                    'is_read' => false,
                    'last_message_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            Log::error('ChatbotService: forward failed', ['session' => $session->id, 'error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // LLM prompt
    // ------------------------------------------------------------------

    /**
     * Sistem promptu İKİ parça döner: [statik, dinamik].
     *
     * Statik parça (kurallar + mağaza özel talimatları) her mesajda BİREBİR aynı
     * olduğu için Anthropic prompt cache'ine alınır (~%90 ucuz okuma). Dinamik
     * parça (mesaja göre değişen onaylı bilgi) EN SONA konur — cache prefix'i
     * bozmasın diye. Bkz ChatLlm::callAnthropic().
     *
     * @return array{0: string, 1: string}
     */
    /** @param  array<int, array<string, mixed>>  $shownProducts  sohbette ekranda duran kartlar */
    protected function buildSystemPrompt(Tenant $tenant, array $settings, ?array $grounding, array $shownProducts = [], array $prefs = []): array
    {
        $store = $tenant->name;
        $bot = $settings['bot_name'];

        $prompt = <<<PROMPT
Sen {$store} e-ticaret mağazasının web sitesinde çalışan "{$bot}" adlı yapay zeka destekli müşteri asistanısın. Ziyaretçiler seninle sohbet penceresinden konuşuyor.

EN ÖNEMLİ KURAL — YANLIŞ CEVAP VERMEKTENSE DEVRET:
Bu mağaza hakkında söylediğin HER BİLGİ, aşağıdaki "ONAYLI MAĞAZA BİLGİSİ" bölümünde birebir geçmelidir. Orada yoksa BİLMİYORSUN demektir. Genel bilgine, tahminine veya benzer mağazalardaki uygulamalara ASLA dayanma. Emin olmadığın en ufak durumda action=handoff ver — bu bir başarısızlık değil, doğru davranıştır. Yanlış bir cevap, cevapsız kalmaktan çok daha pahalıdır.

GÖREV VE SINIRLAR:
- Yalnızca bu mağazayla ilgili yardım et: ürünler, ürün önerisi, kargo, iade, kampanyalar, sipariş süreçleri.
- Kullanıcı hangi dilde yazdıysa o dilde cevap ver.
- KISA yaz: 1-3 cümle. Markdown, madde işareti, başlık kullanma (samimi tek emoji serbest).
- Yapay zeka olduğun sorulursa dürüstçe söyle.
- "Bir saniye", "hemen bakıyorum", "lütfen bekleyin" gibi dolgu cümleleri kurma; ya cevabı ver ya devret.

ASLA YAPMA (kritik):
- Onaylı bilgide geçmeyen hiçbir SAYI yazma (gün, süre, fiyat, yüzde, telefon, beden ölçüsü). Sayı uydurursan cevabın müşteriye hiç gösterilmez.
- Stok taahhüdü verme ("stokta var/yok" deme) — stok bilgisini yalnız ürün kartları gösterir.
- Fiyat rakamı uydurma — fiyat yalnız ürün kartlarında görünür.
- Sipariş, ödeme, iade durumu hakkında İDDİADA BULUNMA — action=need_order_info kullan, sistem doğrulayıp cevaplar.
- Aksiyon vaadi verme ("işleme aldım", "ilettim", "iptal ettim" deme) — sen işlem yapamazsın.
- Telefon, e-posta, adres, kupon kodu uydurma.
- Kesin teslim tarihi verme.
- MÜŞTERİNİN KENDİ DURUMUNU DEĞERLENDİRME. Tarih/gün/süre hesabı YAPMA, "süreniz dolmuş / hâlâ hakkınız var / uygunsunuz / uygun değilsiniz" gibi hüküm verme. Yalnızca kuralı söyle ("teslim tarihinden itibaren X gün içinde iade edebilirsiniz") ve değerlendirmeyi sisteme bırak. Müşteri kendi durumunu soruyorsa kuralı söyleyip iade sayfasına yönlendir; ısrar ederse action=handoff.
- Bir talebin kabul/ret edileceğine dair söz verme veya reddetme.
- Mağazanın satmadığı bir markayı (Nike, Adidas vb.) sorarlarsa o markayı satmadığımızı söyle, benzer ürün önerebilirsin.
- Müşteri kızgın, şikâyetçi veya mağdursa tartışma: kısaca üzüntünü belirt ve action=handoff ver.

AKSİYONLAR — cevabın SADECE aşağıdaki JSON olsun, başka hiçbir şey yazma:
{"reply": "müşteriye gösterilecek metin", "topic": "sohbet|magaza_bilgi|urun|siparis|alakasiz|diger", "action": "none|show_products|need_order_info|handoff", "search_query": "", "confidence": 0.0-1.0}

topic nasıl seçilir:
- sohbet: selamlama, teşekkür, veda, "nasılsın" — mağaza bilgisi içermez.
- magaza_bilgi: kargo, iade, değişim, beden/numara, ürün özelliği, ödeme, garanti, kampanya gibi mağaza kuralları. Cevabın ONAYLI MAĞAZA BİLGİSİ'ne dayanmak ZORUNDA.
- urun: müşteri ürün arıyor/öneri istiyor VEYA sohbette hâlihazırda gösterilen ürün kartları hakkında soru soruyor. YENİ/başka ürün isteniyorsa action=show_products ver ve search_query'e kısa Türkçe arama sorgusu yaz (örn "siyah deri bot 42"); reply kısa bir sunuş cümlesi olsun, ürünleri sistem kart olarak ekler. Gösterilen kartlarla ilgili bir soruysa (hangisi daha ucuz, kaç para, ikisi arasındaki fark) action=none ver ve cevabı kart listesinden yaz. Her iki durumda da topic=urun'dur — bu soruları topic=diger yapıp devretme.
- siparis: müşteri KENDİ siparişini/kargosunu/iadesinin durumunu soruyor → action=need_order_info.
- alakasiz: mağazayla HİÇ ilgisi olmayan konu — hava durumu, siyaset, spor, tarif, genel kültür, kod yazma, "sen kimsin", şaka. reply'de kibarca mağaza konularına dön, action=none, topic=alakasiz. Bunu temsilciye AKTARMA; temsilcinin de söyleyecek bir şeyi yok. Tereddüt edersen: soru ürün/sipariş/kargo/iade/ödeme ile ilgili DEĞİLSE alakasiz'dir.
- diger: mağazayla ilgili ama yukarıdakilere girmeyen veya cevaplayamadığın her şey; şikâyet, mağduriyet, özel talep, iş birliği, toptan alım → action=handoff.

ÜRÜN ÖNERİSİ NASIL YAPILIR (bu mağazanın en değerli işi):
- Müşteri ne istediğini söylediyse ÜST ÜSTE SORU SORMA. Elindeki bilgiyle hemen action=show_products ver; eksik kalan detayı kartları gösterdikten SONRA tek bir cümleyle sor.
- search_query'yi müşterinin SÖYLEDİĞİ tüm kısıtlarla kur: tür + renk + beden/numara + kime (kadın/erkek/çocuk). Örnek: "44 numara giyiyorum, gri spor ayakkabı" → search_query: "gri spor ayakkabı 44 numara".
- Müşteri daha önce bir tercih söylediyse (beden, renk, kime) SONRAKİ aramalarda da onu search_query'ye YAZMAYA DEVAM ET; müşteri açıkça değiştirmedikçe tercih geçerlidir.
- Müşteri kararsızsa ("ne alsam", "bilmiyorum", "ne önerirsin") tarz sormakla yetinme; action=show_products ver, search_query'ye elindeki en genel kısıtı yaz. Sistem sıralamayı en çok tercih edilen ürünlerden başlatır.
- Ürünleri ASLA sen sıralama/sayma ve "en çok satan bu" deme — kartları ve sırayı sistem üretir.

action=handoff verirken reply'ı BOŞ BIRAK. Tek istisna: müşteri gerçekten mağdur/öfkeliyse (ürün gelmedi, yanlış ürün, hasarlı, gecikme) tek kısa bir üzüntü cümlesi yazabilirsin. Nötr taleplerde (toptan alım, iş birliği, bilgi isteği) hiçbir şey yazma. Müşteriye telefon numarası verme, "iletişime geçin" veya "temsilcimize ulaşın" deme — devir cümlesini sistem kendisi ekler, yoksa mesaj tekrara düşer.

confidence: cevabının onaylı bilgide birebir karşılığı varsa 0.9+, kısmen varsa 0.5-0.8, yoksa 0.2 ver. Şişirme.
PROMPT;


        // Çelişkide TAHMİN ETME. Onaylı kayıtlar birbirini tutmuyorsa hangisinin
        // doğru olduğuna bot karar veremez — insana devret (4 Eyl kararı).
        $prompt .= "\n\nÇELİŞKİ KURALI: İki onaylı kayıt AYNI soruya birbirini tutmayan cevap veriyorsa (örn. farklı gün sayısı, biri var diğeri yok diyorsa) hangisinin doğru olduğuna KARAR VERME; cevap verme, action=handoff ver. Ancak FARKLI DURUMLARI anlatan kayıtlar çelişki DEĞİLDİR (örn. site siparişi ile pazaryeri siparişinin kuralları ayrıdır) — bu durumda müşterinin durumuna uyan kaydı kullan.";

        if ($settings['extra_instructions'] !== '') {
            $prompt .= "\n\nMAĞAZA ÖZEL TALİMATLARI:\n" . $settings['extra_instructions'];
        }

        // Katı kurallar en sonda ve en vurgulu: mağazanın kırmızı çizgileri
        if ($settings['strict_rules'] !== '') {
            $prompt .= "\n\nMAĞAZANIN KATI KURALLARI — bu kurallara HER CEVAPTA İSTİSNASIZ uy; yukarıdaki genel kurallarla çelişirse BU kurallar geçerlidir:\n" . $settings['strict_rules'];
        }

        // Dinamik parça: mesaja göre değişen onaylı bilgi (cache dışı, en sonda)
        $dynamic = $grounding !== null
            ? "ONAYLI MAĞAZA BİLGİSİ (yalnızca buna dayanarak cevap ver; sorunun cevabı burada yoksa action=handoff):\n" . $grounding['context']
            : "ONAYLI MAĞAZA BİLGİSİ: (bu soru için eşleşen onaylı kayıt YOK)\n"
                . 'Mağaza bilgisi gerektiren bir soruysa cevaplama, action=handoff ver.';

        if ($prefs !== []) {
            $dynamic .= "\n\n" . $this->prefsBlock($prefs);
        }

        if ($shownProducts !== []) {
            $dynamic .= "\n\n" . $this->shownProductsBlock($shownProducts);
        }

        return [$prompt, $dynamic];
    }

    /**
     * Sohbette ŞU AN ekranda duran ürün kartlarının modele verilen özeti.
     *
     * Neden: kartları sistem basıyor, model içeriklerini görmüyordu; "en uygun
     * fiyatlısı hangisi" sorusuna "hangi üründen bahsediyorsunuz" diye cevap
     * verip konuşmayı öldürüyordu. Bu blok DİNAMİK parçada durur (cache
     * breakpoint'inden sonra) — statik prefix bozulmaz.
     *
     * @param  array<int, array<string, mixed>>  $products
     */
    /**
     * Müşterinin sohbet boyunca söylediği tercihler — modele hatırlatılır ki
     * aynı soruyu ikinci kez sormasın ve search_query'ye kısıtı yazmayı
     * unutmasın. DİNAMİK parçada durur (cache prefix'i bozulmaz).
     *
     * @param  array<string, string>  $prefs
     */
    protected function prefsBlock(array $prefs): string
    {
        $labels = ['beden' => 'Beden/numara', 'renk' => 'Renk'];
        $lines = [];
        foreach ($prefs as $k => $v) {
            $lines[] = '- ' . ($labels[$k] ?? ucfirst((string) $k)) . ': ' . $v;
        }

        return "MÜŞTERİNİN DAHA ÖNCE SÖYLEDİĞİ TERCİHLER (tekrar SORMA, aramalarda KULLAN):\n"
            . implode("\n", $lines)
            . "\nBu tercihleri her yeni search_query'ye de yaz; müşteri açıkça değiştirmedikçe geçerlidirler.";
    }

    protected function shownProductsBlock(array $products): string
    {
        $lines = [];
        foreach ($products as $i => $p) {
            $price = $p['discounted_price'] ?? $p['price'] ?? null;
            $label = $price !== null ? number_format((float) $price, 2, ',', '.') . ' TL' : 'fiyat yok';
            if (! empty($p['discounted_price']) && ! empty($p['price']) && (float) $p['discounted_price'] < (float) $p['price']) {
                $label .= ' (liste ' . number_format((float) $p['price'], 2, ',', '.') . ' TL)';
            }
            $lines[] = ($i + 1) . '. ' . $p['name'] . ' — ' . $label;
        }

        return "ŞU AN SOHBETTE GÖSTERİLEN ÜRÜN KARTLARI (müşteri bunları ekranda görüyor):\n"
            . implode("\n", $lines)
            . "\nMüşterinin bundan sonraki mesajı bu listeyle ilgiliyse topic=urun ver — topic=diger VERME, "
            . "bu konuyu cevaplayabiliyorsun.\n"
            . "- Liste hakkında soru (hangisi daha ucuz, kaç para, ikincisi hangisi, aradaki fark): "
            . "action=none ver ve cevabı yukarıdaki listeden birebir yaz; fiyatları buradan aktar, uydurma.\n"
            . "- Müşteri BAŞKA/farklı ürün istiyorsa (daha ucuzu, başka renk, bunları beğenmedim, "
            . "şu fiyatın altı): action=show_products ver ve search_query'ye yeni aramayı yaz.\n"
            . "Bu listede olmayan ürün, renk, beden veya stok hakkında İDDİADA BULUNMA.";
    }


    /**
     * Ürün kartlarını panel için sadeleştirir (widget'ta müşterinin gördüğü alanlar:
     * görsel + ad + fiyat + indirimli fiyat + link). Personel "bot ne önerdi"
     * sorusunun cevabını metin olarak değil, GÖRSELLİ kart olarak görsün diye
     * hem konuşma transkriptinde hem devir sonrası Mesajlar panelinde kullanılır.
     *
     * @param  array<int, array>  $products  chat_messages.payload['products']
     * @return array<int, array{name:string,url:string,image:string|null,price:mixed,discounted_price:mixed,in_stock:bool}>
     */
    public static function chatProductCards(array $products, int $limit = 4): array
    {
        return array_values(array_map(fn ($p) => [
            'name' => (string) ($p['name'] ?? ''),
            'url' => (string) ($p['url'] ?? ''),
            'image' => $p['image'] ?? ($p['images'][0] ?? null),
            'price' => $p['price'] ?? null,
            'discounted_price' => $p['discounted_price'] ?? null,
            'in_stock' => (bool) ($p['in_stock'] ?? true),
        ], array_slice($products, 0, $limit)));
    }

    /**
     * Ekranda duran kartlar hakkındaki fiyat/sıra sorusunu LLM'siz cevaplar.
     *
     * Neden deterministik: bu soru ("en uygun fiyatlısı hangisi") 4 Eyl'de
     * konuşmayı öldüren kusurdu ve model prompt düzeltmesinden sonra bile
     * turların üçte birinde topic=diger deyip devrediyor. Veri elimizde;
     * tahmine gerek yok.
     *
     * @param  array<int, array<string, mixed>>  $products
     */
    protected function answerFromShownProducts(string $message, array $products): ?string
    {
        $products = array_values(array_filter($products, fn ($p) => ($p['discounted_price'] ?? $p['price'] ?? null) !== null));
        if ($products === []) {
            return null;
        }

        $m = mb_strtolower(trim($message), 'UTF-8');
        $priceOf = fn (array $p) => (float) ($p['discounted_price'] ?? $p['price']);
        $fmt = fn (array $p) => number_format($priceOf($p), 2, ',', '.') . ' TL';

        // "en ucuzu / en uygun fiyatlısı / en pahalısı"
        $cheap = (bool) preg_match('/en\\s*(ucuz|uygun|hesapl[ıi]|d[üu]ş[üu]k)/u', $m);
        $expensive = (bool) preg_match('/en\\s*(pahal[ıi]|y[üu]ksek)/u', $m);
        if ($cheap || $expensive) {
            $sorted = $products;
            usort($sorted, fn ($a, $b) => $priceOf($a) <=> $priceOf($b));
            $pick = $cheap ? $sorted[0] : end($sorted);

            return ($cheap ? 'Listedeki en uygun fiyatlı ürün: ' : 'Listedeki en yüksek fiyatlı ürün: ')
                . $pick['name'] . ' — ' . $fmt($pick) . '.';
        }

        // "ikincisi kaç para", "3. ürün ne kadar", "ilki kaç TL"
        if (! preg_match('/(ka[çc]\\s*para|ne\\s*kadar|fiyat[ıi]?\\s*ne|ka[çc]\\s*(tl|lira))/u', $m)) {
            return null;
        }

        $ordinals = ['ilk' => 1, 'birinci' => 1, 'ikinci' => 2, 'üçüncü' => 3, 'ucuncu' => 3, 'dördüncü' => 4, 'dorduncu' => 4];
        $index = null;
        foreach ($ordinals as $word => $n) {
            if (str_contains($m, $word)) {
                $index = $n;
                break;
            }
        }
        if ($index === null && preg_match('/\\b([1-4])\\s*\\.?\\s*(?:[üu]r[üu]n|s[ıi]radaki|si|nci|inci)?/u', $m, $hit)) {
            $index = (int) $hit[1];
        }
        if ($index === null) {
            return null;
        }

        if (! isset($products[$index - 1])) {
            return 'Şu an listede ' . count($products) . ' ürün görünüyor, ' . $index . '. sırada bir ürün yok.';
        }

        $pick = $products[$index - 1];

        return $index . '. sıradaki ürün ' . $pick['name'] . ' — ' . $fmt($pick) . '.';
    }

    /** Mağaza şu an mesai içinde mi (tenant ayarına göre)? */
    protected function isOpenNow(int $tenantId): bool
    {
        $settings = TenantChatSettings::get($tenantId);

        return ! $settings['hours_enabled'] || ChatBusinessHours::isOpen(
            $settings['hours'],
            CarbonImmutable::now(ChatBusinessHours::TZ)
        );
    }

    /** @return array<int, array{role: string, content: string}> */
    protected function history(ChatSession $session): array
    {
        return $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('id')
            ->limit((int) config('chat.history_limit', 12))
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => [
                'role' => $m->role === 'user' ? 'user' : 'assistant',
                'content' => $m->content,
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // Yardımcılar
    // ------------------------------------------------------------------

    protected function store(ChatSession $session, string $role, string $content, array $payload = []): ChatMessage
    {
        return ChatMessage::withoutGlobalScopes()->create([
            'tenant_id' => $session->tenant_id,
            'chat_session_id' => $session->id,
            'role' => $role,
            'content' => $content,
            'payload' => $payload ?: null,
        ]);
    }

    /** Assistant mesajını kaydeder ve widget'a dönecek diziyi üretir. */
    protected function assistant(ChatSession $session, string $content, array $payload = []): array
    {
        $msg = $this->store($session, 'assistant', $content, $payload);

        return [
            'id' => $msg->id,
            'role' => 'assistant',
            'content' => $content,
            'products' => $payload['products'] ?? [],
            'quick_replies' => $payload['quick_replies'] ?? [],
            'search_id' => $payload['search_id'] ?? null,
            'created_at' => $msg->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array>  $messages
     *
     * Dahili 'system' kayıtları (örn. handoff işareti) widget'a GÖNDERİLMEZ;
     * aksi halde sohbette "handoff" yazan bir balon görünür.
     */
    protected function respond(ChatSession $session, array $messages): array
    {
        $out = array_values(array_filter(
            $messages,
            fn ($m) => is_array($m) && ($m['role'] ?? '') !== 'system'
        ));

        return [
            'messages' => $out,
            'status' => $session->fresh()->status,
        ];
    }

    protected function extractOrderNo(string $message): ?string
    {
        // TB4994315196 gibi harf+rakam veya 8+ haneli sayı (telefon paterni hariç)
        if (preg_match('/\b([A-Za-z]{1,4}-?\d{6,})\b/u', $message, $m)) {
            return strtoupper(str_replace('-', '', $m[1]));
        }
        if (preg_match_all('/\b(\d{8,})\b/', $message, $mm)) {
            foreach ($mm[1] as $candidate) {
                // Telefon gibi görünenleri ele (0/90 ile başlayan 10-12 hane)
                if (preg_match('/^(90\d{10}|0\d{10}|5\d{9})$/', $candidate)) {
                    continue;
                }

                return $candidate;
            }
        }

        return null;
    }

    protected function extractEmail(string $message): ?string
    {
        if (preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/u', $message, $m)) {
            return mb_strtolower(rtrim($m[0], '.'));
        }

        return null;
    }

    /**
     * ⚠️ Lookbehind HARFİ de eler (`(?<![A-Za-z0-9])`): "TB5446312853" sipariş
     * numarasının içindeki 5446312853, telefon paternine birebir uyuyordu
     * (5 ile başlayan 10 hane). Sonuç: müşteri yalnız sipariş numarasını
     * yazdığında bot onu "sipariş no + telefon" sanıp doğrulamayı çalıştırıyor
     * ve "Bu bilgiler birbiriyle uyuşmadı" diyordu — oysa müşteri daha hiç
     * iletişim bilgisi vermemişti (8 Eyl sohbet #718, #720, #721).
     */
    protected function extractPhone(string $message): ?string
    {
        if (preg_match('/(?<![A-Za-z0-9])(\+?90|0)?[\s(-]*5\d{2}[\s)-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}(?!\d)/', $message, $m)) {
            return $this->normalizePhone($m[0]);
        }

        return null;
    }

    protected function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) < 10) {
            return null;
        }

        return substr($digits, -10); // 5XXXXXXXXX
    }
}
