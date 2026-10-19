<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class CallController extends Controller
{
    /**
     * IVR/anons destination pattern'leri
     */
    protected const IVR_PATTERNS = ['mesaidisi', 'oglearasi', 'ivr', 'karsilama', 'announcement', 'Puanlama', 'interactive'];

    protected function applyCallTypeFilter($query, ?string $callType): void
    {
        if (!$callType) return;

        $ivrCondition = implode(' OR ', array_map(fn($p) => "called ILIKE '%{$p}%'", self::IVR_PATTERNS));

        match ($callType) {
            'normal' => $query->where('direction', 'inbound')->whereRaw("NOT ({$ivrCondition})"),
            'normal_missed' => $query->where('direction', 'inbound')->whereRaw("NOT ({$ivrCondition})")->where('status', '!=', 'answered'),
            'ivr' => $query->where('direction', 'inbound')->whereRaw("({$ivrCondition})"),
            'outbound' => $query->where('direction', 'outbound'),
            default => null,
        };
    }

    public function index(Request $request): JsonResponse
    {
        $query = Call::query()->orderByDesc('started_at');

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Call type filter (normal, ivr, outbound)
        if ($request->filled('call_type')) {
            $this->applyCallTypeFilter($query, $request->call_type);
        }

        // Extension filter
        if ($request->filled('extension')) {
            $query->where('extension', $request->extension);
        }

        // Direction filter
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }

        // Date range filter (TR timezone)
        $this->applyDateFilter($query, $request);

        // Transcription filter
        if ($request->filled('transcribed')) {
            if ($request->transcribed === 'yes') {
                $query->whereNotNull('transcribed_at');
            } elseif ($request->transcribed === 'no') {
                $query->whereNull('transcribed_at');
            }
        }

        // Search
        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('caller', 'ilike', "%{$search}%")
                  ->orWhere('called', 'ilike', "%{$search}%")
                  ->orWhere('transcription', 'ilike', "%{$search}%")
                  ->orWhere('ai_summary', 'ilike', "%{$search}%");
            });
        }

        $perPage = min((int) ($request->per_page ?? 50), 100);
        $calls = $query->paginate($perPage);

        // Extension → kullanıcı adı eşleştirmesi (tenant'a göre — User modelinde
        // global tenant scope yok, aynı dahili farklı firmalarda olabilir)
        $tenantIds = $calls->getCollection()->pluck('tenant_id')->unique()->filter()->all();
        $userMap = User::whereNotNull('extension')
            ->when(! empty($tenantIds), fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->get(['tenant_id', 'extension', 'name'])
            ->keyBy(fn ($u) => $u->tenant_id . '|' . $u->extension);

        $calls->getCollection()->each(function ($call) use ($userMap) {
            $call->agent_name = $userMap[$call->tenant_id . '|' . $call->extension]->name ?? null;
        });

        return response()->json($calls);
    }

    public function show(Call $call): JsonResponse
    {
        if ($call->extension) {
            $call->agent_name = User::where('extension', $call->extension)->value('name');
        }

        return response()->json($call);
    }

    /**
     * Ses kaydını NetGSM'den proxy olarak stream et.
     * Audio tag Bearer header gönderemediği için token query param ile alınır.
     */
    public function stream(Request $request, Call $call)
    {
        // Token-based auth (audio tag can't send Authorization header)
        $token = $request->query('token');
        if (! $token) {
            return response()->json(['message' => 'Yetkilendirme gerekli.'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (! $accessToken || ($accessToken->expires_at && $accessToken->expires_at->isPast())) {
            return response()->json(['message' => 'Geçersiz veya süresi dolmuş token.'], 401);
        }

        // Tenant izolasyonu: bu route auth:sanctum/tenant grubunun dışında olduğu için
        // global scope uygulanmaz. Token sahibi başka firmanın kaydına erişemez
        // (super admin — tenant_id null — hariç).
        $tokenUser = $accessToken->tokenable;
        if ($tokenUser && $tokenUser->tenant_id && (int) $tokenUser->tenant_id !== (int) $call->tenant_id) {
            return response()->json(['message' => 'Bu kayda erişim yetkiniz yok.'], 403);
        }

        if (! $call->recording_url && ! $call->recording_path) {
            return response()->json(['message' => 'Ses kaydı bulunamadı.'], 404);
        }

        // Lokal dosya varsa doğrudan serve et (seeking destekli)
        if ($call->recording_path && $call->recording_path !== 'FAILED') {
            $filePath = storage_path("app/private/{$call->recording_path}");
            if (file_exists($filePath)) {
                $contentType = str_ends_with($call->recording_path, '.mp3') ? 'audio/mpeg' : 'audio/wav';
                $totalSize = filesize($filePath);
                $rangeHeader = $request->header('Range');

                if ($rangeHeader && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
                    $start = (int) $matches[1];
                    $end = $matches[2] !== '' ? (int) $matches[2] : $totalSize - 1;
                    $end = min($end, $totalSize - 1);
                    $length = $end - $start + 1;

                    $fp = fopen($filePath, 'rb');
                    fseek($fp, $start);
                    $data = fread($fp, $length);
                    fclose($fp);

                    return response($data, 206, [
                        'Content-Type' => $contentType,
                        'Content-Length' => $length,
                        'Content-Range' => "bytes {$start}-{$end}/{$totalSize}",
                        'Accept-Ranges' => 'bytes',
                        'Cache-Control' => 'public, max-age=86400',
                    ]);
                }

                return response()->file($filePath, [
                    'Content-Type' => $contentType,
                    'Accept-Ranges' => 'bytes',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }

        // Lokal yoksa NetGSM'den proxy
        if (! $call->recording_url) {
            return response()->json(['message' => 'Ses kaydı bulunamadı.'], 404);
        }

        $response = \Illuminate\Support\Facades\Http::timeout(30)->get($call->recording_url);

        if (! $response->successful()) {
            return response()->json(['message' => 'Ses kaydı indirilemedi.'], 502);
        }

        $body = $response->body();
        $totalSize = strlen($body);

        return response($body, 200, [
            'Content-Type' => 'audio/wav',
            'Content-Length' => $totalSize,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $query = Call::query();
        $this->applyDateFilter($query, $request);

        $ivrCondition = implode(' OR ', array_map(fn($p) => "called ILIKE '%{$p}%'", self::IVR_PATTERNS));

        $total = (clone $query)->count();

        // Normal (operatöre düşen gelen çağrılar)
        $normalQuery = (clone $query)->where('direction', 'inbound')->whereRaw("NOT ({$ivrCondition})");
        $normalAnswered = (clone $normalQuery)->where('status', 'answered')->count();
        $normalMissed = (clone $normalQuery)->where('status', '!=', 'answered')->count();

        // IVR (mesai dışı, öğle arası, IVR)
        $ivrTotal = (clone $query)->where('direction', 'inbound')->whereRaw("({$ivrCondition})")->count();

        // Outbound (giden çağrılar)
        $outboundTotal = (clone $query)->where('direction', 'outbound')->count();

        $avgDuration = (clone $normalQuery)->where('status', 'answered')->avg('duration') ?? 0;
        $totalDuration = (clone $normalQuery)->where('status', 'answered')->sum('duration');
        $transcribed = (clone $query)->whereNotNull('transcribed_at')->count();

        // Cevaplanma oranı: sadece normal çağrılar (answered / (answered + missed))
        $realTotal = $normalAnswered + $normalMissed;

        return response()->json([
            'total' => $total,
            'answered' => $normalAnswered,
            'missed' => $normalMissed,
            'ivr' => $ivrTotal,
            'outbound' => $outboundTotal,
            'avg_duration' => round($avgDuration),
            'total_duration' => $totalDuration,
            'answer_rate' => $realTotal > 0 ? round(($normalAnswered / $realTotal) * 100, 1) : 0,
            'transcribed' => $transcribed,
        ]);
    }

    public function extensions(Request $request): JsonResponse
    {
        $query = Call::query();
        $this->applyDateFilter($query, $request);

        $extensions = (clone $query)
            ->select('extension')
            ->selectRaw("count(*) as total_calls")
            ->selectRaw("count(*) filter (where status = 'answered') as answered")
            ->selectRaw("count(*) filter (where status = 'missed') as missed")
            ->selectRaw("coalesce(avg(duration) filter (where status = 'answered'), 0) as avg_duration")
            ->selectRaw("coalesce(sum(duration) filter (where status = 'answered'), 0) as total_duration")
            ->whereNotNull('extension')
            ->groupBy('extension')
            ->orderByDesc('total_calls')
            ->get();

        // Extension → kullanıcı adı eşleştirmesi (tenant'a göre scope — User'da
        // global tenant scope yok, aynı dahili farklı firmalarda olabilir)
        $tenantIds = (clone $query)->distinct()->pluck('tenant_id')->filter()->all();
        $userMap = User::whereNotNull('extension')
            ->when(! empty($tenantIds), fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->pluck('name', 'extension');

        $extensions->each(function ($ext) use ($userMap) {
            $ext->agent_name = $userMap[$ext->extension] ?? null;
        });

        return response()->json($extensions);
    }

    public function hourlyChart(Request $request): JsonResponse
    {
        $query = Call::query();
        $this->applyDateFilter($query, $request);

        $hourly = $query
            ->selectRaw("extract(hour from started_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Istanbul') as hour")
            ->selectRaw("count(*) as total")
            ->selectRaw("count(*) filter (where status = 'answered') as answered")
            ->selectRaw("count(*) filter (where status = 'missed') as missed")
            ->groupByRaw("extract(hour from started_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Istanbul')")
            ->orderByRaw("extract(hour from started_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Istanbul')")
            ->get();

        return response()->json($hourly);
    }

    public function dailyChart(Request $request): JsonResponse
    {
        $query = Call::query();
        $this->applyDateFilter($query, $request);

        $daily = $query
            ->selectRaw("date(started_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Istanbul') as date")
            ->selectRaw("count(*) as total")
            ->selectRaw("count(*) filter (where status = 'answered') as answered")
            ->selectRaw("count(*) filter (where status = 'missed') as missed")
            ->groupByRaw("date(started_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Istanbul')")
            ->orderByRaw("date(started_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Istanbul')")
            ->get();

        return response()->json($daily);
    }

    protected function applyDateFilter($query, Request $request): void
    {
        if ($request->filled('date_from')) {
            $query->whereRaw("started_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Istanbul' >= ?", [$request->date_from . ' 00:00:00']);
        }
        if ($request->filled('date_to')) {
            $query->whereRaw("started_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Istanbul' <= ?", [$request->date_to . ' 23:59:59']);
        }
    }

    public function sentimentStats(Request $request): JsonResponse
    {
        $query = Call::query()->whereNotNull('ai_sentiment');
        $this->applyDateFilter($query, $request);

        $sentiments = $query
            ->selectRaw("ai_sentiment, count(*) as cnt")
            ->groupBy('ai_sentiment')
            ->pluck('cnt', 'ai_sentiment');

        $categories = Call::query()
            ->whereNotNull('ai_category');
        $this->applyDateFilter($categories, $request);

        $categoryCounts = $categories
            ->selectRaw("ai_category, count(*) as cnt")
            ->groupBy('ai_category')
            ->pluck('cnt', 'ai_category');

        return response()->json([
            'sentiments' => $sentiments,
            'categories' => $categoryCounts,
        ]);
    }
}
