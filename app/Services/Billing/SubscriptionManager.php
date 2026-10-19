<?php

namespace App\Services\Billing;

use App\Mail\InvoicePaidMail;
use App\Mail\PaymentFailedMail;
use App\Mail\SubscriptionExpiredMail;
use App\Models\BillingEvent;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Abonelik yaşam döngüsü: deneme → ödeme formu → aktif → yenileme / ödeme hatası → süresi dolmuş.
 * Plan uygulanınca tenant ürün kapısı (settings.products) ve limitler (settings.plan_limits) yazılır.
 */
class SubscriptionManager
{
    public function __construct(private readonly IyzicoClient $iyzico) {}

    // ── Deneme ─────────────────────────────────────────────────────────────

    public function startTrial(Tenant $tenant, ?Plan $plan = null): Subscription
    {
        $plan ??= Plan::trialDefault();
        if (! $plan) {
            throw new RuntimeException('Deneme planı tanımlı değil (plans tablosu boş).');
        }
        $days = (int) ($plan->trial_days ?: config('saas.billing.trial_days', 14));

        $sub = Subscription::updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $plan->id,
            'status' => Subscription::TRIALING,
            'billing_cycle' => 'monthly',
            'trial_ends_at' => now()->addDays($days),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($days),
            'price' => $plan->price_monthly,
            'currency' => $plan->currency,
        ]);
        $this->applyPlan($tenant, $plan);
        BillingEvent::record($tenant->id, 'trial.started', ['plan' => $plan->code, 'days' => $days]);

        return $sub;
    }

    // ── Plan uygulama ───────────────────────────────────────────────────────

    public function applyPlan(Tenant $tenant, Plan $plan): void
    {
        $settings = $tenant->settings ?? [];
        $settings['products'] = array_values(array_intersect($plan->products ?? [], Tenant::PRODUCTS));
        $settings['plan_limits'] = $plan->limits ?? [];
        $settings['plan_code'] = $plan->code;
        unset($settings['allowed_pages']);
        $tenant->forceFill(['settings' => $settings])->save();
        Cache::forget("tenant:{$tenant->id}:subscription");
    }

    /** Erişim kilidi: ürün listesi boşaltılmaz (plan korunur) — kapı middleware'de. */
    public function lock(Subscription $sub, string $status): void
    {
        $sub->forceFill(['status' => $status, 'ends_at' => $sub->ends_at ?? now()])->save();
        Cache::forget("tenant:{$sub->tenant_id}:subscription");
    }

    // ── Ödeme formu (ilk ödeme + kart saklama) ───────────────────────────────

    /**
     * @return array{invoice:Invoice, payment_page_url:string, token:string}
     */
    public function startCheckout(Tenant $tenant, User $user, Plan $plan, string $cycle, string $ip, string $callbackUrl): array
    {
        $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
        $sub = $tenant->subscription ?? $this->startTrial($tenant, $plan);
        $profile = $tenant->billingProfile;

        $invoice = $this->createInvoice($tenant, $sub, $plan, $cycle, now(), $this->periodEnd(now(), $cycle), 'iyzico');
        $conversationId = 'inv-' . $invoice->id . '-' . Str::random(6);

        $buyer = $this->buyerPayload($tenant, $user, $profile, $ip);
        $payload = [
            'locale' => 'tr',
            'conversationId' => $conversationId,
            'price' => $this->money($invoice->subtotal),
            'paidPrice' => $this->money($invoice->total),
            'currency' => $invoice->currency,
            'basketId' => $invoice->number,
            'paymentGroup' => 'SUBSCRIPTION',
            'callbackUrl' => $callbackUrl,
            'enabledInstallments' => [1],
            'cardUserKey' => $sub->gateway_customer_ref ?: null,
            'buyer' => $buyer,
            'shippingAddress' => $this->addressPayload($tenant, $profile),
            'billingAddress' => $this->addressPayload($tenant, $profile),
            'basketItems' => [[
                'id' => $plan->code,
                'name' => Str::limit(config('saas.brand.name') . ' ' . $plan->name . ' (' . ($cycle === 'yearly' ? 'yıllık' : 'aylık') . ')', 60, ''),
                'category1' => 'SaaS Abonelik',
                'itemType' => 'VIRTUAL',
                'price' => $this->money($invoice->subtotal),
            ]],
        ];
        $payload = array_filter($payload, fn ($v) => $v !== null);

        $res = $this->iyzico->initializeCheckoutForm($payload);
        if (($res['status'] ?? '') !== 'success' || empty($res['token'])) {
            $invoice->forceFill(['status' => Invoice::FAILED, 'failure_reason' => $res['errorMessage'] ?? 'iyzico başlatma hatası'])->save();
            throw new RuntimeException($res['errorMessage'] ?? 'Ödeme formu başlatılamadı.');
        }

        $invoice->forceFill([
            'gateway_token' => $res['token'],
            'meta' => ['conversation_id' => $conversationId, 'plan_code' => $plan->code, 'cycle' => $cycle, 'register_card' => true],
        ])->save();

        return [
            'invoice' => $invoice,
            'payment_page_url' => (string) ($res['paymentPageUrl'] ?? ''),
            'token' => (string) $res['token'],
        ];
    }

    /** iyzico callback'i: token ile sonucu çek, faturayı kapat, aboneliği aktive et. */
    public function completeCheckout(string $token): Invoice
    {
        $invoice = Invoice::where('gateway_token', $token)->firstOrFail();
        if ($invoice->status === Invoice::PAID) {
            return $invoice; // idempotent
        }
        $res = $this->iyzico->retrieveCheckoutForm($token, (string) ($invoice->meta['conversation_id'] ?? ''));

        if (($res['status'] ?? '') !== 'success' || ($res['paymentStatus'] ?? '') !== 'SUCCESS') {
            $invoice->forceFill([
                'status' => Invoice::FAILED,
                'attempts' => $invoice->attempts + 1,
                'failure_reason' => $res['errorMessage'] ?? ($res['paymentStatus'] ?? 'Ödeme başarısız'),
            ])->save();
            BillingEvent::record($invoice->tenant_id, 'checkout.failed', ['invoice' => $invoice->number, 'reason' => $invoice->failure_reason]);

            return $invoice;
        }

        DB::transaction(function () use ($invoice, $res) {
            $tenant = $invoice->tenant;
            $sub = $tenant->subscription;
            $plan = Plan::where('code', $invoice->meta['plan_code'] ?? '')->first() ?? $sub->plan;
            $cycle = $invoice->meta['cycle'] ?? 'monthly';

            $sub->forceFill([
                'plan_id' => $plan->id,
                'status' => Subscription::ACTIVE,
                'billing_cycle' => $cycle,
                'current_period_start' => $invoice->period_start,
                'current_period_end' => $invoice->period_end,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'ends_at' => null,
                'gateway' => 'iyzico',
                'gateway_customer_ref' => $res['cardUserKey'] ?? $sub->gateway_customer_ref,
                'gateway_card_ref' => $res['cardToken'] ?? $sub->gateway_card_ref,
                'card_meta' => [
                    'last4' => $res['lastFourDigits'] ?? null,
                    'association' => $res['cardAssociation'] ?? null,
                    'family' => $res['cardFamily'] ?? null,
                    'bank' => $res['cardBankName'] ?? null,
                ],
                'price' => $plan->priceFor($cycle),
                'currency' => $plan->currency,
                'failed_attempts' => 0,
                'last_payment_at' => now(),
            ])->save();

            $invoice->forceFill([
                'status' => Invoice::PAID,
                'paid_at' => now(),
                'gateway_payment_id' => (string) ($res['paymentId'] ?? ''),
                'attempts' => $invoice->attempts + 1,
            ])->save();

            $this->applyPlan($tenant, $plan);
            BillingEvent::record($tenant->id, 'subscription.activated', ['invoice' => $invoice->number, 'plan' => $plan->code, 'cycle' => $cycle]);
        });

        $this->notifyOwner($invoice->tenant, new InvoicePaidMail($invoice->fresh()));

        return $invoice->fresh();
    }

    // ── Yenileme ────────────────────────────────────────────────────────────

    /** Dönemi dolan aktif abonelikleri saklı kartla yeniler; başarısızsa past_due → grace sonrası expired. */
    public function renewDue(): array
    {
        $stats = ['renewed' => 0, 'failed' => 0, 'expired' => 0, 'trial_expired' => 0, 'canceled_ended' => 0];

        // Deneme süresi dolanlar
        Subscription::where('status', Subscription::TRIALING)->where('trial_ends_at', '<=', now())->with('tenant')->each(function (Subscription $sub) use (&$stats) {
            $this->lock($sub, Subscription::EXPIRED);
            $stats['trial_expired']++;
            BillingEvent::record($sub->tenant_id, 'trial.expired');
            $this->notifyOwner($sub->tenant, new SubscriptionExpiredMail($sub, 'trial'));
        });

        // Dönem sonunda iptal edilenler
        Subscription::where('status', Subscription::CANCELED)->where('ends_at', '<=', now())->each(function (Subscription $sub) use (&$stats) {
            $this->lock($sub, Subscription::EXPIRED);
            $stats['canceled_ended']++;
            BillingEvent::record($sub->tenant_id, 'subscription.ended');
        });

        // Yenilenecekler (aktif + ödeme-bekliyor, dönemi dolmuş)
        Subscription::whereIn('status', [Subscription::ACTIVE, Subscription::PAST_DUE])
            ->where('current_period_end', '<=', now())
            ->with(['tenant', 'plan'])
            ->each(function (Subscription $sub) use (&$stats) {
                if ($sub->cancel_at_period_end) {
                    $sub->forceFill(['status' => Subscription::CANCELED, 'ends_at' => $sub->current_period_end])->save();
                    $this->lock($sub, Subscription::EXPIRED);
                    $stats['canceled_ended']++;

                    return;
                }
                if ($sub->gateway === 'manual') {
                    // Havale/EFT: açık fatura var, süper admin "ödendi" işaretleyince uzar; grace dolunca kilit.
                    $this->ensureOpenRenewalInvoice($sub);
                    if ($sub->status !== Subscription::PAST_DUE) {
                        $sub->forceFill(['status' => Subscription::PAST_DUE])->save();
                    }
                    if (! $sub->grantsAccess()) {
                        $this->lock($sub, Subscription::EXPIRED);
                        $stats['expired']++;
                    }

                    return;
                }
                $ok = $this->chargeRenewal($sub);
                if ($ok) {
                    $stats['renewed']++;
                } elseif ($sub->fresh()->grantsAccess()) {
                    $stats['failed']++;
                } else {
                    $this->lock($sub, Subscription::EXPIRED);
                    $stats['expired']++;
                    $this->notifyOwner($sub->tenant, new SubscriptionExpiredMail($sub, 'payment'));
                }
            });

        return $stats;
    }

    public function chargeRenewal(Subscription $sub): bool
    {
        if (! $sub->hasStoredCard()) {
            $sub->forceFill(['status' => Subscription::PAST_DUE, 'failed_attempts' => $sub->failed_attempts + 1])->save();
            BillingEvent::record($sub->tenant_id, 'renewal.no_card');

            return false;
        }
        $tenant = $sub->tenant;
        $plan = $sub->plan;
        $cycle = $sub->billing_cycle;
        $start = $sub->current_period_end ?? now();
        $invoice = $this->ensureOpenRenewalInvoice($sub);
        $conversationId = 'ren-' . $invoice->id . '-' . Str::random(6);
        $owner = $tenant->users()->where('is_owner', true)->first() ?? $tenant->users()->where('role', 'admin')->orderBy('id')->first();

        $payload = [
            'locale' => 'tr',
            'conversationId' => $conversationId,
            'price' => $this->money($invoice->subtotal),
            'paidPrice' => $this->money($invoice->total),
            'currency' => $invoice->currency,
            'installment' => 1,
            'basketId' => $invoice->number,
            'paymentChannel' => 'WEB',
            'paymentGroup' => 'SUBSCRIPTION',
            'paymentCard' => ['cardUserKey' => $sub->gateway_customer_ref, 'cardToken' => $sub->gateway_card_ref],
            'buyer' => $this->buyerPayload($tenant, $owner, $tenant->billingProfile, '127.0.0.1'),
            'shippingAddress' => $this->addressPayload($tenant, $tenant->billingProfile),
            'billingAddress' => $this->addressPayload($tenant, $tenant->billingProfile),
            'basketItems' => [[
                'id' => $plan->code, 'name' => Str::limit(config('saas.brand.name') . ' ' . $plan->name, 60, ''),
                'category1' => 'SaaS Abonelik', 'itemType' => 'VIRTUAL', 'price' => $this->money($invoice->subtotal),
            ]],
        ];

        try {
            $res = $this->iyzico->chargeStoredCard($payload);
        } catch (\Throwable $e) {
            Log::error('billing.renewal_exception', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            $res = ['status' => 'failure', 'errorMessage' => $e->getMessage()];
        }

        if (($res['status'] ?? '') === 'success') {
            $end = $this->periodEnd($start, $cycle);
            DB::transaction(function () use ($sub, $invoice, $res, $start, $end, $plan) {
                $invoice->forceFill(['status' => Invoice::PAID, 'paid_at' => now(), 'gateway' => 'iyzico', 'gateway_payment_id' => (string) ($res['paymentId'] ?? ''), 'attempts' => $invoice->attempts + 1, 'period_start' => $start, 'period_end' => $end])->save();
                $sub->forceFill(['status' => Subscription::ACTIVE, 'current_period_start' => $start, 'current_period_end' => $end, 'failed_attempts' => 0, 'last_payment_at' => now()])->save();
                $this->applyPlan($sub->tenant, $plan);
            });
            BillingEvent::record($tenant->id, 'renewal.paid', ['invoice' => $invoice->number]);
            $this->notifyOwner($tenant, new InvoicePaidMail($invoice->fresh()));

            return true;
        }

        $invoice->forceFill(['status' => Invoice::OPEN, 'attempts' => $invoice->attempts + 1, 'failure_reason' => $res['errorMessage'] ?? 'Tahsilat başarısız'])->save();
        $sub->forceFill(['status' => Subscription::PAST_DUE, 'failed_attempts' => $sub->failed_attempts + 1])->save();
        Cache::forget("tenant:{$tenant->id}:subscription");
        BillingEvent::record($tenant->id, 'renewal.failed', ['invoice' => $invoice->number, 'reason' => $invoice->failure_reason]);
        if ($sub->failed_attempts <= 1) {
            $this->notifyOwner($tenant, new PaymentFailedMail($sub, $invoice));
        }

        return false;
    }

    // ── İptal / devam ───────────────────────────────────────────────────────

    public function cancelAtPeriodEnd(Subscription $sub): void
    {
        $sub->forceFill(['cancel_at_period_end' => true, 'canceled_at' => now()])->save();
        BillingEvent::record($sub->tenant_id, 'subscription.cancel_requested');
    }

    public function resume(Subscription $sub): void
    {
        $sub->forceFill(['cancel_at_period_end' => false, 'canceled_at' => null])->save();
        BillingEvent::record($sub->tenant_id, 'subscription.resumed');
    }

    /** Süper admin: planı elle ata ve dönemi uzat (havale/EFT, kampanya, destek). */
    public function manualActivate(Tenant $tenant, Plan $plan, string $cycle, int $days, ?Invoice $invoice = null): Subscription
    {
        $sub = $tenant->subscription ?? $this->startTrial($tenant, $plan);
        $start = now();
        $sub->forceFill([
            'plan_id' => $plan->id,
            'status' => Subscription::ACTIVE,
            'billing_cycle' => $cycle,
            'current_period_start' => $start,
            'current_period_end' => $start->copy()->addDays($days),
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ends_at' => null,
            'gateway' => $sub->gateway ?: 'manual',
            'price' => $plan->priceFor($cycle),
            'currency' => $plan->currency,
            'failed_attempts' => 0,
            'last_payment_at' => $invoice ? now() : $sub->last_payment_at,
        ])->save();
        if ($invoice) {
            $invoice->forceFill(['status' => Invoice::PAID, 'paid_at' => now(), 'gateway' => 'manual'])->save();
        }
        $this->applyPlan($tenant, $plan);
        BillingEvent::record($tenant->id, 'subscription.manual_activate', ['plan' => $plan->code, 'days' => $days]);

        return $sub;
    }

    // ── Yardımcılar ─────────────────────────────────────────────────────────

    public function createInvoice(Tenant $tenant, Subscription $sub, Plan $plan, string $cycle, $start, $end, string $gateway): Invoice
    {
        $subtotal = round($plan->priceFor($cycle), 2);
        $taxRate = (float) config('saas.billing.tax_rate', 20);
        $tax = round($subtotal * $taxRate / 100, 2);

        return Invoice::create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $sub->id,
            'number' => Invoice::nextNumber(),
            'status' => Invoice::OPEN,
            'description' => config('saas.brand.name') . ' ' . $plan->name . ' — ' . ($cycle === 'yearly' ? 'yıllık' : 'aylık') . ' abonelik',
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $tax,
            'total' => round($subtotal + $tax, 2),
            'currency' => $plan->currency,
            'period_start' => $start,
            'period_end' => $end,
            'due_at' => $start,
            'gateway' => $gateway,
            'billing_snapshot' => $tenant->billingProfile?->only(['type', 'legal_name', 'tax_office', 'tax_number', 'address', 'district', 'city', 'country', 'email']),
        ]);
    }

    private function ensureOpenRenewalInvoice(Subscription $sub): Invoice
    {
        $existing = Invoice::where('subscription_id', $sub->id)->where('status', Invoice::OPEN)
            ->where('period_start', $sub->current_period_end)->first();
        if ($existing) {
            return $existing;
        }
        $start = $sub->current_period_end ?? now();

        return $this->createInvoice($sub->tenant, $sub, $sub->plan, $sub->billing_cycle, $start, $this->periodEnd($start, $sub->billing_cycle), $sub->gateway ?: 'iyzico');
    }

    public function periodEnd($start, string $cycle)
    {
        return $cycle === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth();
    }

    private function money(float|string $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    private function buyerPayload(Tenant $tenant, ?User $user, ?BillingProfile $profile, string $ip): array
    {
        $name = trim((string) ($user?->name ?? $tenant->name));
        $parts = preg_split('/\s+/', $name) ?: [$name];
        $surname = count($parts) > 1 ? array_pop($parts) : $tenant->name;

        return [
            'id' => 'tenant-' . $tenant->id,
            'name' => Str::limit(implode(' ', $parts) ?: $tenant->name, 50, ''),
            'surname' => Str::limit($surname ?: '-', 50, ''),
            'gsmNumber' => $profile?->phone ?: '+905000000000',
            'email' => $user?->email ?: ($profile?->email ?: 'billing@' . config('saas.brand.domain')),
            'identityNumber' => $profile?->type === 'individual' && $profile?->tax_number ? $profile->tax_number : '11111111111',
            'registrationAddress' => Str::limit($profile?->address ?: 'Adres belirtilmedi', 200, ''),
            'ip' => $ip,
            'city' => $profile?->city ?: 'Istanbul',
            'country' => 'Turkey',
        ];
    }

    private function addressPayload(Tenant $tenant, ?BillingProfile $profile): array
    {
        return [
            'contactName' => Str::limit($profile?->legal_name ?: $tenant->name, 100, ''),
            'city' => $profile?->city ?: 'Istanbul',
            'country' => 'Turkey',
            'address' => Str::limit($profile?->address ?: 'Adres belirtilmedi', 200, ''),
            'zipCode' => $profile?->postal_code ?: '34000',
        ];
    }

    private function notifyOwner(Tenant $tenant, $mailable): void
    {
        try {
            $owner = $tenant->users()->where('is_owner', true)->first() ?? $tenant->users()->where('role', 'admin')->orderBy('id')->first();
            if ($owner?->email) {
                Mail::to($owner->email)->queue($mailable);
            }
        } catch (\Throwable $e) {
            Log::warning('billing.notify_failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
        }
    }
}
