<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/** MesajQ planları (KDV hariç, TL/ay). Değiştirince `php artisan db:seed --class=PlanSeeder` (upsert). */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $base = ['WhatsApp, Instagram, Messenger, e-posta, SMS', 'Trendyol & Hepsiburada soru kutuları', 'Sınırsız temsilci', 'Hazır cevap kütüphanesi', 'Şablonla konuşma başlatma', 'E-posta arşivi + mesaj analizi', 'Sipariş/müşteri bağlamı (Ticimax, ikas)'];
        $ai = ['AI cevap önerileri', 'Bilgi bankasından otomatik yanıt', 'Web chat → temsilci devri', 'AI konuşma özetleri'];

        $plans = [
            ['code' => 'mq-standart', 'name' => 'Standart', 'description' => 'Tüm kanallar tek gelen kutusunda, ekibiniz hazır cevaplarla yanıtlar.', 'products' => ['inbox', 'store'], 'limits' => ['ai_messages' => 0], 'features' => $base, 'price_monthly' => 12500, 'sort_order' => 1],
            ['code' => 'mq-ai-2500', 'name' => 'AI Asistan 2.500', 'description' => 'Aylık 2.500 AI destekli mesaj.', 'products' => ['inbox', 'chatbot', 'store'], 'limits' => ['ai_messages' => 2500], 'features' => array_merge(['Standart paketteki her şey'], $ai), 'price_monthly' => 14500, 'sort_order' => 2, 'highlight' => true, 'is_trial_default' => true],
            ['code' => 'mq-ai-10000', 'name' => 'AI Asistan 10.000', 'description' => 'Aylık 10.000 AI destekli mesaj.', 'products' => ['inbox', 'chatbot', 'store'], 'limits' => ['ai_messages' => 10000], 'features' => array_merge(['Standart paketteki her şey'], $ai, ['Öncelikli destek']), 'price_monthly' => 20000, 'sort_order' => 3],
            ['code' => 'mq-ai-25000', 'name' => 'AI Asistan 25.000', 'description' => 'Aylık 25.000 AI destekli mesaj.', 'products' => ['inbox', 'chatbot', 'store', 'call'], 'limits' => ['ai_messages' => 25000], 'features' => array_merge(['Standart paketteki her şey'], $ai, ['Çağrı analizi (Netgsm)', 'Öncelikli destek']), 'price_monthly' => 31500, 'sort_order' => 4],
        ];

        foreach ($plans as $p) {
            Plan::updateOrCreate(['code' => $p['code']], $p + ['family' => 'mesajq', 'currency' => 'TRY', 'trial_days' => 14, 'is_public' => true]);
        }
    }
}
