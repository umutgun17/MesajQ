<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Customer;
use App\Services\MetaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class UpdateInstagramUsernames extends Command
{
    protected $signature = 'meta:update-ig-usernames
                            {--channel= : Channel ID (default: all active IG channels)}
                            {--delay=1 : Delay between API calls in seconds}';

    protected $description = 'Mevcut Instagram müşterilerinin username bilgilerini Meta Graph API üzerinden günceller';

    public function handle(MetaService $metaService): int
    {
        $channelId = $this->option('channel');
        $delay = max(1, (int) $this->option('delay'));

        $channels = $channelId
            ? Channel::withoutGlobalScopes()->where('id', $channelId)->where('is_active', true)->get()
            : Channel::withoutGlobalScopes()
                ->whereIn('type', ['instagram-message', 'instagram-comment'])
                ->where('is_active', true)
                ->get();

        if ($channels->isEmpty()) {
            $this->error('Aktif Instagram kanalı bulunamadı.');
            return 1;
        }

        $totalUpdated = 0;
        $totalSkipped = 0;
        $totalFailed = 0;

        foreach ($channels as $channel) {
            $credentials = $channel->credentials ?? [];
            $settings = $channel->settings ?? [];
            $pageAccessToken = $metaService->getPageAccessToken($credentials);
            $igBusinessAccountId = $settings['page_id'] ?? null;

            if (! $pageAccessToken || ! $igBusinessAccountId) {
                $this->warn("Kanal #{$channel->id} ({$channel->type}): Token veya page_id eksik, atlanıyor.");
                continue;
            }

            // Get Instagram customers without username in metadata
            $customers = Customer::withoutGlobalScopes()
                ->where('platform_type', 'instagram')
                ->where('tenant_id', $channel->tenant_id)
                ->where(function ($q) {
                    $q->whereNull('metadata')
                        ->orWhereRaw("(metadata->>'instagram_username') IS NULL");
                })
                ->get();

            $this->info("Kanal #{$channel->id} ({$channel->type}): {$customers->count()} müşteri güncellenecek");

            $bar = $this->output->createProgressBar($customers->count());
            $bar->start();

            foreach ($customers as $customer) {
                try {
                    $response = Http::withToken($pageAccessToken)
                        ->timeout(5)
                        ->get("https://graph.facebook.com/v21.0/{$customer->platform_id}", [
                            'fields' => 'name,profile_pic,username',
                        ]);

                    if ($response->successful() && $response->json('username')) {
                        $metadata = $customer->metadata ?? [];
                        $metadata['instagram_username'] = $response->json('username');
                        $updateData = ['metadata' => $metadata];

                        if (in_array($customer->name, ['Meta User', 'Instagram User'])) {
                            $name = $response->json('name');
                            if ($name && ! in_array($name, ['Meta User', 'Instagram User'])) {
                                $updateData['name'] = $name;
                            }
                            $avatar = $response->json('profile_pic');
                            if ($avatar) {
                                $updateData['avatar'] = $avatar;
                            }
                        }

                        $customer->update($updateData);
                        $totalUpdated++;
                    } else {
                        $totalFailed++;
                    }
                } catch (\Throwable $e) {
                    $totalFailed++;
                }

                $bar->advance();
                usleep($delay * 500000);
            }

            $bar->finish();
            $this->newLine();
        }

        $totalSkipped = $totalFailed;
        $this->newLine();
        $this->info("Tamamlandı: {$totalUpdated} güncellendi, {$totalSkipped} username alınamadı.");

        return 0;
    }
}
