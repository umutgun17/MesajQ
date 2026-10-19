<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = KnowledgeBase::with('creator:id,name');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $this->escapeLike($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('content', 'ilike', "%{$search}%");
            });
        }

        $items = $query->orderByDesc('updated_at')->paginate(min((int) $request->input('per_page', 20), 100));

        return response()->json($items);
    }

    public function categories(): JsonResponse
    {
        $categories = KnowledgeBase::select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return response()->json($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'tags' => 'sometimes|nullable|array',
        ]);

        $item = KnowledgeBase::create([
            ...$validated,
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);

        $item->load('creator:id,name');

        return response()->json($item, 201);
    }

    public function show(KnowledgeBase $knowledgeBase): JsonResponse
    {
        $knowledgeBase->load('creator:id,name');

        return response()->json($knowledgeBase);
    }

    public function update(Request $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'sometimes|string|max:255',
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'tags' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $knowledgeBase->update($validated);

        return response()->json($knowledgeBase->fresh('creator:id,name'));
    }

    public function destroy(KnowledgeBase $knowledgeBase): JsonResponse
    {
        $knowledgeBase->delete(); // soft delete

        return response()->json(['message' => 'Bilgi bankası kaydı silindi.']);
    }
}
