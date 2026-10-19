<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kullanıcı aktivitesi raporu (firma yöneticisi + süper admin).
 * Tüm hesaplama indeksli tek sorgularla; PHP tarafına yalnızca tek kullanıcının
 * son N gün satırları çekilir (üst sınır 5.000).
 */
class UserActivityController extends Controller
{
    /** Firma kullanıcılarının özet tablosu. */
    public function summary(Request $request): JsonResponse
    {
        $days = min(max((int) $request->integer('days', 30), 1), UserActivityRecorder::RETENTION_DAYS);
        $since = now()->subDays($days);

        $users = $this->scopedUsers($request)
            ->select('id', 'name', 'email', 'role', 'status', 'tenant_id', 'created_at', 'last_login_at')
            ->orderBy('name')
            ->get();
        $ids = $users->pluck('id')->all();
        if (! $ids) {
            return response()->json(['days' => $days, 'users' => []]);
        }

        $logins = DB::table('user_logins')
            ->selectRaw('user_id, min(created_at) first_at, max(created_at) last_at, count(*) filter (where created_at >= ?) cnt', [$since])
            ->whereIn('user_id', $ids)->where('kind', 'login')
            ->groupBy('user_id')->get()->keyBy('user_id');

        // Aktif süre: ardışık görüntülemeler arası fark (30 dk üstü boşluk = yeni oturum, 1 dk sayılır)
        $gap = UserActivityRecorder::SESSION_GAP_MINUTES;
        $views = collect(DB::select("
            with v as (
                select user_id, created_at,
                       lag(created_at) over (partition by user_id order by created_at) prev
                from user_page_views
                where user_id in (" . implode(',', array_map('intval', $ids)) . ") and created_at >= ?
            )
            select user_id,
                   count(*) views,
                   max(created_at) last_seen,
                   sum(case when prev is null or created_at - prev > interval '{$gap} minutes' then 1 else 0 end) sessions,
                   coalesce(sum(extract(epoch from (case when prev is null or created_at - prev > interval '{$gap} minutes'
                                                         then interval '1 minute' else created_at - prev end))), 0) active_seconds
            from v group by user_id
        ", [$since]))->keyBy('user_id');

        $rows = $users->map(function (User $u) use ($logins, $views) {
            $l = $logins->get($u->id);
            $v = $views->get($u->id);

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'status' => $u->status,
                'is_super_admin' => $u->isSuperAdmin(),
                'created_at' => $u->created_at?->toISOString(),
                'first_login_at' => $l ? $this->iso($l->first_at) : null,
                'last_login_at' => $l ? $this->iso($l->last_at) : $u->last_login_at?->toISOString(),
                'login_count' => (int) ($l->cnt ?? 0),
                'last_seen_at' => $v ? $this->iso($v->last_seen) : null,
                'sessions' => (int) ($v->sessions ?? 0),
                'page_views' => (int) ($v->views ?? 0),
                'active_minutes' => (int) round(($v->active_seconds ?? 0) / 60),
            ];
        });

        return response()->json(['days' => $days, 'users' => $rows]);
    }

    /** Tek kullanıcının giriş listesi + oturum/sayfa zaman çizelgesi. */
    public function show(Request $request, User $user): JsonResponse
    {
        if (! $this->scopedUsers($request)->whereKey($user->id)->exists()) {
            return response()->json(['message' => 'Kullanıcı bulunamadı.'], 404);
        }
        $days = min(max((int) $request->integer('days', 30), 1), UserActivityRecorder::RETENTION_DAYS);
        $since = now()->subDays($days);

        $logins = DB::table('user_logins')
            ->where('user_id', $user->id)->where('created_at', '>=', $since)
            ->orderByDesc('created_at')->limit(200)
            ->get()->map(fn ($r) => [
                'at' => $this->iso($r->created_at),
                'kind' => $r->kind,
                'ip' => $r->ip,
                'device' => $this->device($r->user_agent),
            ]);

        $views = DB::table('user_page_views')
            ->select('page', 'ip', 'created_at')
            ->where('user_id', $user->id)->where('created_at', '>=', $since)
            ->orderBy('created_at')->limit(5000)->get();

        // Oturumlara böl
        $gapSec = UserActivityRecorder::SESSION_GAP_MINUTES * 60;
        $sessions = [];
        $cur = null;
        foreach ($views as $v) {
            $ts = strtotime($v->created_at);
            if ($cur === null || $ts - $cur['_last'] > $gapSec) {
                if ($cur) {
                    $sessions[] = $this->closeSession($cur);
                }
                $cur = ['start' => $ts, '_last' => $ts, 'ip' => $v->ip, 'pages' => []];
            } else {
                // önceki sayfanın süresi = bu görüntülemeye kadar geçen zaman
                $n = count($cur['pages']);
                $cur['pages'][$n - 1]['seconds'] = $ts - $cur['pages'][$n - 1]['_ts'];
            }
            $cur['pages'][] = ['page' => $v->page, 'at' => date('c', $ts), '_ts' => $ts, 'seconds' => null];
            $cur['_last'] = $ts;
        }
        if ($cur) {
            $sessions[] = $this->closeSession($cur);
        }

        $top = collect($views)->groupBy('page')->map(fn ($g, $p) => ['page' => $p, 'views' => $g->count()])
            ->sortByDesc('views')->values()->take(15);

        return response()->json([
            'days' => $days,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'created_at' => $user->created_at?->toISOString()],
            'logins' => $logins,
            'sessions' => array_reverse($sessions), // en yeni üstte
            'top_pages' => $top,
            'truncated' => $views->count() >= 5000,
        ]);
    }

    private function closeSession(array $s): array
    {
        $pages = array_map(function ($p) {
            unset($p['_ts']);

            return $p;
        }, $s['pages']);
        $minutes = max(1, (int) round(($s['_last'] - $s['start']) / 60));

        return [
            'start' => date('c', $s['start']),
            'end' => date('c', $s['_last']),
            'minutes' => $minutes,
            'ip' => $s['ip'],
            'pages' => $pages,
        ];
    }

    /** UserController::index ile aynı tenant kapsamı. */
    private function scopedUsers(Request $request)
    {
        $me = $request->user();
        $q = User::query();
        if ($me->isSuperAdmin()) {
            if ($request->hasHeader('X-Tenant-Id')) {
                $q->where('tenant_id', (int) $request->header('X-Tenant-Id'));
            }
        } else {
            $q->where('tenant_id', $me->tenant_id);
        }

        return $q;
    }

    private function iso($ts): ?string
    {
        return $ts ? \Carbon\Carbon::parse($ts)->toISOString() : null;
    }

    private function device(?string $ua): string
    {
        $ua = (string) $ua;
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };
        $br = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => '',
        };

        return trim($os . ' ' . $br) ?: '—';
    }
}
