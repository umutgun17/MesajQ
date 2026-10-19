<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with('tenant:id,name,settings');

        $user = $request->user();
        if ($user->isSuperAdmin()) {
            // Super admin: filter by X-Tenant-Id header, or show all
            if ($request->hasHeader('X-Tenant-Id')) {
                $query->where('tenant_id', (int) $request->header('X-Tenant-Id'));
            }
            // No header = show all users (including other super admins)
        } else {
            $query->where('tenant_id', $user->tenant_id);
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->paginate(min((int) $request->input('per_page', 20), 100));

        // Tenant ayarlarındaki sırlar (API anahtarları, kargo/ödeme şifreleri)
        // kullanıcı listesinde gezmesin — /settings ile aynı maskeleme
        $users->getCollection()->transform(function (User $u) {
            if ($u->tenant) {
                $u->tenant->settings = SettingsController::maskSettingsArray($u->tenant->settings ?? []);
            }

            return $u;
        });

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()],
            'role' => 'required|in:admin,agent',
            'is_super_admin' => 'sometimes|boolean',
            'return_role' => 'sometimes|in:none,staff,manager',
            'page_permissions' => 'sometimes|nullable|array',
        ]);

        // Super admin oluşturma: sadece mevcut super admin yapabilir
        if (! empty($validated['is_super_admin'])) {
            if (! $request->user()->isSuperAdmin()) {
                return response()->json(['message' => 'Super admin oluşturma yetkiniz yok.'], 403);
            }

            // E-posta benzersizliği tenant-bazlı; süper adminler kendi aralarında kontrol edilir
            if (User::whereNull('tenant_id')->where('email', $validated['email'])->exists()) {
                return response()->json(['message' => 'Bu e-posta ile bir süper admin zaten var.'], 422);
            }

            $user = new User([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => bcrypt($validated['password']),
                'role' => 'admin',
                'status' => 'active',
            ]);
            $user->tenant_id = null;
            $user->save();

            return response()->json($user, 201);
        }

        // Determine tenant_id: super admin uses X-Tenant-Id header, normal user uses own tenant
        $tenantId = $request->user()->tenant_id;
        if ($request->user()->isSuperAdmin() && $request->hasHeader('X-Tenant-Id')) {
            $tenantId = (int) $request->header('X-Tenant-Id');
        }

        if (! $tenantId) {
            return response()->json(['message' => 'Kullanici eklemek icin bir firma secmelisiniz.'], 422);
        }

        // E-posta benzersizliği yalnız aynı firma içinde aranır
        if (User::where('tenant_id', $tenantId)->where('email', $validated['email'])->exists()) {
            return response()->json(['message' => 'Bu e-posta bu firmada zaten kayıtlı.'], 422);
        }

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role' => $validated['role'],
            'status' => 'active',
            'return_role' => $validated['return_role'] ?? 'none',
            // Sayfa yetki matrisi yalnız temsilciler için anlamlıdır
            'page_permissions' => $validated['role'] === 'agent'
                ? $this->sanitizePagePermissions($validated['page_permissions'] ?? null)
                : null,
        ]);
        $user->tenant_id = $tenantId;
        $user->save();

        return response()->json($user, 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserAccess($request, $user);

        return response()->json($user);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserAccess($request, $user);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)->where(
                fn ($q) => $user->tenant_id ? $q->where('tenant_id', $user->tenant_id) : $q->whereNull('tenant_id')
            )],
            'password' => ['sometimes', 'nullable', 'string', Password::min(10)->letters()->numbers()],
            'role' => 'sometimes|in:admin,agent',
            'status' => 'sometimes|in:active,inactive',
            'return_role' => 'sometimes|in:none,staff,manager',
            'page_permissions' => 'sometimes|nullable|array',
        ]);

        $userData = collect($validated)->except(['password', 'page_permissions'])->toArray();
        if (! empty($validated['password'])) {
            $userData['password'] = bcrypt($validated['password']);
        }

        // Matris yalnız temsilcide tutulur; role admin'e dönerse temizlenir
        $newRole = $validated['role'] ?? $user->role;
        if ($newRole === 'agent') {
            if (array_key_exists('page_permissions', $validated)) {
                $userData['page_permissions'] = $this->sanitizePagePermissions($validated['page_permissions']);
            }
        } else {
            $userData['page_permissions'] = null;
        }

        $user->update($userData);

        return response()->json($user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserAccess($request, $user);

        // Kullanıcı kendini silemez
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Kendi hesabınızı silemezsiniz.'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'Kullanıcı silindi.']);
    }

    /**
     * Ürün→sayfa matrisini Tenant::PRODUCT_PAGES kataloğuna göre ayıklar.
     * Boş kalan matris null döner (= eski varsayılan: Mesaj operasyonel sayfaları).
     */
    private function sanitizePagePermissions(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $clean = [];
        foreach ($raw as $product => $pages) {
            $valid = array_values(array_intersect((array) $pages, Tenant::PRODUCT_PAGES[$product] ?? []));
            if ($valid) {
                $clean[$product] = $valid;
            }
        }

        return $clean ?: null;
    }

    private function authorizeUserAccess(Request $request, User $user): void
    {
        $currentUser = $request->user();
        if (!$currentUser->isSuperAdmin() && $user->tenant_id !== $currentUser->tenant_id) {
            abort(403, 'Bu kullanıcıya erişim yetkiniz yok.');
        }
    }
}
