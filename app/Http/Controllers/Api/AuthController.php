<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Progressive lockout: 10 başarısız denemeden sonra 15 dakika kilitle.
        // GÜVENLİK: kilit email+IP'ye bağlıdır — böylece saldırgan sadece kurbanın e-postasına
        // deneme yaparak (farklı IP'den) meşru kullanıcının hesabını kilitleyemez (targeted DoS).
        $lockoutKey = 'login_attempts:' . strtolower($request->email) . ':' . $request->ip();
        $attempts = (int) Cache::get($lockoutKey, 0);
        if ($attempts >= 10) {
            throw ValidationException::withMessages([
                'email' => ['Çok fazla başarısız deneme. 15 dakika sonra tekrar deneyin.'],
            ]);
        }

        // Aynı e-posta birden fazla firmada bulunabilir (unique tenant-bazlı).
        // Parola hangi hesaba aitse onunla girilir; süper admin hesabı önceliklidir.
        // PASİF firmanın kullanıcısı giriş yapamaz (aday olarak da elenmez ki
        // aynı e-posta aktif başka firmada varsa oraya girebilsin).
        $user = User::with('tenant')
            ->where('email', $request->email)
            ->orderByRaw('tenant_id IS NOT NULL')
            ->orderBy('id')
            ->get()
            ->first(fn (User $u) => Hash::check($request->password, $u->password)
                && ($u->tenant_id === null || (bool) $u->tenant?->is_active));

        if (! $user) {
            Cache::put($lockoutKey, $attempts + 1, now()->addMinutes(15));
            throw ValidationException::withMessages([
                'email' => ['Girdiğiniz bilgiler hatalı.'],
            ]);
        }

        // Başarılı giriş: sayacı sıfırla (doğru parolada kilit uygulanmaz)
        Cache::forget($lockoutKey);

        if ($user->status !== 'active' && ! $user->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['Hesabınız devre dışı bırakılmış.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);
        \App\Services\UserActivityRecorder::recordLogin($user, $request);

        // Eski tokenları temizle (güvenlik: çalınmış token geçersiz olur)
        $user->tokens()->delete();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Çıkış yapıldı.']);
    }

    /**
     * "Kullanıcı gözünden gör": süper admin, bir tenant kullanıcısının
     * paneline onun yetkileriyle bakar. 30 dk'lık ayrı impersonation token'ı
     * üretilir; hedef kullanıcının kendi oturumu etkilenmez (login'deki
     * tokens()->delete() bayat impersonation token'larını da süpürür).
     */
    public function impersonate(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if (! $actor->isSuperAdmin()) {
            return response()->json(['message' => 'Yalnızca süper admin kullanıcı gözünden görebilir.'], 403);
        }

        if ($user->isSuperAdmin()) {
            return response()->json(['message' => 'Süper admin hesabına geçilemez.'], 422);
        }

        // warning seviyesi bilinçli: LOG_LEVEL=warning altında audit izi kaybolmasın
        Log::warning('impersonation.start', [
            'super_admin_id' => $actor->id,
            'super_admin_email' => $actor->email,
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'tenant_id' => $user->tenant_id,
            'ip' => $request->ip(),
        ]);

        $token = $user->createToken('impersonation:sa' . $actor->id, ['*'], now()->addMinutes(30))->plainTextToken;
        \App\Services\UserActivityRecorder::recordLogin($user, $request, 'impersonation', $actor->id);

        $user->load('tenant');

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('tenant');

        return response()->json($this->formatUser($user));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user->update($request->only('name'));

        return response()->json([
            'message' => 'Profil güncellendi.',
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required',
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mevcut şifre hatalı.'],
            ]);
        }

        $user->update(['password' => Hash::make($request->password)]);

        // Güvenlik: parola değişince mevcut oturum hariç tüm token'ları iptal et — çalınmış
        // bir token parola değişikliğinden sonra geçerli kalmasın.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();

        return response()->json(['message' => 'Şifre başarıyla değiştirildi.']);
    }

    /**
     * Şirket içi CRM'e (crm.yunbi.ai) tek tıkla geçiş kodu — yalnızca süper admin.
     * 60 sn ömürlü tek kullanımlık Sanctum token'ı üretir; CRM tarafı hub DB'sinden
     * doğrulayıp SİLER (tek kullanım) ve kendi oturumunu açar.
     */
    public function crmSso(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Bu işlem için yetkiniz yok.'], 403);
        }

        $code = $user->createToken('crm-sso', ['crm-sso'], now()->addSeconds(60))->plainTextToken;

        return response()->json(['code' => $code]);
    }

    public function formatUser(User $user): array
    {
        $user->loadMissing('tenant');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'avatar' => $user->avatar,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'is_super_admin' => $user->isSuperAdmin(),
            'tenant' => $user->tenant ? [
                'id' => $user->tenant->id,
                'name' => $user->tenant->name,
                'slug' => $user->tenant->slug,
                'settings' => SettingsController::maskSettingsArray($user->tenant->settings ?? []),
            ] : null,
            'allowed_pages' => $user->isSuperAdmin()
                ? array_values(array_unique(array_merge(['settings'], ...array_values(\App\Models\Tenant::PRODUCT_PAGES))))
                : $user->getAllowedPages(),
            // SaaS: sahiplik, e-posta doğrulama ve abonelik özeti (bant/yönlendirme için)
            'is_owner' => (bool) $user->is_owner,
            'email_verified' => (bool) $user->email_verified_at,
            'subscription' => $user->tenant?->subscription?->load('plan')?->toPanelArray(),
            'onboarded_at' => $user->tenant?->onboarded_at?->toISOString(),
            // 7 alt ürün ekseni — menü/ana sayfa görünürlüğü buradan beslenir
            'allowed_products' => $user->getAllowedProducts(),
            // Kusur bildirimi onay/red butonu görünürlüğü (yalnızca firma yöneticisi + süper admin)
            'is_admin' => $user->isSuperAdmin() || $user->isAdmin(),
            // İade modülü görev ayrılığı yetkileri (buton görünürlüğü için)
            'return_role' => $user->returnRole()->value,
            'can_staff_approve_returns' => $user->canStaffApproveReturns(),
            'can_manager_approve_returns' => $user->canManagerApproveReturns(),
            // Personel notu, salt görüntüleyen personelde de açıktır (durum değiştirmez, müşteri görmez)
            'can_add_return_notes' => $user->canAddReturnNotes(),
        ];
    }
}
