<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Panel kullanıcı aktivitesini kaydeder. Tasarım ilkesi: sıcak yolu yormamak.
 *  - Giriş: 1 INSERT (zaten nadir).
 *  - Sayfa görüntüleme: önce Redis'te (user, sayfa) anahtarı var mı bakılır (~0,1 ms);
 *    varsa hiçbir şey yapılmaz. Yoksa 1 INSERT + anahtar DEDUPE_SECONDS boyunca set edilir.
 *    Böylece bir sayfadaki 5-15 API çağrısı ve polling tek satıra iner.
 *  - Her adım try/catch içinde: izleme hiçbir zaman asıl isteği düşürmez.
 */
class UserActivityRecorder
{
    /** Aynı kullanıcı + sayfa için tek satır penceresi (sn). */
    public const DEDUPE_SECONDS = 120;

    /** Bu kadar süre boşluk = yeni oturum (rapor tarafı). */
    public const SESSION_GAP_MINUTES = 30;

    /** Saklama süresi (gün). */
    public const RETENTION_DAYS = 90;

    public static function recordLogin(User $user, Request $request, string $kind = 'login', ?int $impersonatorId = null): void
    {
        try {
            DB::table('user_logins')->insert([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'kind' => $kind,
                'impersonator_id' => $impersonatorId,
                'ip' => substr((string) $request->ip(), 0, 45),
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('user-activity.login.failed', ['e' => $e->getMessage()]);
        }
    }

    /**
     * API isteğinden SPA sayfasını (Referer) çıkarır ve gerekiyorsa kaydeder.
     * terminate() aşamasında, yanıt gönderildikten sonra çağrılır.
     */
    public static function recordPageView(User $user, Request $request): void
    {
        try {
            $page = self::pageFromRequest($request);
            if ($page === null) {
                return;
            }

            $key = 'upv:' . $user->id . ':' . md5($page);
            // add() = SETNX: anahtar varsa false döner, DB'ye dokunulmaz
            if (! Cache::add($key, 1, self::DEDUPE_SECONDS)) {
                return;
            }

            DB::table('user_page_views')->insert([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'page' => $page,
                'ip' => substr((string) $request->ip(), 0, 45),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('user-activity.view.failed', ['e' => $e->getMessage()]);
        }
    }

    /** Referer'dan hub SPA yolunu çıkarır; id'leri {id} yapar. Hub dışı/boş ise null. */
    public static function pageFromRequest(Request $request): ?string
    {
        $ref = (string) $request->headers->get('referer', '');
        if ($ref === '') {
            return null;
        }
        $host = parse_url($ref, PHP_URL_HOST);
        if (! $host || strcasecmp($host, (string) $request->getHost()) !== 0) {
            return null;
        }
        $path = parse_url($ref, PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/app/')) {
            $path = substr($path, 4) ?: '/';
        }
        if ($path === '/login' || str_starts_with($path, '/iade')) {
            return null;
        }
        $path = preg_replace('#/\d+(?=/|$)#', '/{id}', $path);
        $path = rtrim($path, '/') ?: '/';

        return substr($path, 0, 160);
    }

    public static function prune(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $deleted = 0;
        foreach (['user_page_views', 'user_logins'] as $table) {
            // Parça parça sil: uzun kilit tutmasın
            do {
                $n = DB::table($table)->where('created_at', '<', $cutoff)->limit(5000)->delete();
                $deleted += $n;
            } while ($n > 0);
        }

        return $deleted;
    }
}
