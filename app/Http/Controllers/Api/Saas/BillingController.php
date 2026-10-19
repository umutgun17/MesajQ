<?php

namespace App\Http\Controllers\Api\Saas;

use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\IyzicoClient;
use App\Services\Billing\SubscriptionManager;
use App\Services\Billing\UsageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function __construct(private readonly SubscriptionManager $subscriptions) {}

    /** Herkese açık plan listesi (vitrin + kayıt sayfası). */
    public function publicPlans(): JsonResponse
    {
        return response()->json([
            'family' => config('saas.family'),
            'yearly_discount' => (float) config('saas.billing.yearly_discount'),
            'tax_rate' => (float) config('saas.billing.tax_rate'),
            'trial_days' => (int) config('saas.billing.trial_days'),
            'plans' => Plan::where('family', config('saas.family'))->where('is_public', true)->orderBy('sort_order')->get()->map->toPublicArray(),
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $sub = $tenant->subscription()->with('plan')->first();

        return response()->json([
            'subscription' => $sub?->toPanelArray(),
            'plans' => Plan::where('family', config('saas.family'))->where('is_public', true)->orderBy('sort_order')->get()->map->toPublicArray(),
            'invoices' => Invoice::where('tenant_id', $tenant->id)->orderByDesc('id')->limit(36)->get()->map->toPanelArray(),
            'profile' => $tenant->billingProfile,
            'usage' => UsageMeter::summary($tenant),
            'gateway_ready' => IyzicoClient::fromConfig()->isConfigured(),
            'bank_transfer' => config('saas.billing.bank_transfer'),
            'can_manage' => $this->canManage($request),
            'email_verified' => (bool) $request->user()->email_verified_at,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'type' => 'required|in:company,individual',
            'legal_name' => 'required|string|max:190',
            'tax_office' => 'nullable|string|max:120',
            'tax_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:30',
            'address' => 'required|string|max:255',
            'district' => 'nullable|string|max:80',
            'city' => 'required|string|max:80',
            'postal_code' => 'nullable|string|max:12',
        ]);
        $profile = BillingProfile::updateOrCreate(['tenant_id' => $this->tenant($request)->id], $data);

        return response()->json($profile);
    }

    /** Ödeme formunu başlat → iyzico ödeme sayfası URL'si döner. */
    public function checkout(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['plan' => 'required|string', 'cycle' => 'required|in:monthly,yearly']);
        $tenant = $this->tenant($request);
        $plan = Plan::where('code', $data['plan'])->where('family', config('saas.family'))->where('is_public', true)->firstOrFail();

        if (! $tenant->billingProfile?->isComplete()) {
            return response()->json(['message' => 'Önce fatura bilgilerinizi tamamlayın.', 'code' => 'profile_incomplete'], 422);
        }
        if (! IyzicoClient::fromConfig()->isConfigured()) {
            return response()->json(['message' => 'Kartla ödeme henüz aktif değil. Havale/EFT ile ödeme için fatura oluşturun.', 'code' => 'gateway_unavailable'], 422);
        }

        try {
            $res = $this->subscriptions->startCheckout($tenant, $request->user(), $plan, $data['cycle'], $request->ip(), route('saas.billing.callback'));
        } catch (\Throwable $e) {
            Log::error('billing.checkout_failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['payment_page_url' => $res['payment_page_url'], 'invoice' => $res['invoice']->toPanelArray()]);
    }

    /** iyzico ödeme sonrası geri dönüş (POST token). Tarayıcıyı panele yönlendirir. */
    public function callback(Request $request): RedirectResponse
    {
        $token = (string) $request->input('token', '');
        $app = rtrim(config('saas.app_base'), '/') . '/billing';
        if ($token === '') {
            return redirect($app . '?payment=invalid');
        }
        try {
            $invoice = $this->subscriptions->completeCheckout($token);
        } catch (\Throwable $e) {
            Log::error('billing.callback_failed', ['error' => $e->getMessage()]);

            return redirect($app . '?payment=error');
        }

        return redirect($app . ($invoice->status === Invoice::PAID ? '?payment=success' : '?payment=failed'));
    }

    /** Havale/EFT: açık fatura oluştur, banka bilgisi göster; süper admin ödendi işaretler. */
    public function bankTransferInvoice(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['plan' => 'required|string', 'cycle' => 'required|in:monthly,yearly']);
        $tenant = $this->tenant($request);
        $plan = Plan::where('code', $data['plan'])->where('family', config('saas.family'))->where('is_public', true)->firstOrFail();
        if (! $tenant->billingProfile?->isComplete()) {
            return response()->json(['message' => 'Önce fatura bilgilerinizi tamamlayın.', 'code' => 'profile_incomplete'], 422);
        }
        $sub = $tenant->subscription ?? $this->subscriptions->startTrial($tenant, $plan);
        $open = Invoice::where('tenant_id', $tenant->id)->where('status', Invoice::OPEN)->where('gateway', 'manual')->first();
        if ($open) {
            return response()->json(['invoice' => $open->toPanelArray(), 'bank' => config('saas.billing.bank_transfer')]);
        }
        $start = $sub->status === 'active' && $sub->current_period_end?->isFuture() ? $sub->current_period_end : now();
        $invoice = $this->subscriptions->createInvoice($tenant, $sub, $plan, $data['cycle'], $start, $this->subscriptions->periodEnd($start, $data['cycle']), 'manual');
        $invoice->forceFill(['meta' => ['plan_code' => $plan->code, 'cycle' => $data['cycle']]])->save();
        if ($sub->gateway !== 'iyzico') {
            $sub->forceFill(['gateway' => 'manual'])->save();
        }

        return response()->json(['invoice' => $invoice->toPanelArray(), 'bank' => config('saas.billing.bank_transfer')]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $sub = $this->tenant($request)->subscription;
        abort_unless($sub, 404);
        $this->subscriptions->cancelAtPeriodEnd($sub);

        return response()->json($sub->fresh()->load('plan')->toPanelArray());
    }

    public function resume(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $sub = $this->tenant($request)->subscription;
        abort_unless($sub, 404);
        $this->subscriptions->resume($sub);

        return response()->json($sub->fresh()->load('plan')->toPanelArray());
    }

    private function tenant(Request $request): \App\Models\Tenant
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            $id = (int) $request->header('X-Tenant-Id');
            abort_unless($id, 422, 'Tenant seçin.');

            return \App\Models\Tenant::findOrFail($id);
        }

        return $user->tenant;
    }

    private function canManage(Request $request): bool
    {
        $u = $request->user();

        return $u->isSuperAdmin() || $u->is_owner || $u->isAdmin();
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($this->canManage($request), 403, 'Faturalama yalnızca hesap sahibi/yönetici tarafından yönetilir.');
    }
}
