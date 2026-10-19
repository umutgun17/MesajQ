<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Message;
use App\Services\MetaService;
use Illuminate\Console\Command;

class RetryFailedMetaMessages extends Command
{
    protected $signature = 'meta:retry-failed
        {--dry-run : Show what would be sent without actually sending}
        {--delay=2 : Delay in seconds between each message}';

    protected $description = 'Retry sending failed Meta (FB/IG) DM messages that have no platform_message_id';

    public function handle(MetaService $metaService): int
    {
        $dryRun = $this->option('dry-run');
        $delay = (int) $this->option('delay');

        $channelIds = Channel::withoutGlobalScopes()
            ->whereIn('type', ['instagram-message', 'facebook-message'])
            ->pluck('id');

        $sevenDaysAgo = now()->subDays(7);

        $messages = Message::withoutGlobalScopes()
            ->whereHas('conversation', function ($q) use ($channelIds) {
                $q->withoutGlobalScopes()->whereIn('channel_id', $channelIds);
            })
            ->where('sender_type', 'agent')
            ->where(function ($q) {
                $q->whereNull('platform_message_id')->orWhere('platform_message_id', '');
            })
            ->where('created_at', '>=', $sevenDaysAgo)
            ->with(['conversation' => function ($q) {
                $q->withoutGlobalScopes()->with(['customer', 'channel']);
            }])
            ->orderBy('created_at')
            ->get();

        // Filter out test/fake platform IDs
        $messages = $messages->filter(function ($msg) {
            $platformId = $msg->conversation->customer->platform_id ?? '';

            return $platformId && ! str_starts_with($platformId, 'IG-') && strlen($platformId) > 5;
        });

        if ($messages->isEmpty()) {
            $this->info('No failed messages to retry.');

            return self::SUCCESS;
        }

        $this->info("Found {$messages->count()} failed messages to retry.");

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($messages as $msg) {
            $conv = $msg->conversation;
            $customer = $conv->customer;
            $channel = $conv->channel;
            $channelType = $channel->type;
            $recipientId = $customer->platform_id;
            $name = $customer->name ?? '?';
            $preview = mb_substr($msg->content, 0, 50);

            if ($dryRun) {
                $this->line("  [DRY-RUN] ID:{$msg->id} | {$channelType} | {$name} | {$preview}");

                continue;
            }

            // Get page access token
            $credentials = $channel->credentials ?? [];
            $pageAccessToken = $metaService->getPageAccessToken($credentials);

            if (! $pageAccessToken) {
                $this->warn("  SKIP ID:{$msg->id} | No access token for channel {$channel->id}");
                $skipped++;

                continue;
            }

            // Check message length (Instagram has 1000 char limit)
            if ($channelType === 'instagram-message' && mb_strlen($msg->content) > 1000) {
                $this->warn("  SKIP ID:{$msg->id} | {$name} | Message too long (" . mb_strlen($msg->content) . " chars)");
                $skipped++;

                continue;
            }

            $this->line("  Sending ID:{$msg->id} | {$channelType} | {$name} | {$preview}");

            $result = $metaService->sendMessage(
                $recipientId,
                $msg->content,
                $pageAccessToken,
                $channelType,
            );

            if ($result['success'] && isset($result['message_id'])) {
                $updateData = ['platform_message_id' => $result['message_id']];

                if (! empty($result['used_human_agent'])) {
                    $metadata = $msg->metadata ?? [];
                    $metadata['used_human_agent'] = true;
                    $metadata['retried_at'] = now()->toIso8601String();
                    $updateData['metadata'] = $metadata;
                } else {
                    $metadata = $msg->metadata ?? [];
                    $metadata['retried_at'] = now()->toIso8601String();
                    $updateData['metadata'] = $metadata;
                }

                $msg->update($updateData);
                $tag = ! empty($result['used_human_agent']) ? ' [HUMAN_AGENT]' : '';
                $this->info("    OK → {$result['message_id']}{$tag}");
                $sent++;
            } else {
                $error = $result['error']['error']['message'] ?? json_encode($result['error'] ?? []);
                $this->error("    FAIL → {$error}");
                $failed++;
            }

            if ($delay > 0) {
                sleep($delay);
            }
        }

        $this->newLine();
        $this->info("Done. Sent: {$sent}, Failed: {$failed}, Skipped: {$skipped}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
