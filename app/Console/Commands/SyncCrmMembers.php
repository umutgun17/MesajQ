<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Customer;
use App\Services\TicimaxService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Support\TurkishText;

class SyncCrmMembers extends Command
{
    protected $signature = 'crm:sync-members
                            {--limit=100 : Sayfa basi kayit sayisi}
                            {--pages=0 : Max sayfa (0=hepsi)}
                            {--channel= : Belirli kanal ID}
                            {--full : Tum uyeleri bastan cek}';

    protected $description = 'E-ticaret platformlarindan uyeleri senkronize eder';

    protected int $totalNew = 0;
    protected int $totalUpdated = 0;

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
            $this->info("Kanal #{$channel->id} ({$channel->type}, Tenant #{$channel->tenant_id}) isleniyor...");

            $this->syncMembers($channel);

            $freshSettings = $channel->fresh()->settings ?? [];
            $freshSettings['members_synced_at'] = now()->toIso8601String();
            $channel->update(['settings' => $freshSettings]);
        }

        $this->info("Tamamlandi: {$this->totalNew} yeni, {$this->totalUpdated} guncellendi");
        Log::info('SyncCrmMembers: completed', [
            'new' => $this->totalNew,
            'updated' => $this->totalUpdated,
        ]);

        return 0;
    }

    protected function syncMembers(Channel $channel): void
    {
        if (! str_starts_with($channel->type, 'ticimax')) {
            $this->warn("  Kanal #{$channel->id}: Desteklenmeyen platform tipi ({$channel->type})");

            return;
        }

        $service = TicimaxService::fromChannel($channel);

        if (! $service->isAvailable()) {
            $this->warn("  Kanal #{$channel->id}: Ticimax servisi kullanilamiyor");

            return;
        }

        $limit = (int) $this->option('limit');
        $maxPages = (int) $this->option('pages');
        $isFull = $this->option('full');

        $filters = [];

        // Incremental sync: fetch members updated since last sync minus 1 day buffer
        $lastSyncedAt = $channel->settings['members_synced_at'] ?? $channel->last_synced_at;
        if (! $isFull && $lastSyncedAt) {
            $ts = $lastSyncedAt instanceof \Carbon\Carbon ? $lastSyncedAt : \Carbon\Carbon::parse($lastSyncedAt);
            $filters['DuzenlemeTarihi1'] = $ts->subDay()->format('Y-m-d\TH:i:s');
        }

        $page = 1;
        $processed = 0;

        while (true) {
            $result = $service->getMembers($filters, $page, $limit);

            if (! $result['success']) {
                $this->error("  Sayfa {$page}: API hatasi — " . ($result['error'] ?? 'Bilinmeyen hata'));
                break;
            }

            $members = $result['data']['SelectUyelerResult']['Uye'] ?? [];

            // Single result comes as associative array, not list
            if (! empty($members) && isset($members['ID'])) {
                $members = [$members];
            }

            if (empty($members)) {
                if ($page === 1) {
                    $this->info('  Hic uye bulunamadi.');
                }
                break;
            }

            foreach ($members as $member) {
                $this->syncMember($member, $channel);
                $processed++;
            }

            $this->info("  Sayfa {$page}: " . count($members) . " uye islendi (toplam: {$processed})");

            $page++;

            if ($maxPages > 0 && ($page - 1) >= $maxPages) {
                break;
            }

            if (count($members) < $limit) {
                break;
            }

            usleep(300000); // 0.3s rate limit
        }
    }

    protected function syncMember(array $data, Channel $channel): void
    {
        $platformId = (string) ($data['ID'] ?? '');
        if (empty($platformId) || $platformId === '0') {
            return;
        }

        $mapped = [
            'name' => TurkishText::fix(trim($data['Isim'] ?? '')),
            'surname' => TurkishText::fix(trim($data['Soyisim'] ?? '')) ?: null,
            'email' => ! empty($data['Mail']) ? strtolower(trim($data['Mail'])) : null,
            'phone' => $this->normalizePhone($data['CepTelefonu'] ?? null),
            'gender' => isset($data['CinsiyetID']) ? (int) $data['CinsiyetID'] : null,
            'birth_date' => $this->parseDate($data['DogumTarihi'] ?? null),
            'city' => trim($data['Il'] ?? '') ?: null,
            'district' => trim($data['Ilce'] ?? '') ?: null,
            'member_type' => trim($data['UyeTuru'] ?? '') ?: null,
            'is_active' => (bool) ($data['Aktif'] ?? true),
            'mail_consent' => (bool) ($data['MailIzin'] ?? false),
            'sms_consent' => (bool) ($data['SmsIzin'] ?? false),
            'loyalty_points' => (float) ($data['ParaPuan'] ?? 0),
            'platform_created_at' => $this->parseDate($data['UyelikTarihi'] ?? null),
            'last_login_at' => $this->parseDate($data['SonGirisTarihi'] ?? null),
            'platform_customer_id' => $platformId,
            'platform_type' => 'ticimax',
        ];

        // Fallback for empty names
        if (empty($mapped['name'])) {
            $mapped['name'] = $mapped['email'] ?? ('Uye #' . $platformId);
        }

        $existing = Customer::withoutGlobalScopes()
            ->where('tenant_id', $channel->tenant_id)
            ->where('channel_id', $channel->id)
            ->where('platform_customer_id', $platformId)
            ->first();

        if ($existing) {
            $existing->update($mapped);
            $this->totalUpdated++;
        } else {
            $mapped['tenant_id'] = $channel->tenant_id;
            $mapped['channel_id'] = $channel->id;
            Customer::withoutGlobalScopes()->create($mapped);
            $this->totalNew++;
        }
    }

    protected function normalizePhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Remove non-numeric characters except leading +
        $phone = trim($phone);
        $clean = preg_replace('/[^0-9+]/', '', $phone);

        return ! empty($clean) ? $clean : null;
    }

    protected function parseDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            $parsed = Carbon::parse($date);

            // Ticimax returns "0001-01-01T00:00:00" for empty dates
            if ($parsed->year < 1900) {
                return null;
            }

            return $parsed->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
