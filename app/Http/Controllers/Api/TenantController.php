<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    private const ALL_PAGES = ['dashboard', 'messages', 'comments', 'email_archive', 'calls', 'settings', 'crm', 'ads', 'ads_analytics', 'reports', 'returns', 'marketing', 'cro'];

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Yetkisiz erisim.'], 403);
        }

        $tenants = Tenant::withCount(['users', 'channels', 'conversations'])
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'is_active' => $t->is_active,
                'users_count' => $t->users_count,
                'channels_count' => $t->channels_count,
                'conversations_count' => $t->conversations_count,
                'settings' => $t->settings,
                'created_at' => $t->created_at?->toISOString(),
            ]);

        return response()->json($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Yetkisiz erisim.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'sometimes|string|max:100|unique:tenants,slug',
            'admin_name' => 'required|string|max:255',
            // E-posta benzersizliği tenant-bazlı; yeni tenant'ın kullanıcısı yok,
            // başka firmada aynı e-postanın olması artık engel değil.
            'admin_email' => 'required|email',
            'admin_password' => ['required', 'string', Password::min(10)->letters()->numbers()],
            'allowed_pages' => 'sometimes|array',
            'allowed_pages.*' => 'string|in:' . implode(',', self::ALL_PAGES),
            'products' => 'sometimes|array',
            'products.*' => 'string|in:' . implode(',', Tenant::PRODUCTS),
        ]);

        $slug = $validated['slug'] ?? Str::slug($validated['name']);

        // Slug unique check (if auto-generated)
        if (Tenant::where('slug', $slug)->exists()) {
            $slug = $slug . '-' . Str::random(4);
        }

        $allowedPages = $validated['allowed_pages'] ?? self::ALL_PAGES;
        $products = $validated['products'] ?? Tenant::PRODUCTS;

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'is_active' => true,
            'settings' => ['allowed_pages' => $allowedPages, 'products' => $products],
        ]);

        // Create admin user for this tenant
        $admin = new User([
            'name' => $validated['admin_name'],
            'email' => $validated['admin_email'],
            'password' => $validated['admin_password'],
            'role' => 'admin',
            'status' => 'active',
        ]);
        $admin->tenant_id = $tenant->id;
        $admin->save();

        // Create default channels for this tenant
        $this->createDefaultChannels($tenant->id);

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'is_active' => $tenant->is_active,
            'settings' => $tenant->settings,
            'admin' => [
                'id' => $admin->id,
                'email' => $admin->email,
            ],
        ], 201);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Yetkisiz erisim.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:100|unique:tenants,slug,' . $tenant->id,
            'is_active' => 'sometimes|boolean',
            'allowed_pages' => 'sometimes|array',
            'allowed_pages.*' => 'string|in:' . implode(',', self::ALL_PAGES),
            'products' => 'sometimes|array',
            'products.*' => 'string|in:' . implode(',', Tenant::PRODUCTS),
        ]);

        $data = collect($validated)->except(['allowed_pages', 'products'])->toArray();

        // Update allowed_pages / products in settings if provided
        if (isset($validated['allowed_pages']) || isset($validated['products'])) {
            $settings = $tenant->settings ?? [];
            if (isset($validated['allowed_pages'])) {
                $settings['allowed_pages'] = $validated['allowed_pages'];
            }
            if (isset($validated['products'])) {
                $settings['products'] = $validated['products'];
            }
            $data['settings'] = $settings;
        }

        $tenant->update($data);

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'is_active' => $tenant->is_active,
            'settings' => $tenant->settings,
        ]);
    }

    /**
     * Create default channels for a new tenant
     */
    protected function createDefaultChannels(int $tenantId): void
    {
        $defaults = [
            ['type' => 'facebook-message',   'name' => 'Facebook Messenger', 'category' => 'Gelen Mesaj'],
            ['type' => 'facebook-comment',   'name' => 'Facebook',           'category' => 'Yorum'],
            ['type' => 'instagram-message',  'name' => 'Instagram',          'category' => 'Gelen Mesaj'],
            ['type' => 'instagram-comment',  'name' => 'Instagram',          'category' => 'Yorum'],
            ['type' => 'trendyol-product',   'name' => 'Trendyol',           'category' => 'Ürün Sorusu'],
            ['type' => 'trendyol-order',     'name' => 'Trendyol',           'category' => 'Sipariş Sorusu'],
            ['type' => 'hepsiburada-product','name' => 'Hepsiburada',        'category' => 'Ürün Sorusu'],
            ['type' => 'hepsiburada-order',  'name' => 'Hepsiburada',        'category' => 'Sipariş Sorusu'],
            ['type' => 'ticimax-ticket',     'name' => 'Ticimax',            'category' => 'Destek Talebi'],
            ['type' => 'whatsapp',           'name' => 'WhatsApp',           'category' => 'Gelen Mesaj'],
            ['type' => 'email',              'name' => 'E-posta',            'category' => 'E-posta'],
        ];

        foreach ($defaults as $ch) {
            Channel::create([
                'tenant_id'   => $tenantId,
                'type'        => $ch['type'],
                'name'        => $ch['name'],
                'category'    => $ch['category'],
                'is_active'   => false,
                'credentials' => [],
                'settings'    => [],
            ]);
        }
    }

    /**
     * ⚠️ Tenant'ı TÜM verisiyle kalıcı siler (DB + sunucu dosyaları).
     * Yalnız süper admin; onay için tenant slug'ı birebir gönderilmek zorunda.
     */
    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Yetkisiz erisim.'], 403);
        }

        $request->validate(['confirm_slug' => 'required|string']);

        if ($request->confirm_slug !== $tenant->slug) {
            return response()->json(['message' => 'Onay için firma slug\'ı birebir yazılmalı.'], 422);
        }

        \Illuminate\Support\Facades\Artisan::call('tenant:purge', [
            'id' => $tenant->id,
            'slug' => $tenant->slug,
            '--force' => true,
        ]);

        $output = trim(\Illuminate\Support\Facades\Artisan::output());
        $ok = str_contains($output, 'kalıcı olarak silindi');

        return response()->json([
            'message' => $ok ? 'Firma ve tüm verileri kalıcı olarak silindi.' : 'Silme tamamlanamadı — log kontrol edin.',
            'detail' => mb_substr($output, 0, 2000),
        ], $ok ? 200 : 500);
    }
}
