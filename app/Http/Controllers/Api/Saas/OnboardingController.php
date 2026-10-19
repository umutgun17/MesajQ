<?php

namespace App\Http\Controllers\Api\Saas;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Kurulum sihirbazı durumu: her adımın tamamlanıp tamamlanmadığı tek uçta. */
class OnboardingController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->isSuperAdmin() ? \App\Models\Tenant::find((int) $request->header('X-Tenant-Id')) : $user->tenant;
        if (! $tenant) {
            return response()->json(['steps' => [], 'done' => true]);
        }
        $channels = Channel::where('tenant_id', $tenant->id)->get();
        $active = fn (array $types) => $channels->whereIn('type', $types)->where('is_active', true)->isNotEmpty();
        $settings = $tenant->settings ?? [];

        $steps = [];
        foreach (config('saas.onboarding', []) as $step) {
            $done = match ($step['check']) {
                'email_verified' => (bool) $user->email_verified_at,
                'store_connected' => $active(['ticimax-ecommerce', 'ikas-ecommerce']) || filled($settings['storefront_url'] ?? null),
                'channel_connected' => $active(['whatsapp', 'facebook-message', 'instagram-message', 'email', 'trendyol-product', 'hepsiburada-product']),
                'team_invited' => $tenant->users()->count() > 1,
                'widget_installed' => filled($settings['storefront_url'] ?? null) && collect($settings)->filter(fn ($v, $k) => str_ends_with((string) $k, '_widget_enabled') && $v)->isNotEmpty(),
                'returns_configured' => ! empty($settings['returns']),
                'billing_profile' => (bool) $tenant->billingProfile?->isComplete(),
                default => false,
            };
            $steps[] = $step + ['done' => $done];
        }

        return response()->json([
            'steps' => $steps,
            'done' => collect($steps)->every(fn ($s) => $s['done']),
            'slug' => $tenant->slug,
            'onboarded_at' => $tenant->onboarded_at?->toISOString(),
        ]);
    }

    public function dismiss(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->tenant) {
            $user->tenant->forceFill(['onboarded_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }
}
