<?php

namespace App\Http\Controllers\Api\Traits;

use App\Models\Tenant;
use App\Services\GoogleAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait ResolvesGa4Service
{
    protected function resolveService(Request $request): ?GoogleAnalyticsService
    {
        $tenant = $this->resolveTenant($request);

        if (! $tenant) {
            return null;
        }

        $service = GoogleAnalyticsService::fromTenant($tenant);

        return $service->isAvailable() ? $service : null;
    }

    protected function resolveTenant(Request $request): ?Tenant
    {
        $user = $request->user();

        if ($user->tenant_id) {
            return $user->tenant;
        }

        if ($request->hasHeader('X-Tenant-Id')) {
            return Tenant::find((int) $request->header('X-Tenant-Id'));
        }

        return null;
    }

    protected function resolveDateRange(Request $request): array
    {
        $period = $request->query('period', '30d');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if ($dateFrom && $dateTo) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                return [$dateFrom, $dateTo];
            }
        }

        if ($period === 'today') {
            $today = Carbon::now()->format('Y-m-d');

            return [$today, $today];
        }

        if ($period === 'yesterday') {
            $yesterday = Carbon::yesterday()->format('Y-m-d');

            return [$yesterday, $yesterday];
        }

        $days = match ($period) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        return [
            Carbon::now()->subDays($days)->format('Y-m-d'),
            Carbon::now()->format('Y-m-d'),
        ];
    }

    protected function notConfigured(): JsonResponse
    {
        return response()->json([
            'error' => 'ga4_not_configured',
            'message' => 'Google Analytics 4 baglantisi yapilandirilmamis.',
        ]);
    }
}
