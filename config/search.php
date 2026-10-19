<?php

return [
    /**
     * Akıllı Arama (Smart Search) / Keşif & Öneri Motoru VARSAYILANLARI.
     *
     * Tenant bazlı değerler tenants.settings (jsonb) içinde tutulur:
     *   search_widget_enabled (bool), search_widget_domains (csv), search_* overrides.
     * Burası motor davranışının GÜVENLİ varsayılanları + sınırlarıdır (clamp). Kayıtlı
     * bozuk/eksik ayara körü körüne güvenilmez. Çark modülü deseni (config/wheel.php) izlenir.
     */

    'enabled' => env('SEARCH_ENABLED', true),

    // ── Aday getirme (retrieval) ──
    'retrieval' => [
        'candidate_limit'    => 200,  // ranking'e girmeden önceki aday havuzu
        'result_limit'       => 24,   // kullanıcıya dönen sonuç adedi
        'autocomplete_limit' => 8,
        'min_query_length'   => 2,
        'vector_enabled'     => true, // pgvector semantik (products.embedding)
        // Renk/beden aramasında yalnız ARANAN varyantı stokta olan ürünler listelenir
        // ("36" → 36'sı satılabilir olanlar). false → tükenenler de gelir (rozetli, sona).
        'require_variant_stock' => true,
        // Tükenen ürün (toplam stok 0) HİÇ listelenmez — arama satın almaya yönlendirsin.
        // false → tükenenler rozetli gelir, blok sonunda.
        'require_stock' => true,
        // Görseli olmayan ürün listelenmez (placeholder kart yerine hiç gösterme).
        'require_image' => true,
    ],

    // ── Sıralama (ranking) ağırlıkları ──
    'ranking' => [
        'weights' => [
            'semantic'    => 0.40, // vektör kosinüs benzerliği
            'lexical'     => 0.20, // pg_trgm word_similarity (isim içi kelime eşleşmesi)
            'performance' => 0.20, // products.performance_score (hazır sinyal)
            'ga4'         => 0.15, // product_ga4_cache (görüntüleme/atc/satın alma)
            'stock'       => 0.05, // stokta olan öne (renk/beden aramada ARANAN varyantın stoğu)
        ],
        'out_of_stock_penalty' => 0.50, // stoksuz ürünün nihai skoru * bu kat sayı
        // Serbest terimli sorgularda alaka eşiği: altındaki sonuçlar elenir →
        // hiç kalmazsa "0 sonuç" sayılır ve öneri (popüler ürün) gösterilir.
        // min_lexical, word_similarity ölçeğine göredir (tam kelime ≈1.0, typo ≈0.4-0.6).
        'min_semantic'         => 0.42,
        'min_lexical'          => 0.34,
    ],

    // ── Türkçe normalizasyon ──
    'turkish' => [
        'deascii'       => true, // tisort ~ tişört ~ t-shirt eşitle
        'lowercase_tr'  => true, // İ/ı, I/ı duyarlı küçültme
        // Sorgu ayrıştırmada tanınan attribute anahtarları (product_variants.attributes
        // ve products.specifications jsonb'den gerçek değerlerle doldurulur).
        'facet_keys'    => ['Renk', 'Beden', 'Cinsiyet', 'Materyal', 'Sezon', 'Numara'],
        // Renk eşanlamlı/varyant tohumları (agentic loop kataloğa göre genişletir).
        'color_synonyms' => [
            'kırmızı' => ['kirmizi', 'red', 'bordo', 'vişne'],
            'siyah'   => ['siyh', 'black'],
            'beyaz'   => ['beyz', 'white', 'ekru', 'krem'],
            'lacivert'=> ['laci', 'navy'],
            'mavi'    => ['blue'],
            'yeşil'   => ['yesil', 'green'],
            'kahve'   => ['kahverengi', 'brown', 'taba'],
        ],
        // Kategori eşanlamlıları (KATEGORİ eşleştirmesinde, kelime-ifade düzeyinde).
        // Katalog aynı ürün tipini iki adla anıyor: kategori "Sneaker" + adında
        // "Sneaker" olan ~200 ürün ile "… Spor Ayakkabı" kategorileri/adları (TBZER020,
        // TBA9060, TBKMN320 gibi en çok satanlar). "sneaker" yazan bu ikinci kümeyi hiç
        // görmüyordu. Çift yönlü yazılır; panel eşanlamlıları (search_synonyms
        // type=synonym, active) da aynı havuza katılır.
        'category_synonyms' => [
            'spor ayakkabı' => ['sneaker'],
            'sneaker'       => ['spor ayakkabı'],
            'kadın'         => ['bayan'],
        ],
        // Hedef kitle (audience) ipuçları: sorguda geçince kategori eşleşmesi o kitleye
        // DARALTILIR. "erkek bot" → "Erkek Çocuk Bot"u da kapsıyordu (kelime-altküme
        // eşleşmesi) ve çocuk botları önde çıkıyordu. Yetişkin (erkek/kadın) sorgusunda
        // çocuk kategorileri elenir; isim-OR genişletmesi de aynı kitleye bağlanır
        // (unisex ürünler yetişkin iki kitleye de girer). Çocuk ipucu her zaman baskın
        // ("erkek çocuk" = çocuk).
        // girl: "kız" tek başına da çocuk demektir ("kız bot" → Kız Çocuk Bot) ve çocuk
        // içinde alt-cinsiyet sayılır ("erkek çocuk" sorgusunda Kız Çocuk kategorisi elenir).
        // unisex: kitle daraltmaz, yalnız tip ifadesinden düşer ("unisex spor ayakkabı").
        'audience' => [
            'child'  => ['çocuk', 'bebek', 'kids'],
            'girl'   => ['kız'],
            'male'   => ['erkek'],
            'female' => ['kadın', 'bayan'],
            'unisex' => ['unisex'],
        ],
    ],

    // ── Sorgu anlama (LLM) ──
    'understanding' => [
        'llm_enabled'           => true,    // facet/intent çıkarımında LLM fallback
        'llm_provider'          => env('SEARCH_LLM_PROVIDER', 'claude'), // claude | openai
        'llm_only_when_ambiguous' => true,  // her sorguda değil; kural-bazlı çözülemezse
        'cache_ttl_minutes'     => 1440,    // aynı sorgunun çözümünü cache'le (redis)
    ],

    // ── Öneri (recommender) — search ile paylaşılan, omnichannel ──
    'recommendations' => [
        'enabled'              => true,
        'zero_result_fallback' => true, // 0 sonuçta boş bırakma, öner
        'skip_when_results_gte'=> 6,    // >=6 organik sonuç varsa öneri ekleme
        'copurchase_enabled'   => true, // order_items birlikte-alınan
        'similar_enabled'      => true, // embedding benzeri
        'popular_enabled'      => true, // GA4 popüler
        'limit'                => 12,
    ],

    // ── Agentic döngü eşikleri ──
    'agentic' => [
        'enabled'                 => true,
        'lookback_days'           => 7,
        'zero_result_min_count'   => 5,     // X kez 0-sonuç veren sorgu → aksiyon
        'typo_min_count'          => 5,
        'auto_apply_synonyms'     => false, // GÜVENLİ: önce öner, otomatik uygulama kapalı
        'notify_merchant'         => true,  // eksik/Kategorisiz ürünü merchant'a bildir
    ],

    // ── Sunum (widget kartları) ──
    'presentation' => [
        // Kart rozetinde gösterilecek products.metadata anahtarı (Ticimax özel alanından
        // sync ile gelir; ör. ozel_alan1 = "1 ALANA 1 BEDAVA"). Boş string = rozet kapalı.
        'badge_metadata_key' => 'ozel_alan1',
    ],

    // ── Satış atıfı (sunucu-taraflı; bkz. search:attribute-conversions) ──
    // Ticimax checkout SPA'sı tema script'i yüklemediğinden client-side conversion imkânsız;
    // atıf sunucuda IP+ürün+pencere eşleşmesiyle yapılır.
    'attribution' => [
        'window_hours' => 24,          // tıklama → sipariş azami süre
        'order_tz_offset_hours' => 3,  // orders.order_date TR yerel saat; search_* UTC (TR'de DST yok)
    ],

    // ── Event toplama ──
    'events' => [
        'log_queries'      => true,
        'log_clicks'       => true,
        'log_zero_results' => true,
        'session_cookie'   => 'yunbi_sid',
        'retention_days'   => 365,
    ],

    // ── Güvenli sınırlar (clamp) ──
    'limits' => [
        'query_max_length'   => ['min' => 1, 'max' => 120],
        'result_limit'       => ['min' => 1, 'max' => 100],
        'autocomplete_limit' => ['min' => 1, 'max' => 20],
        'candidate_limit'    => ['min' => 10, 'max' => 1000],
    ],
];
