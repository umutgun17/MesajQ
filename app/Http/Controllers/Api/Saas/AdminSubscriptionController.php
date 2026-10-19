<?php

namespace App\Http\Controllers\Api\Saas;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Süper admin: abonelik listesi, elle plan atama / uzatma, havale faturasını ödendi işaretleme. */
class AdminSubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionManager $subscriptions) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $rows = Subscription::with(['tenant', 'plan'])->orderByDesc('id')->get()->map(fn (Subscription $s) => [
            'tenant_id' => $s->tenant_id,
            'tenant' => $s->tenant?->name,
            'slug' => $s->tenant?->slug,
            'owner_email' => $s->tenant?->users()->where('is_owner', true)->value('email'),
            'created_at' => $s->tenant?->created_at?->toISOString(),
        ] + $s->toPanelArray());

        $openManual = Invoice::with('tenant')->where('status', Invoice::OPEN)->where('gateway', 'manual')->orderByDesc('id')->get()
            ->map(fn (Invoice $i) => ['tenant' => $i->tenant?->name, 'tenant_id' => $i->tenant_id] + $i->toPanelArray());

        return response()->json([
            'subscriptions' => $rows,
            'open_bank_invoices' => $openManual,
            'plans' => Plan::where('family', config('saas.family'))->orderBy('sort_order')->get()->map->toPublicArray(),
            'totals' => [
                'trialing' => Subscription::where('status', 'trialing')->count(),
                'active' => Subscription::where('status', 'active')->count(),
                'past_due' => Subscription::where('status', 'past_due')->count(),
                'expired' => Subscription::where('status', 'expired')->count(),
                'mrr' => (float) Subscription::where('status', 'active')->get()->sum(fn ($s) => $s->billing_cycle === 'yearly' ? $s->price / 12 : $s->price),
            ],
        ]);
    }

    public function activate(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'plan' => 'required|string',
            'cycle' => 'required|in:monthly,yearly',
            'days' => 'required|integer|min:1|max:730',
            'invoice_id' => 'nullable|integer',
        ]);
        $plan = Plan::where('code', $data['plan'])->firstOrFail();
        $invoice = ! empty($data['invoice_id']) ? Invoice::where('tenant_id', $tenant->id)->find($data['invoice_id']) : null;
        $sub = $this->subscriptions->manualActivate($tenant, $plan, $data['cycle'], (int) $data['days'], $invoice);

        return response()->json($sub->load('plan')->toPanelArray());
    }

    public function extendTrial(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $days = (int) $request->validate(['days' => 'required|integer|min:1|max:90'])['days'];
        $sub = $tenant->subscription ?? $this->subscriptions->startTrial($tenant);
        $base = $sub->trial_ends_at && $sub->trial_ends_at->isFuture() ? $sub->trial_ends_at : now();
        $sub->forceFill(['status' => Subscription::TRIALING, 'trial_ends_at' => $base->copy()->addDays($days), 'current_period_end' => $base->copy()->addDays($days), 'ends_at' => null])->save();
        \Illuminate\Support\Facades\Cache::forget("tenant:{$tenant->id}:subscription");

        return response()->json($sub->fresh()->load('plan')->toPanelArray());
    }

    public function lock(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $sub = $tenant->subscription;
        abort_unless($sub, 404);
        $this->subscriptions->lock($sub, Subscription::EXPIRED);

        return response()->json($sub->fresh()->load('plan')->toPanelArray());
    }
}
