<?php

namespace App\Console\Commands;

use App\Mail\TrialEndingMail;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Günlük: deneme bitişi, yenileme tahsilatı, ödeme hatası → kilit; T-3 deneme hatırlatması. */
class BillingRenew extends Command
{
    protected $signature = 'billing:renew {--dry-run : Yalnız listele}';

    protected $description = 'Abonelikleri yeniler, biten denemeleri kilitler, hatırlatma gönderir';

    public function handle(SubscriptionManager $manager): int
    {
        if ($this->option('dry-run')) {
            $due = Subscription::whereIn('status', ['active', 'past_due'])->where('current_period_end', '<=', now())->count();
            $trial = Subscription::where('status', 'trialing')->where('trial_ends_at', '<=', now())->count();
            $this->info("Yenilenecek: {$due}, biten deneme: {$trial}");

            return self::SUCCESS;
        }

        $stats = $manager->renewDue();
        $this->info('Sonuç: ' . json_encode($stats));

        // Deneme bitimine 3 gün kala tek sefer hatırlat
        Subscription::where('status', 'trialing')
            ->whereBetween('trial_ends_at', [now()->addDays(2)->startOfDay(), now()->addDays(3)->endOfDay()])
            ->with('tenant')
            ->each(function (Subscription $sub) {
                $meta = $sub->meta ?? [];
                if (! empty($meta['trial_reminder_sent'])) {
                    return;
                }
                $owner = $sub->tenant?->users()->where('is_owner', true)->first();
                if ($owner?->email) {
                    Mail::to($owner->email)->queue(new TrialEndingMail($sub, 3));
                }
                $sub->forceFill(['meta' => $meta + ['trial_reminder_sent' => now()->toDateString()]])->save();
            });

        return self::SUCCESS;
    }
}
