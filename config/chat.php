<?php

/**
 * AI Chatbot (site içi sohbet) ayarları — bkz docs/ai-chatbot.md.
 * Tenant'a özel ayarlar tenants.settings'ten gelir (TenantChatSettings);
 * buradakiler güvenli platform varsayılanları.
 */
return [
    // Sağlayıcı deneme sırası. Başarısız sağlayıcı kısa süre atlanır (dead cache),
    // örn. Anthropic kredisi bitince OpenAI otomatik devreye girer.
    'providers' => array_filter(array_map('trim', explode(',', env('CHAT_LLM_PROVIDERS', 'anthropic,openai,groq')))),

    // Chatbot modeli: Sonnet 5 ($2/$10 per Mtok) + prompt caching. Statik kural
    // bloğu (~1.800 token) cache'lenir → tekrar okumalar ~%90 ucuz; bu haliyle
    // mesaj başı maliyet Haiku 4.5 ile aynı seviyeye iner ama kalite çok üstün.
    // Daha da ucuzu isteniyorsa: CHAT_ANTHROPIC_MODEL=claude-haiku-4-5 (dikkat:
    // Haiku'nun cache eşiği 4.096 token, bizim prefix altında kalır → cache YOK).
    'anthropic_model' => env('CHAT_ANTHROPIC_MODEL', 'claude-sonnet-5'),
    'openai_model' => env('CHAT_OPENAI_MODEL', 'gpt-4o-mini'),
    // 4 Eyl: llama-3.3-70b-versatile Groq'ta kaldırılmış (404) — yedek zincir
    // fiilen çalışmıyordu. Hesapta mevcut modellerden biriyle değiştirildi.
    'groq_model' => env('CHAT_GROQ_MODEL', 'openai/gpt-oss-120b'),

    'max_tokens' => (int) env('CHAT_MAX_TOKENS', 700),
    'timeout' => (int) env('CHAT_LLM_TIMEOUT', 25),
    'dead_provider_ttl' => 300, // saniye — başarısız sağlayıcıyı atlama süresi

    // Kötüye kullanım / maliyet frenleri (AiBudgetGuard'a ek olarak)
    'max_message_length' => 1000,
    'max_user_messages' => 40,   // oturum başına
    'history_limit' => 12,       // LLM'e giden geçmiş mesaj sayısı
    'products_limit' => 4,       // sohbette gösterilen ürün kartı sayısı
    'order_attempts' => 5,       // oturum başına sipariş doğrulama denemesi
    'low_confidence' => 0.4,     // (geçersiz) yerini tenant ayarı chat_min_confidence aldı
    'kb_snippets' => 3,          // knowledge_base'ten prompt'a giden kayıt sayısı
];
