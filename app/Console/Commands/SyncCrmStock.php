<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\TicimaxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncCrmStock extends Command
{
    protected $signature = 'crm:sync-stock
                            {--channel= : Belirli kanal ID}
                            {--minutes=10 : Son X dakikadaki degisiklikleri cek}';

    protected $description = 'E-ticaret platformlarindan stok ve fiyat bilgilerini senkronize eder';

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
            if (str_starts_with($channel->type, 'ticimax')) {
                $this->syncTicimaxStock($channel);
            }
        }

        return 0;
    }

    protected function syncTicimaxStock(Channel $channel): void
    {
        $service = TicimaxService::fromChannel($channel);

        if (! $service->isAvailable()) {
            $this->warn("Kanal #{$channel->id}: Ticimax servisi kullanilabilir degil");

            return;
        }

        $minutes = (int) $this->option('minutes');
        $since = now()->subMinutes($minutes)->format('Y-m-d\TH:i:s');

        $updatedVariants = 0;
        $updatedProducts = 0;
        $page = 1;
        $affectedProductIds = [];

        while (true) {
            $result = $service->getVariants(
                ['StokGuncellemeTarihiBas' => $since],
                $page,
                100
            );

            if (! $result['success']) {
                $this->warn("  API hatasi: " . ($result['error'] ?? 'bilinmeyen'));
                break;
            }

            $variants = $result['data']['SelectVaryasyonResult']['Varyasyon'] ?? [];

            if (empty($variants)) {
                break;
            }

            if (isset($variants['ID'])) {
                $variants = [$variants];
            }

            foreach ($variants as $v) {
                $platformVariantId = (string) ($v['ID'] ?? '');
                $platformProductId = (string) ($v['UrunKartiID'] ?? '');

                if (empty($platformVariantId)) {
                    continue;
                }

                $variant = ProductVariant::whereHas('product', function ($q) use ($channel) {
                    $q->withoutGlobalScopes()->where('channel_id', $channel->id);
                })->where('platform_variant_id', $platformVariantId)->first();

                if (! $variant) {
                    continue;
                }

                $newStock = (int) ($v['StokAdedi'] ?? 0);
                $newPrice = round((float) ($v['SatisFiyati'] ?? 0), 2);
                $newDiscounted = round((float) ($v['IndirimliFiyati'] ?? 0), 2);

                $updates = ['stock_quantity' => $newStock];
                if ($newPrice > 0) {
                    $updates['price'] = $newPrice;
                }
                $updates['discounted_price'] = $newDiscounted > 0 ? $newDiscounted : null;

                $variant->update($updates);
                $updatedVariants++;

                $affectedProductIds[$variant->product_id] = true;
            }

            if (count($variants) < 100) {
                break;
            }

            $page++;
            usleep(200000); // 200ms rate limit
        }

        // Recalculate product-level stock and price from variants
        if (! empty($affectedProductIds)) {
            foreach (array_keys($affectedProductIds) as $productId) {
                $product = Product::withoutGlobalScopes()->find($productId);
                if (! $product) {
                    continue;
                }

                $product->stock_quantity = $product->variants()->sum('stock_quantity');

                // Update price from first variant (lowest ID)
                $firstVariant = $product->variants()->orderBy('id')->first();
                if ($firstVariant) {
                    $product->price = $firstVariant->price;
                    $product->discounted_price = $firstVariant->discounted_price;
                }

                $product->save();

                $updatedProducts++;
            }
        }

        if ($updatedVariants > 0) {
            $this->info("Kanal #{$channel->id}: {$updatedVariants} varyant, {$updatedProducts} urun stok/fiyat guncellendi");
            Log::info('SyncCrmStock: completed', [
                'channel' => $channel->id,
                'variants' => $updatedVariants,
                'products' => $updatedProducts,
            ]);
        }
    }
}
