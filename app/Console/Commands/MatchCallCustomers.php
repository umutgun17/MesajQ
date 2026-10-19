<?php

namespace App\Console\Commands;

use App\Models\Call;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MatchCallCustomers extends Command
{
    protected $signature = 'call:match-customers
        {--limit=0 : Kaç çağrı işlensin (0 = hepsi)}';

    protected $description = 'Mevcut çağrıları sipariş telefon numaralarıyla eşleştir';

    public function handle(): int
    {
        // Önce telefon → customer_id map'ini oluştur (siparişlerden)
        $this->info('Telefon → Müşteri eşleştirme tablosu oluşturuluyor...');

        $phoneMap = DB::table('orders')
            ->whereNotNull('customer_id')
            ->whereNotNull('shipping_address')
            ->selectRaw("DISTINCT ON (RIGHT(REGEXP_REPLACE(shipping_address->>'phone', '\\D', '', 'g'), 10))
                RIGHT(REGEXP_REPLACE(shipping_address->>'phone', '\\D', '', 'g'), 10) as phone_normalized,
                customer_id")
            ->whereRaw("LENGTH(REGEXP_REPLACE(shipping_address->>'phone', '\\D', '', 'g')) >= 10")
            ->orderByRaw("RIGHT(REGEXP_REPLACE(shipping_address->>'phone', '\\D', '', 'g'), 10)")
            ->orderByDesc('id')
            ->pluck('customer_id', 'phone_normalized');

        $this->info("Eşleştirme tablosu: {$phoneMap->count()} benzersiz telefon numarası");

        // Eşleştirilmemiş çağrıları al
        $query = Call::withoutGlobalScopes()
            ->whereNull('customer_id')
            ->whereNotNull('caller');

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $totalCalls = $query->count();
        $this->info("İşlenecek çağrı: {$totalCalls}");

        $matched = 0;
        $unmatched = 0;

        $query->chunkById(1000, function ($calls) use ($phoneMap, &$matched, &$unmatched) {
            $updates = [];

            foreach ($calls as $call) {
                $normalized = Call::normalizePhone($call->caller);

                if (strlen($normalized) >= 10 && isset($phoneMap[$normalized])) {
                    $updates[$call->id] = $phoneMap[$normalized];
                    $matched++;
                } else {
                    $unmatched++;
                }
            }

            // Batch update
            foreach ($updates as $callId => $customerId) {
                Call::withoutGlobalScopes()
                    ->where('id', $callId)
                    ->update(['customer_id' => $customerId]);
            }

            $this->output->write("\r  İşlenen: " . ($matched + $unmatched) . " | Eşleşen: {$matched} | Eşleşmeyen: {$unmatched}");
        });

        $this->newLine();
        $this->info("Tamamlandı: {$matched} eşleştirildi, {$unmatched} eşleştirilemedi.");

        return 0;
    }
}
