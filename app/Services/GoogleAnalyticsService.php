<?php

namespace App\Services;

use App\Models\Tenant;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\RunRealtimeReportRequest;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class GoogleAnalyticsService
{
    protected string $propertyId;
    protected string $credentialsPath;
    protected int $cacheTtl;

    public function __construct(string $propertyId, string $credentialsPath, int $cacheTtl)
    {
        $this->propertyId = $propertyId;
        $this->credentialsPath = $credentialsPath;
        $this->cacheTtl = $cacheTtl;
    }

    public static function fromTenant(Tenant $tenant): self
    {
        $settings = $tenant->settings ?? [];
        $propertyId = $settings['ga4_property_id'] ?? '';
        $encPath = storage_path("app/ga4/{$tenant->id}.json.enc");
        $legacyPath = storage_path("app/ga4/{$tenant->id}.json");
        $credentialsPath = file_exists($encPath) ? $encPath : $legacyPath;
        $cacheTtl = config('google-analytics.cache_ttl', 3600);

        return new self($propertyId, $credentialsPath, $cacheTtl);
    }

    public function isAvailable(): bool
    {
        return ! empty($this->propertyId) && file_exists($this->credentialsPath);
    }

    public function testConnection(): array
    {
        if (! $this->isAvailable()) {
            return ['success' => false, 'message' => 'GA4 yapilandirmasi eksik.'];
        }

        try {
            $client = $this->makeClient();
            $request = new RunReportRequest([
                'property' => "properties/{$this->propertyId}",
                'date_ranges' => [new DateRange(['start_date' => '1daysAgo', 'end_date' => 'today'])],
                'metrics' => [new Metric(['name' => 'activeUsers'])],
            ]);
            $client->runReport($request);

            return ['success' => true, 'message' => 'Baglanti basarili.'];
        } catch (\Throwable $e) {
            Log::warning('GA4 test connection failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Baglanti hatasi: '.$e->getMessage()];
        }
    }

    public function getProductStats(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:products:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $dims = [
                    new Dimension(['name' => 'itemName']),
                    new Dimension(['name' => 'itemId']),
                ];
                $dateRange = new DateRange(['start_date' => $startDate, 'end_date' => $endDate]);

                // Query 1: Core metrics (always compatible with item dimensions)
                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [$dateRange],
                    'dimensions' => $dims,
                    'metrics' => [
                        new Metric(['name' => 'itemsViewed']),
                        new Metric(['name' => 'itemsAddedToCart']),
                        new Metric(['name' => 'itemsPurchased']),
                        new Metric(['name' => 'itemRevenue']),
                    ],
                    'limit' => 10000,
                ]);

                $response = $client->runReport($request);

                // Query 2: List metrics (may not be available)
                $listData = [];
                try {
                    $listRequest = new RunReportRequest([
                        'property' => "properties/{$this->propertyId}",
                        'date_ranges' => [$dateRange],
                        'dimensions' => $dims,
                        'metrics' => [
                            new Metric(['name' => 'itemsViewedInList']),
                            new Metric(['name' => 'itemsClickedInList']),
                        ],
                        'limit' => 10000,
                    ]);
                    $listResponse = $client->runReport($listRequest);
                    foreach ($listResponse->getRows() as $row) {
                        $key = $row->getDimensionValues()[0]->getValue().'|'.$row->getDimensionValues()[1]->getValue();
                        $listData[$key] = [
                            'list_views' => (int) $row->getMetricValues()[0]->getValue(),
                            'list_clicks' => (int) $row->getMetricValues()[1]->getValue(),
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::warning('GA4 list metrics failed', ['error' => $e->getMessage()]);
                }

                $rows = [];
                foreach ($response->getRows() as $row) {
                    $rowDims = $row->getDimensionValues();
                    $vals = $row->getMetricValues();
                    $key = $rowDims[0]->getValue().'|'.$rowDims[1]->getValue();

                    $views = (int) $vals[0]->getValue();
                    $addToCarts = (int) $vals[1]->getValue();
                    $purchases = (int) $vals[2]->getValue();
                    $revenue = (float) $vals[3]->getValue();
                    $listViews = $listData[$key]['list_views'] ?? 0;
                    $listClicks = $listData[$key]['list_clicks'] ?? 0;

                    $rows[] = [
                        'item_name' => $rowDims[0]->getValue(),
                        'item_id' => $rowDims[1]->getValue(),
                        'views' => $views,
                        'list_views' => $listViews,
                        'list_clicks' => $listClicks,
                        'ctr' => $listViews > 0 ? round($listClicks / $listViews * 100, 2) : 0,
                        'add_to_carts' => $addToCarts,
                        'atc_rate' => $views > 0 ? round($addToCarts / $views * 100, 2) : 0,
                        'purchases' => $purchases,
                        'units_sold' => $purchases,
                        'conversion_rate' => $views > 0 ? round($purchases / $views * 100, 2) : 0,
                        'cart_to_purchase' => $addToCarts > 0 ? round($purchases / $addToCarts * 100, 2) : 0,
                        'revenue' => round($revenue, 2),
                        'avg_price' => $purchases > 0 ? round($revenue / $purchases, 2) : 0,
                        'revenue_per_view' => $views > 0 ? round($revenue / $views, 2) : 0,
                    ];
                }

                return $rows;
            } catch (\Throwable $e) {
                Log::error('GA4 getProductStats failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getSummaryStats(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:summary:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
                    'metrics' => [
                        new Metric(['name' => 'itemsViewed']),
                        new Metric(['name' => 'itemsAddedToCart']),
                        new Metric(['name' => 'itemsPurchased']),
                        new Metric(['name' => 'itemRevenue']),
                    ],
                ]);

                $response = $client->runReport($request);
                $row = $response->getRows()[0] ?? null;

                if (! $row) {
                    return [
                        'total_views' => 0,
                        'total_add_to_carts' => 0,
                        'total_purchases' => 0,
                        'total_revenue' => 0,
                        'conversion_rate' => 0,
                        'cart_abandonment_rate' => 0,
                    ];
                }

                $vals = $row->getMetricValues();
                $views = (int) $vals[0]->getValue();
                $addToCarts = (int) $vals[1]->getValue();
                $purchases = (int) $vals[2]->getValue();
                $revenue = (float) $vals[3]->getValue();

                return [
                    'total_views' => $views,
                    'total_add_to_carts' => $addToCarts,
                    'total_purchases' => $purchases,
                    'total_revenue' => round($revenue, 2),
                    'conversion_rate' => $views > 0 ? round($purchases / $views * 100, 2) : 0,
                    'cart_abandonment_rate' => $addToCarts > 0
                        ? round((1 - $purchases / $addToCarts) * 100, 2)
                        : 0,
                ];
            } catch (\Throwable $e) {
                Log::error('GA4 getSummaryStats failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getDailyTrend(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:chart:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
                    'dimensions' => [new Dimension(['name' => 'date'])],
                    'metrics' => [
                        new Metric(['name' => 'itemsViewed']),
                        new Metric(['name' => 'itemsAddedToCart']),
                        new Metric(['name' => 'itemsPurchased']),
                        new Metric(['name' => 'itemRevenue']),
                    ],
                ]);

                $response = $client->runReport($request);
                $points = [];

                foreach ($response->getRows() as $row) {
                    $date = $row->getDimensionValues()[0]->getValue();
                    $vals = $row->getMetricValues();
                    $points[] = [
                        'date' => substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2),
                        'views' => (int) $vals[0]->getValue(),
                        'add_to_carts' => (int) $vals[1]->getValue(),
                        'purchases' => (int) $vals[2]->getValue(),
                        'revenue' => round((float) $vals[3]->getValue(), 2),
                    ];
                }

                usort($points, fn ($a, $b) => strcmp($a['date'], $b['date']));

                return $points;
            } catch (\Throwable $e) {
                Log::error('GA4 getDailyTrend failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getSiteOverview(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:overview:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $dateRange = new DateRange(['start_date' => $startDate, 'end_date' => $endDate]);

                $metrics = [
                    new Metric(['name' => 'sessions']),
                    new Metric(['name' => 'totalUsers']),
                    new Metric(['name' => 'newUsers']),
                    new Metric(['name' => 'screenPageViews']),
                    new Metric(['name' => 'ecommercePurchases']),
                    new Metric(['name' => 'totalRevenue']),
                    new Metric(['name' => 'engagementRate']),
                    new Metric(['name' => 'averageSessionDuration']),
                ];

                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [$dateRange],
                    'metrics' => $metrics,
                ]);

                $response = $client->runReport($request);
                $row = $response->getRows()[0] ?? null;

                if (! $row) {
                    return [
                        'sessions' => 0,
                        'users' => 0,
                        'new_users' => 0,
                        'page_views' => 0,
                        'purchases' => 0,
                        'revenue' => 0,
                        'engagement_rate' => 0,
                        'avg_session_duration' => 0,
                        'conversion_rate' => 0,
                    ];
                }

                $vals = $row->getMetricValues();
                $sessions = (int) $vals[0]->getValue();
                $purchases = (int) $vals[4]->getValue();

                return [
                    'sessions' => $sessions,
                    'users' => (int) $vals[1]->getValue(),
                    'new_users' => (int) $vals[2]->getValue(),
                    'page_views' => (int) $vals[3]->getValue(),
                    'purchases' => $purchases,
                    'revenue' => round((float) $vals[5]->getValue(), 2),
                    'engagement_rate' => round((float) $vals[6]->getValue() * 100, 2),
                    'avg_session_duration' => round((float) $vals[7]->getValue(), 1),
                    'conversion_rate' => $sessions > 0 ? round($purchases / $sessions * 100, 2) : 0,
                ];
            } catch (\Throwable $e) {
                Log::error('GA4 getSiteOverview failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getSiteDailyTrend(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:sitetrend:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
                    'dimensions' => [new Dimension(['name' => 'date'])],
                    'metrics' => [
                        new Metric(['name' => 'sessions']),
                        new Metric(['name' => 'totalUsers']),
                        new Metric(['name' => 'screenPageViews']),
                        new Metric(['name' => 'ecommercePurchases']),
                        new Metric(['name' => 'totalRevenue']),
                    ],
                ]);

                $response = $client->runReport($request);
                $points = [];

                foreach ($response->getRows() as $row) {
                    $date = $row->getDimensionValues()[0]->getValue();
                    $vals = $row->getMetricValues();
                    $points[] = [
                        'date' => substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2),
                        'sessions' => (int) $vals[0]->getValue(),
                        'users' => (int) $vals[1]->getValue(),
                        'page_views' => (int) $vals[2]->getValue(),
                        'purchases' => (int) $vals[3]->getValue(),
                        'revenue' => round((float) $vals[4]->getValue(), 2),
                    ];
                }

                usort($points, fn ($a, $b) => strcmp($a['date'], $b['date']));

                return $points;
            } catch (\Throwable $e) {
                Log::error('GA4 getSiteDailyTrend failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getTrafficSources(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:traffic:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $dateRange = new DateRange(['start_date' => $startDate, 'end_date' => $endDate]);

                $metrics = [
                    new Metric(['name' => 'sessions']),
                    new Metric(['name' => 'totalUsers']),
                    new Metric(['name' => 'newUsers']),
                    new Metric(['name' => 'ecommercePurchases']),
                    new Metric(['name' => 'totalRevenue']),
                ];

                // Try engagementRate — may not be available
                $hasEngagement = true;
                try {
                    $metrics[] = new Metric(['name' => 'engagementRate']);
                } catch (\Throwable $e) {
                    $hasEngagement = false;
                }

                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [$dateRange],
                    'dimensions' => [new Dimension(['name' => 'sessionSourceMedium'])],
                    'metrics' => $metrics,
                    'limit' => 500,
                ]);

                $response = $client->runReport($request);
                $rows = [];

                foreach ($response->getRows() as $row) {
                    $sourceMedium = $row->getDimensionValues()[0]->getValue();
                    $vals = $row->getMetricValues();
                    $sessions = (int) $vals[0]->getValue();
                    $purchases = (int) $vals[3]->getValue();

                    $rows[] = [
                        'source_medium' => $sourceMedium,
                        'sessions' => $sessions,
                        'users' => (int) $vals[1]->getValue(),
                        'new_users' => (int) $vals[2]->getValue(),
                        'purchases' => $purchases,
                        'revenue' => round((float) $vals[4]->getValue(), 2),
                        'engagement_rate' => $hasEngagement && isset($vals[5])
                            ? round((float) $vals[5]->getValue() * 100, 2)
                            : 0,
                        'conversion_rate' => $sessions > 0 ? round($purchases / $sessions * 100, 2) : 0,
                    ];
                }

                return $rows;
            } catch (\Throwable $e) {
                Log::error('GA4 getTrafficSources failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getNewVsReturning(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:newret:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
                    'dimensions' => [new Dimension(['name' => 'newVsReturning'])],
                    'metrics' => [
                        new Metric(['name' => 'sessions']),
                        new Metric(['name' => 'totalUsers']),
                        new Metric(['name' => 'ecommercePurchases']),
                        new Metric(['name' => 'totalRevenue']),
                    ],
                ]);

                $response = $client->runReport($request);
                $rows = [];

                foreach ($response->getRows() as $row) {
                    $type = $row->getDimensionValues()[0]->getValue();
                    $vals = $row->getMetricValues();

                    $rows[] = [
                        'type' => $type,
                        'sessions' => (int) $vals[0]->getValue(),
                        'users' => (int) $vals[1]->getValue(),
                        'purchases' => (int) $vals[2]->getValue(),
                        'revenue' => round((float) $vals[3]->getValue(), 2),
                    ];
                }

                return $rows;
            } catch (\Throwable $e) {
                Log::error('GA4 getNewVsReturning failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getFunnelData(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:funnel:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $dateRange = new DateRange(['start_date' => $startDate, 'end_date' => $endDate]);

                $metrics = [
                    new Metric(['name' => 'sessions']),
                    new Metric(['name' => 'itemsViewed']),
                    new Metric(['name' => 'itemsAddedToCart']),
                    new Metric(['name' => 'ecommercePurchases']),
                    new Metric(['name' => 'totalRevenue']),
                ];

                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [$dateRange],
                    'metrics' => $metrics,
                ]);

                $response = $client->runReport($request);
                $row = $response->getRows()[0] ?? null;

                if (! $row) {
                    return [
                        'sessions' => 0,
                        'views' => 0,
                        'add_to_carts' => 0,
                        'checkouts' => 0,
                        'purchases' => 0,
                        'revenue' => 0,
                    ];
                }

                $vals = $row->getMetricValues();
                $result = [
                    'sessions' => (int) $vals[0]->getValue(),
                    'views' => (int) $vals[1]->getValue(),
                    'add_to_carts' => (int) $vals[2]->getValue(),
                    'checkouts' => 0,
                    'purchases' => (int) $vals[3]->getValue(),
                    'revenue' => round((float) $vals[4]->getValue(), 2),
                ];

                // Try checkouts metric (may not be available)
                try {
                    $checkoutRequest = new RunReportRequest([
                        'property' => "properties/{$this->propertyId}",
                        'date_ranges' => [$dateRange],
                        'metrics' => [new Metric(['name' => 'checkouts'])],
                    ]);
                    $checkoutResponse = $client->runReport($checkoutRequest);
                    $checkoutRow = $checkoutResponse->getRows()[0] ?? null;
                    if ($checkoutRow) {
                        $result['checkouts'] = (int) $checkoutRow->getMetricValues()[0]->getValue();
                    }
                } catch (\Throwable $e) {
                    // checkouts metric not available
                }

                return $result;
            } catch (\Throwable $e) {
                Log::error('GA4 getFunnelData failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getDeviceBreakdown(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:device:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $dateRange = new DateRange(['start_date' => $startDate, 'end_date' => $endDate]);

                $metrics = [
                    new Metric(['name' => 'sessions']),
                    new Metric(['name' => 'totalUsers']),
                    new Metric(['name' => 'ecommercePurchases']),
                    new Metric(['name' => 'totalRevenue']),
                ];

                $hasEngagement = true;
                try {
                    $metrics[] = new Metric(['name' => 'engagementRate']);
                } catch (\Throwable $e) {
                    $hasEngagement = false;
                }

                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [$dateRange],
                    'dimensions' => [new Dimension(['name' => 'deviceCategory'])],
                    'metrics' => $metrics,
                ]);

                $response = $client->runReport($request);
                $rows = [];

                foreach ($response->getRows() as $row) {
                    $device = $row->getDimensionValues()[0]->getValue();
                    $vals = $row->getMetricValues();
                    $sessions = (int) $vals[0]->getValue();
                    $purchases = (int) $vals[2]->getValue();

                    $rows[] = [
                        'device' => $device,
                        'sessions' => $sessions,
                        'users' => (int) $vals[1]->getValue(),
                        'purchases' => $purchases,
                        'revenue' => round((float) $vals[3]->getValue(), 2),
                        'engagement_rate' => $hasEngagement && isset($vals[4])
                            ? round((float) $vals[4]->getValue() * 100, 2)
                            : 0,
                        'conversion_rate' => $sessions > 0 ? round($purchases / $sessions * 100, 2) : 0,
                    ];
                }

                return $rows;
            } catch (\Throwable $e) {
                Log::error('GA4 getDeviceBreakdown failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getCityBreakdown(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:city:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
                    'dimensions' => [new Dimension(['name' => 'city'])],
                    'metrics' => [
                        new Metric(['name' => 'sessions']),
                        new Metric(['name' => 'totalUsers']),
                        new Metric(['name' => 'ecommercePurchases']),
                        new Metric(['name' => 'totalRevenue']),
                    ],
                    'limit' => 50,
                ]);

                $response = $client->runReport($request);
                $rows = [];

                foreach ($response->getRows() as $row) {
                    $city = $row->getDimensionValues()[0]->getValue();
                    if ($city === '(not set)') {
                        continue;
                    }

                    $vals = $row->getMetricValues();
                    $rows[] = [
                        'city' => $city,
                        'sessions' => (int) $vals[0]->getValue(),
                        'users' => (int) $vals[1]->getValue(),
                        'purchases' => (int) $vals[2]->getValue(),
                        'revenue' => round((float) $vals[3]->getValue(), 2),
                    ];
                }

                return $rows;
            } catch (\Throwable $e) {
                Log::error('GA4 getCityBreakdown failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getCampaigns(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:campaigns:v2:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $dateRange = new DateRange(['start_date' => $startDate, 'end_date' => $endDate]);

                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [$dateRange],
                    'dimensions' => [
                        new Dimension(['name' => 'sessionCampaignName']),
                        new Dimension(['name' => 'sessionSourceMedium']),
                    ],
                    'metrics' => [
                        new Metric(['name' => 'sessions']),
                        new Metric(['name' => 'totalUsers']),
                        new Metric(['name' => 'newUsers']),
                        new Metric(['name' => 'ecommercePurchases']),
                        new Metric(['name' => 'totalRevenue']),
                        new Metric(['name' => 'engagementRate']),
                    ],
                    'limit' => 2000,
                ]);

                $response = $client->runReport($request);

                // Reklam maliyeti metrikleri sadece Google Ads bağlıysa döner
                $costData = [];
                try {
                    $costRequest = new RunReportRequest([
                        'property' => "properties/{$this->propertyId}",
                        'date_ranges' => [$dateRange],
                        'dimensions' => [new Dimension(['name' => 'sessionCampaignName'])],
                        'metrics' => [
                            new Metric(['name' => 'advertiserAdCost']),
                            new Metric(['name' => 'advertiserAdClicks']),
                            new Metric(['name' => 'advertiserAdImpressions']),
                        ],
                        'limit' => 500,
                    ]);
                    $costResponse = $client->runReport($costRequest);
                    foreach ($costResponse->getRows() as $row) {
                        $costData[$row->getDimensionValues()[0]->getValue()] = [
                            'ad_cost' => (float) $row->getMetricValues()[0]->getValue(),
                            'ad_clicks' => (int) $row->getMetricValues()[1]->getValue(),
                            'ad_impressions' => (int) $row->getMetricValues()[2]->getValue(),
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::info('GA4 ad cost metrics not available', ['error' => $e->getMessage()]);
                }

                // Kampanya bazında topla; kanal = en çok oturum getiren source/medium
                $agg = [];
                foreach ($response->getRows() as $row) {
                    $dims = $row->getDimensionValues();
                    $campaign = $dims[0]->getValue();
                    $sourceMedium = $dims[1]->getValue();
                    $vals = $row->getMetricValues();
                    $sessions = (int) $vals[0]->getValue();

                    if (! isset($agg[$campaign])) {
                        $agg[$campaign] = [
                            'campaign' => $campaign,
                            'sessions' => 0, 'users' => 0, 'new_users' => 0,
                            'purchases' => 0, 'revenue' => 0.0,
                            'engagement_weighted' => 0.0,
                            'sources' => [],
                        ];
                    }

                    $agg[$campaign]['sessions'] += $sessions;
                    $agg[$campaign]['users'] += (int) $vals[1]->getValue();
                    $agg[$campaign]['new_users'] += (int) $vals[2]->getValue();
                    $agg[$campaign]['purchases'] += (int) $vals[3]->getValue();
                    $agg[$campaign]['revenue'] += (float) $vals[4]->getValue();
                    $agg[$campaign]['engagement_weighted'] += (float) $vals[5]->getValue() * $sessions;
                    $agg[$campaign]['sources'][$sourceMedium] = ($agg[$campaign]['sources'][$sourceMedium] ?? 0) + $sessions;
                }

                $rows = [];
                foreach ($agg as $a) {
                    arsort($a['sources']);
                    $topSource = array_key_first($a['sources']) ?? '';
                    $sessions = $a['sessions'];
                    $purchases = $a['purchases'];
                    $revenue = round($a['revenue'], 2);
                    $cost = $costData[$a['campaign']]['ad_cost'] ?? 0.0;

                    $rows[] = [
                        'campaign' => $a['campaign'],
                        'channel' => self::classifyChannel($topSource),
                        'source_medium' => $topSource,
                        'sessions' => $sessions,
                        'users' => $a['users'],
                        'new_users' => $a['new_users'],
                        'purchases' => $purchases,
                        'revenue' => $revenue,
                        'engagement_rate' => $sessions > 0 ? round($a['engagement_weighted'] / $sessions * 100, 2) : 0,
                        'conversion_rate' => $sessions > 0 ? round($purchases / $sessions * 100, 2) : 0,
                        'ad_cost' => round($cost, 2),
                        'ad_clicks' => $costData[$a['campaign']]['ad_clicks'] ?? 0,
                        'ad_impressions' => $costData[$a['campaign']]['ad_impressions'] ?? 0,
                        'cost_source' => $cost > 0 ? 'google' : null,
                        'roas' => $cost > 0 ? round($revenue / $cost, 2) : null,
                    ];
                }

                usort($rows, fn ($x, $y) => $y['revenue'] <=> $x['revenue']);

                return ['rows' => $rows, 'has_ad_cost' => $costData !== []];
            } catch (\Throwable $e) {
                Log::error('GA4 getCampaigns failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /** source/medium → kanal grubu (meta | google | other). */
    public static function classifyChannel(string $sourceMedium): string
    {
        $sm = mb_strtolower($sourceMedium);
        [$source] = array_pad(explode(' / ', $sm, 2), 1, '');

        $metaSources = ['ig', 'fb', 'facebook', 'instagram', 'meta', 'msg', 'an', 'audience_network'];
        if (in_array(trim($source), $metaSources, true)
            || str_contains($sm, 'facebook') || str_contains($sm, 'instagram')) {
            return 'meta';
        }

        if (str_contains($source, 'google') || str_contains($sm, 'googleads') || str_contains($sm, 'youtube')) {
            return 'google';
        }

        return 'other';
    }

    public function getLandingPages(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:landing:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
                    'dimensions' => [new Dimension(['name' => 'landingPage'])],
                    'metrics' => [
                        new Metric(['name' => 'sessions']),
                        new Metric(['name' => 'totalUsers']),
                        new Metric(['name' => 'newUsers']),
                        new Metric(['name' => 'ecommercePurchases']),
                        new Metric(['name' => 'totalRevenue']),
                        new Metric(['name' => 'engagementRate']),
                        new Metric(['name' => 'bounceRate']),
                    ],
                    'limit' => 300,
                ]);

                $response = $client->runReport($request);
                $rows = [];

                foreach ($response->getRows() as $row) {
                    $page = $row->getDimensionValues()[0]->getValue();
                    if ($page === '(not set)') {
                        continue;
                    }

                    $vals = $row->getMetricValues();
                    $sessions = (int) $vals[0]->getValue();
                    $purchases = (int) $vals[3]->getValue();

                    $rows[] = [
                        'landing_page' => $page,
                        'sessions' => $sessions,
                        'users' => (int) $vals[1]->getValue(),
                        'new_users' => (int) $vals[2]->getValue(),
                        'purchases' => $purchases,
                        'revenue' => round((float) $vals[4]->getValue(), 2),
                        'engagement_rate' => round((float) $vals[5]->getValue() * 100, 2),
                        'bounce_rate' => round((float) $vals[6]->getValue() * 100, 2),
                        'conversion_rate' => $sessions > 0 ? round($purchases / $sessions * 100, 2) : 0,
                    ];
                }

                return $rows;
            } catch (\Throwable $e) {
                Log::error('GA4 getLandingPages failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getSearchTerms(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:searchterms:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
                    'dimensions' => [new Dimension(['name' => 'searchTerm'])],
                    'metrics' => [
                        new Metric(['name' => 'eventCount']),
                        new Metric(['name' => 'sessions']),
                        new Metric(['name' => 'totalUsers']),
                        new Metric(['name' => 'ecommercePurchases']),
                        new Metric(['name' => 'totalRevenue']),
                    ],
                    'limit' => 500,
                ]);

                $response = $client->runReport($request);
                $rows = [];

                foreach ($response->getRows() as $row) {
                    $term = $row->getDimensionValues()[0]->getValue();
                    if ($term === '' || $term === '(not set)') {
                        continue;
                    }

                    $vals = $row->getMetricValues();
                    $rows[] = [
                        'term' => $term,
                        'searches' => (int) $vals[0]->getValue(),
                        'sessions' => (int) $vals[1]->getValue(),
                        'users' => (int) $vals[2]->getValue(),
                        'purchases' => (int) $vals[3]->getValue(),
                        'revenue' => round((float) $vals[4]->getValue(), 2),
                    ];
                }

                return $rows;
            } catch (\Throwable $e) {
                Log::error('GA4 getSearchTerms failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getDemographics(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:demographics:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $dateRange = new DateRange(['start_date' => $startDate, 'end_date' => $endDate]);
                $metrics = [
                    new Metric(['name' => 'sessions']),
                    new Metric(['name' => 'totalUsers']),
                    new Metric(['name' => 'ecommercePurchases']),
                    new Metric(['name' => 'totalRevenue']),
                ];

                $result = ['age' => [], 'gender' => []];

                foreach (['age' => 'userAgeBracket', 'gender' => 'userGender'] as $key => $dimName) {
                    $request = new RunReportRequest([
                        'property' => "properties/{$this->propertyId}",
                        'date_ranges' => [$dateRange],
                        'dimensions' => [new Dimension(['name' => $dimName])],
                        'metrics' => $metrics,
                    ]);

                    $response = $client->runReport($request);
                    foreach ($response->getRows() as $row) {
                        $bucket = $row->getDimensionValues()[0]->getValue();
                        if ($bucket === 'unknown' || $bucket === '(not set)') {
                            continue;
                        }

                        $vals = $row->getMetricValues();
                        $result[$key][] = [
                            'bucket' => $bucket,
                            'sessions' => (int) $vals[0]->getValue(),
                            'users' => (int) $vals[1]->getValue(),
                            'purchases' => (int) $vals[2]->getValue(),
                            'revenue' => round((float) $vals[3]->getValue(), 2),
                        ];
                    }
                }

                return $result;
            } catch (\Throwable $e) {
                Log::error('GA4 getDemographics failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getHourlyTraffic(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:hourly:{$startDate}:{$endDate}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($startDate, $endDate) {
            try {
                $client = $this->makeClient();
                $request = new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
                    'dimensions' => [
                        new Dimension(['name' => 'dayOfWeek']),
                        new Dimension(['name' => 'hour']),
                    ],
                    'metrics' => [
                        new Metric(['name' => 'sessions']),
                        new Metric(['name' => 'ecommercePurchases']),
                        new Metric(['name' => 'totalRevenue']),
                    ],
                    'limit' => 200,
                ]);

                $response = $client->runReport($request);
                $rows = [];

                foreach ($response->getRows() as $row) {
                    $dims = $row->getDimensionValues();
                    $vals = $row->getMetricValues();
                    $rows[] = [
                        'day_of_week' => (int) $dims[0]->getValue(), // 0=Pazar (GA4)
                        'hour' => (int) $dims[1]->getValue(),
                        'sessions' => (int) $vals[0]->getValue(),
                        'purchases' => (int) $vals[1]->getValue(),
                        'revenue' => round((float) $vals[2]->getValue(), 2),
                    ];
                }

                return $rows;
            } catch (\Throwable $e) {
                Log::error('GA4 getHourlyTraffic failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    public function getRealtime(): ?array
    {
        $tenantId = $this->extractTenantId();
        $cacheKey = "ga4:{$tenantId}:realtime";

        // Realtime 60 sn cache — API kotasını korur
        return Cache::remember($cacheKey, 60, function () {
            try {
                $client = $this->makeClient();

                $totalRequest = new RunRealtimeReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'metrics' => [new Metric(['name' => 'activeUsers'])],
                ]);
                $totalResponse = $client->runRealtimeReport($totalRequest);
                $activeUsers = 0;
                if ($row = $totalResponse->getRows()[0] ?? null) {
                    $activeUsers = (int) $row->getMetricValues()[0]->getValue();
                }

                $devices = [];
                try {
                    $deviceRequest = new RunRealtimeReportRequest([
                        'property' => "properties/{$this->propertyId}",
                        'dimensions' => [new Dimension(['name' => 'deviceCategory'])],
                        'metrics' => [new Metric(['name' => 'activeUsers'])],
                    ]);
                    foreach ($client->runRealtimeReport($deviceRequest)->getRows() as $row) {
                        $devices[] = [
                            'device' => $row->getDimensionValues()[0]->getValue(),
                            'active_users' => (int) $row->getMetricValues()[0]->getValue(),
                        ];
                    }
                } catch (\Throwable $e) {
                    // cihaz kırılımı opsiyonel
                }

                $pages = [];
                try {
                    $pageRequest = new RunRealtimeReportRequest([
                        'property' => "properties/{$this->propertyId}",
                        'dimensions' => [new Dimension(['name' => 'unifiedScreenName'])],
                        'metrics' => [new Metric(['name' => 'activeUsers'])],
                        'limit' => 10,
                    ]);
                    foreach ($client->runRealtimeReport($pageRequest)->getRows() as $row) {
                        $pages[] = [
                            'page' => $row->getDimensionValues()[0]->getValue(),
                            'active_users' => (int) $row->getMetricValues()[0]->getValue(),
                        ];
                    }
                } catch (\Throwable $e) {
                    // sayfa kırılımı opsiyonel
                }

                return [
                    'active_users' => $activeUsers,
                    'devices' => $devices,
                    'pages' => $pages,
                ];
            } catch (\Throwable $e) {
                Log::error('GA4 getRealtime failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * Web (platform=web) günlük satın alma: transactions + purchaseRevenue.
     * Ölçüm denetimi çapraz kontrolü için. Y-m-d => ['transactions','revenue']
     */
    public function getWebPurchasesDaily(string $startDate, string $endDate): ?array
    {
        $tenantId = $this->extractTenantId();

        return Cache::remember("ga4:{$tenantId}:web_purchases:{$startDate}:{$endDate}", 1800, function () use ($startDate, $endDate) {
            try {
                $response = $this->makeClient()->runReport(new RunReportRequest([
                    'property' => "properties/{$this->propertyId}",
                    'date_ranges' => [new DateRange(['start_date' => $startDate, 'end_date' => $endDate])],
                    'dimensions' => [new Dimension(['name' => 'date']), new Dimension(['name' => 'platform'])],
                    'metrics' => [new Metric(['name' => 'transactions']), new Metric(['name' => 'purchaseRevenue'])],
                    'limit' => 1000,
                ]));
                $out = [];
                foreach ($response->getRows() as $row) {
                    $d = $row->getDimensionValues();
                    if (strtolower($d[1]->getValue()) !== 'web') {
                        continue;
                    }
                    $raw = $d[0]->getValue();
                    $date = substr($raw, 0, 4).'-'.substr($raw, 4, 2).'-'.substr($raw, 6, 2);
                    $m = $row->getMetricValues();
                    $out[$date] = ['transactions' => (int) $m[0]->getValue(), 'revenue' => (float) $m[1]->getValue()];
                }

                return $out;
            } catch (\Throwable $e) {
                Log::error('GA4 getWebPurchasesDaily failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    protected function makeClient(): BetaAnalyticsDataClient
    {
        $credentials = $this->credentialsPath;

        // Encrypted file: decrypt and pass as array
        if (str_ends_with($this->credentialsPath, '.enc')) {
            $encrypted = file_get_contents($this->credentialsPath);
            $credentials = json_decode(Crypt::decryptString($encrypted), true);
        }

        return new BetaAnalyticsDataClient([
            'credentials' => $credentials,
            'transport' => 'rest',
        ]);
    }

    protected function extractTenantId(): string
    {
        // Extract tenant ID from credentials path: storage/app/ga4/{id}.json
        return pathinfo($this->credentialsPath, PATHINFO_FILENAME);
    }
}
