<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\Ai\ClaudeKeyPolicy;
use App\Services\ClaudeService;
use Illuminate\Console\Command;

class RegenerateAiSuggestions extends Command
{
    protected $signature = 'ai:regenerate-suggestions {--days=2 : Number of days to look back} {--force : Regenerate even if suggestion exists} {--dry-run : Only count, do not generate}';

    protected $description = 'Regenerate AI suggestions for open conversations with pending customer messages';

    public function handle(ClaudeService $claudeService): int
    {
        $days = (int) $this->option('days');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $since = now()->subDays($days)->startOfDay();

        $conversations = Conversation::withoutGlobalScopes()
            ->with(['messages', 'customer', 'channel'])
            ->where('status', 'open')
            ->where('updated_at', '>=', $since)
            ->get()
            ->filter(function ($conv) {
                $lastMsg = $conv->messages->sortByDesc('id')->first();

                return $lastMsg && $lastMsg->sender_type === 'customer';
            });

        if (! $force) {
            // Only those with fallback suggestions (confidence exactly 0.6) or no suggestion
            $conversations = $conversations->filter(function ($conv) {
                return ! $conv->ai_suggestion
                    || (float) $conv->ai_suggestion_confidence === 0.6;
            });
        }

        $total = $conversations->count();
        $this->info("Found {$total} conversations to regenerate (days={$days}, force=" . ($force ? 'yes' : 'no') . ')');

        if ($dryRun) {
            return 0;
        }

        $success = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($conversations as $conv) {
            try {
                $lastCustomerMsg = $conv->messages
                    ->where('sender_type', 'customer')
                    ->filter(fn ($m) => trim($m->content ?? '') !== '')
                    ->sortByDesc('id')
                    ->first();

                if (! $lastCustomerMsg) {
                    $bar->advance();
                    continue;
                }

                $history = $conv->messages
                    ->sortBy('created_at')
                    ->map(fn ($m) => [
                        'role' => $m->sender_type === 'customer' ? 'customer' : 'agent',
                        'content' => $m->content,
                    ])
                    ->values()
                    ->toArray();

                $tenantId = $conv->tenant_id;

                // Bakiye kapısı: gelen kutusu AI kapalı olan kiracıyı atla.
                if (! ClaudeKeyPolicy::allows($tenantId, 'inbox')) {
                    $bar->advance();
                    continue;
                }

                $tenant = $tenantId ? \App\Models\Tenant::find($tenantId) : null;
                $systemPromptOverride = $tenant?->settings['ai_system_prompt'] ?? null;
                $channelType = $conv->channel->type ?? null;

                $result = $claudeService->generateResponse(
                    $lastCustomerMsg->content,
                    $history,
                    [],
                    $channelType,
                    $tenantId,
                    $systemPromptOverride,
                    $conv->id
                );

                // Only save if we got a real API response (confidence != 0.6 fallback)
                if ((float) $result['confidence'] !== 0.6) {
                    $conv->update([
                        'ai_suggestion' => $result['content'],
                        'ai_suggestion_confidence' => $result['confidence'],
                    ]);
                    $success++;
                } else {
                    $failed++;
                    $this->newLine();
                    $this->warn("Conv #{$conv->id}: Still fallback, API may be failing");
                    // Stop early if API is down
                    if ($failed >= 3 && $success === 0) {
                        $this->error('API appears to be down. Stopping.');

                        return 1;
                    }
                }
            } catch (\Exception $e) {
                $failed++;
                $this->newLine();
                $this->error("Conv #{$conv->id}: {$e->getMessage()}");
            }

            $bar->advance();

            // Rate limit: small delay between requests
            usleep(500000); // 0.5s
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done! Success: {$success}, Failed: {$failed}, Total: {$total}");

        return 0;
    }
}
