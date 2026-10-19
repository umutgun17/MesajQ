<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuickReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hazır (hızlı) cevaplar — mesaj yazma alanından tek tıkla eklenir.
 *
 * Yetki: 'messages' sayfa izni olan HER temsilci ekleyip düzenleyip silebilir
 * (kullanıcı kararı 2026-07-25). Tenant izolasyonu BelongsToTenant global
 * scope'u ile otomatik.
 */
class QuickReplyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = QuickReply::query()->with('creator:id,name');

        // Composer'daki seçici yalnız aktifleri ister; yönetim ekranı hepsini.
        if ($request->boolean('only_active')) {
            $query->where('is_active', true);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('search')) {
            $search = $this->escapeLike($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                    ->orWhere('content', 'ilike', "%{$search}%");
            });
        }

        $items = $query
            ->orderBy('sort_order')
            ->orderByDesc('usage_count')
            ->orderBy('title')
            ->get();

        return response()->json([
            'data' => $items,
            'categories' => QuickReply::query()
                ->select('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:120',
            'content' => 'required|string|max:4000',
            'category' => 'sometimes|nullable|string|max:60',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);

        $item = QuickReply::create([
            'title' => $validated['title'],
            'content' => $validated['content'],
            'category' => $validated['category'] ?: 'Genel',
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_default' => false,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($item->load('creator:id,name'), 201);
    }

    public function update(Request $request, QuickReply $quickReply): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:120',
            'content' => 'sometimes|required|string|max:4000',
            'category' => 'sometimes|nullable|string|max:60',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:9999',
        ]);

        if (array_key_exists('category', $validated)) {
            $validated['category'] = $validated['category'] ?: 'Genel';
        }

        $quickReply->update($validated);

        return response()->json($quickReply->fresh()->load('creator:id,name'));
    }

    public function destroy(QuickReply $quickReply): JsonResponse
    {
        $quickReply->delete();

        return response()->json(['message' => 'Hazır cevap silindi.']);
    }

    /**
     * Kullanım sayacı — hangi cevabın işe yaradığını görmek ve sık kullanılanı
     * listede yukarı taşımak için. Mesajın kendisini göndermez.
     */
    public function markUsed(QuickReply $quickReply): JsonResponse
    {
        $quickReply->increment('usage_count');
        $quickReply->forceFill(['last_used_at' => now()])->save();

        return response()->json(['usage_count' => $quickReply->usage_count]);
    }
}
