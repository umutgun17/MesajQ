<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::with('channel:id,name,type')
            ->whereNotNull('platform_customer_id');

        // Search (name, surname, name+surname, email, phone)
        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('surname', 'ilike', "%{$search}%")
                  ->orWhereRaw("concat(name, ' ', surname) ilike ?", ["%{$search}%"])
                  ->orWhere('email', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        // Filters
        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        if ($request->filled('gender')) {
            $query->where('gender', (int) $request->gender);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('mail_consent')) {
            $query->where('mail_consent', filter_var($request->mail_consent, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('member_type')) {
            $query->where('member_type', $request->member_type);
        }

        if ($request->filled('channel_id')) {
            $query->where('channel_id', (int) $request->channel_id);
        }

        // Sorting
        $allowedSorts = ['name', 'email', 'city', 'total_orders', 'total_spent', 'platform_created_at', 'last_login_at', 'created_at'];
        $sortBy = in_array($request->sort_by, $allowedSorts) ? $request->sort_by : 'platform_created_at';
        $sortDir = $request->sort_dir === 'asc' ? 'asc' : 'desc';
        $query->orderByRaw("{$sortBy} {$sortDir} NULLS LAST");

        $perPage = min((int) ($request->per_page ?? 25), 100);

        return response()->json($query->paginate($perPage));
    }

    public function show(int $id): JsonResponse
    {
        $customer = Customer::with('channel:id,name,type')->findOrFail($id);

        // Find all related customer IDs (same person across ticket/CRM records)
        $relatedIds = collect([$customer->id]);

        $fullName = trim($customer->name . ' ' . ($customer->surname ?? ''));

        // Match by email
        if ($customer->email) {
            $byEmail = Customer::withoutGlobalScopes()
                ->where('tenant_id', $customer->tenant_id)
                ->where('email', $customer->email)
                ->where('id', '!=', $customer->id)
                ->pluck('id');
            $relatedIds = $relatedIds->merge($byEmail);
        }

        // Match by phone
        if ($customer->phone) {
            $byPhone = Customer::withoutGlobalScopes()
                ->where('tenant_id', $customer->tenant_id)
                ->where('phone', $customer->phone)
                ->where('id', '!=', $customer->id)
                ->pluck('id');
            $relatedIds = $relatedIds->merge($byPhone);
        }

        // Match by full name (ticket sync stores "Ad Soyad" in name field)
        if ($customer->surname) {
            $byName = Customer::withoutGlobalScopes()
                ->where('tenant_id', $customer->tenant_id)
                ->where('name', 'ilike', $fullName)
                ->where('id', '!=', $customer->id)
                ->pluck('id');
            $relatedIds = $relatedIds->merge($byName);
        }

        $relatedIds = $relatedIds->unique()->values();

        // Fetch conversations from all related customer records
        $conversations = Conversation::withoutGlobalScopes()
            ->whereIn('customer_id', $relatedIds)
            ->with(['channel:id,name,type', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->get();

        $data = $customer->toArray();
        $data['full_name'] = $fullName;
        $data['conversations'] = $conversations->toArray();

        if (isset($data['channel']['credentials'])) {
            unset($data['channel']['credentials']);
        }

        return response()->json($data);
    }

    public function stats(): JsonResponse
    {
        $base = Customer::whereNotNull('platform_customer_id');

        $total = (clone $base)->count();
        $active = (clone $base)->where('is_active', true)->count();

        $genderDistribution = (clone $base)->whereNotNull('gender')
            ->where('gender', '>', 0)
            ->selectRaw('gender, count(*) as count')
            ->groupBy('gender')
            ->get();

        $topCities = (clone $base)->whereNotNull('city')
            ->where('city', '!=', '')
            ->selectRaw('city, count(*) as count')
            ->groupBy('city')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $totalOrders = (clone $base)->sum('total_orders');
        $totalRevenue = (clone $base)->sum('total_spent');

        return response()->json([
            'total' => $total,
            'active' => $active,
            'gender_distribution' => $genderDistribution,
            'top_cities' => $topCities,
            'total_orders' => (int) $totalOrders,
            'total_revenue' => (float) $totalRevenue,
        ]);
    }

    public function cities(): JsonResponse
    {
        $cities = Customer::whereNotNull('platform_customer_id')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('city')
            ->selectRaw('count(*) as count')
            ->groupBy('city')
            ->orderBy('city')
            ->get();

        return response()->json($cities);
    }

    public function memberTypes(): JsonResponse
    {
        $types = Customer::whereNotNull('platform_customer_id')
            ->whereNotNull('member_type')
            ->where('member_type', '!=', '')
            ->select('member_type')
            ->selectRaw('count(*) as count')
            ->groupBy('member_type')
            ->orderBy('member_type')
            ->get();

        return response()->json($types);
    }
}
