<?php

namespace App\Http\Controllers\Api\Saas;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmailMail;
use App\Mail\WelcomeMail;
use App\Models\BillingEvent;
use App\Models\Channel;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Self-servis kayıt: firma + sahip kullanıcı + varsayılan kanallar + deneme aboneliği.
 * E-posta doğrulaması imzalı link ile; doğrulanmadan panel kullanılabilir ama bant gösterilir.
 */
class SignupController extends Controller
{
    public function __construct(private readonly SubscriptionManager $subscriptions) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company' => 'required|string|min:2|max:120',
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email:rfc|max:190',
            'phone' => 'nullable|string|max:30',
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()],
            'platform' => 'nullable|string|in:ticimax,ikas,other',
            'terms' => 'accepted',
            'website' => 'nullable|string|max:0', // honeypot
        ], [
            'terms.accepted' => 'Kullanım şartlarını ve KVKK aydınlatma metnini kabul etmelisiniz.',
            'website.max' => 'Geçersiz istek.',
        ]);

        $email = strtolower(trim($data['email']));
        // Aynı e-posta ile kayıtlı bir SAHİP hesabı varsa yeni firma açılmaz (çalışan davetleri ayrı).
        if (User::where('email', $email)->where('is_owner', true)->exists()) {
            throw ValidationException::withMessages(['email' => ['Bu e-posta ile zaten bir hesap var. Giriş yapın veya şifrenizi sıfırlayın.']]);
        }

        $slug = Str::slug($data['company']) ?: 'firma';
        if (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug .= '-' . Str::lower(Str::random(4));
        }

        [$tenant, $user] = DB::transaction(function () use ($data, $email, $slug, $request) {
            $tenant = Tenant::create([
                'name' => $data['company'],
                'slug' => $slug,
                'is_active' => true,
                'settings' => [
                    'products' => [],
                    'default_language' => 'tr',
                    'ecommerce_platform' => $data['platform'] ?? null,
                ],
            ]);
            $tenant->forceFill(['signup_source' => 'self', 'ecommerce_platform_hint' => $data['platform'] ?? null])->save();

            $user = new User([
                'name' => $data['name'],
                'email' => $email,
                'password' => $data['password'],
                'role' => 'admin',
                'status' => 'active',
            ]);
            $user->tenant_id = $tenant->id;
            $user->is_owner = true;
            $user->terms_accepted_at = now();
            $user->signup_ip = $request->ip();
            $user->save();

            foreach (config('saas.default_channels', []) as $ch) {
                Channel::create(['tenant_id' => $tenant->id, 'type' => $ch['type'], 'name' => $ch['name'], 'category' => $ch['category'], 'is_active' => false, 'credentials' => [], 'settings' => []]);
            }
            if (! empty($data['phone'])) {
                $tenant->billingProfile()->create(['phone' => $data['phone'], 'email' => $email, 'legal_name' => $data['company']]);
            }

            return [$tenant, $user];
        });

        $this->subscriptions->startTrial($tenant);
        BillingEvent::record($tenant->id, 'signup', ['email' => $email, 'ip' => $request->ip(), 'platform' => $data['platform'] ?? null]);

        $this->sendVerification($user);
        try {
            Mail::to($user->email)->queue(new WelcomeMail($user));
        } catch (\Throwable) {
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;
        $user->load('tenant');

        return response()->json([
            'token' => $token,
            'user' => app(\App\Http\Controllers\Api\AuthController::class)->formatUser($user),
        ], 201);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->email_verified_at) {
            return response()->json(['message' => 'E-posta zaten doğrulanmış.']);
        }
        $this->sendVerification($user);

        return response()->json(['message' => 'Doğrulama e-postası yeniden gönderildi.']);
    }

    /** İmzalı doğrulama linki (signed route) — tarayıcıdan tıklanır, panele yönlendirir. */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);
        $app = rtrim(config('saas.app_base'), '/');
        if (! hash_equals(sha1($user->email), $hash)) {
            return redirect($app . '/login?verify=invalid');
        }
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
            BillingEvent::record($user->tenant_id, 'email.verified');
        }

        return redirect($app . '/?verified=1');
    }

    private function sendVerification(User $user): void
    {
        $url = URL::temporarySignedRoute('saas.verify', now()->addDays(3), ['id' => $user->id, 'hash' => sha1($user->email)]);
        try {
            Mail::to($user->email)->queue(new VerifyEmailMail($user, $url));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('signup.verify_mail_failed', ['user' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
