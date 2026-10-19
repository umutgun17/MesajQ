<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\EmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * E-posta kanallarının GİDEN posta sağlığını kontrol eder (mail göndermez).
 *
 * NEDEN: Sunucudaki cPanel dış-SMTP kısıtlaması (`smtpmailgidonly`) 25/465/587
 * bağlantılarını yerel exim'e yönlendiriyor. Muafiyet kuralı silinirse
 * EmailService firmanın SMTP'sine bağlanamaz ve sessizce yerel sendmail'e
 * düşer; mail hub IP'sinden çıkar, firmanın SPF'inde o IP yoktur ve DKIM
 * imzası atılamaz → Gmail 550-5.7.26 / Outlook 550 5.7.1 ile reddeder.
 * 10 Ağu–4 Eyl 2026 arasında tam bu oldu ve 48 cevap müşteriye ulaşmadı;
 * 25 gün boyunca kimse fark etmedi. Bu komut günlük çalışır ve bozulduğu gün
 * laravel.log'a ERROR yazar.
 *
 * Muafiyet kalıcılığı: /var/cpanel/smtpmailgidonly/conf.yaml (cPanel'in kendi
 * uzantı noktası, uid 1017) + /usr/local/sbin/yunbi-smtp-exempt.sh (cron).
 */
class CheckEmailChannelsHealth extends Command
{
    protected $signature = 'email:health-check';

    protected $description = 'Aktif e-posta kanallarının SMTP gönderim yolunu test eder (mail göndermez)';

    public function handle(): int
    {
        $channels = Channel::withoutGlobalScopes()
            ->where('type', 'email')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($channels->isEmpty()) {
            $this->info('Aktif e-posta kanalı yok.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($channels as $channel) {
            $result = (new EmailService($channel))->testSmtpConnection();

            if ($result['ok']) {
                $this->info(sprintf(
                    'ch%d (tenant %d) %s → AUTH OK (%d ms)',
                    $channel->id,
                    $channel->tenant_id,
                    $result['host'],
                    $result['ms']
                ));

                continue;
            }

            $failed++;

            $this->error(sprintf(
                'ch%d (tenant %d) %s → BAŞARISIZ: %s',
                $channel->id,
                $channel->tenant_id,
                $result['host'] ?? '(host yok)',
                $result['error']
            ));

            Log::error('E-posta kanalı SMTP sağlık kontrolü BAŞARISIZ — cevaplar yerel sendmail\'e düşecek ve reddedilebilir', [
                'channel_id' => $channel->id,
                'tenant_id' => $channel->tenant_id,
                'smtp_host' => $result['host'],
                'error' => $result['error'],
                'kontrol' => 'iptables -t nat -L OUTPUT -n | grep 1017  (muafiyet kuralı duruyor mu?)',
            ]);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
