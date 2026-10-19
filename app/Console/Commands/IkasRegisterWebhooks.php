<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\IkasService;
use Illuminate\Console\Command;

/**
 * ikas webhook kayıtları: favoriler (admin API'de liste sorgusu YOK —
 * tek kaynak webhook) + stok güncellemeleri ("gelince haber ver" için).
 */
class IkasRegisterWebhooks extends Command
{
    protected $signature = 'ikas:register-webhooks {--channel= : Belirli kanal ID} {--list : Sadece mevcut kayıtları göster}';

    protected $description = 'ikas webhook aboneliklerini kaydeder (favori + stok)';

    protected const SCOPES = [
        'store/customerFavoriteProducts/created',
        'store/customerFavoriteProducts/updated',
        'store/stock/updated',
    ];

    public function handle(): int
    {
        $query = Channel::withoutGlobalScopes()
            ->where('type', 'ikas-ecommerce')
            ->where('is_active', true);

        if ($channelId = $this->option('channel')) {
            $query->where('id', (int) $channelId);
        }

        foreach ($query->get() as $channel) {
            $service = IkasService::fromChannel($channel);
            if (! $service->isAvailable()) {
                $this->warn("Kanal #{$channel->id}: kimlik eksik, atlandı.");
                continue;
            }

            if ($this->option('list')) {
                $data = $service->graphql('query { listWebhook { id scope endpoint } }');
                foreach ($data['listWebhook'] ?? [] as $w) {
                    $this->line("  {$w['scope']} → {$w['endpoint']}");
                }
                continue;
            }

            $endpoint = rtrim(config('app.url'), '/') . '/api/webhook/ikas';
            $data = $service->graphql(
                'mutation($scopes: [String!]!, $endpoint: String!) {
                    saveWebhooks(input: { scopes: $scopes, endpoint: $endpoint }) { id scope endpoint }
                }',
                ['scopes' => self::SCOPES, 'endpoint' => $endpoint]
            );

            $saved = $data['saveWebhooks'] ?? [];
            $saved = isset($saved['id']) ? [$saved] : $saved;
            $this->info("Kanal #{$channel->id}: " . count($saved) . " webhook kaydı → {$endpoint}");
        }

        return self::SUCCESS;
    }
}
