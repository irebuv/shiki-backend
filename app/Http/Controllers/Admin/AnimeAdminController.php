<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnimeResource;
use App\Models\Anime;
use App\Models\Filter;
use App\Models\Studio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class AnimeAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = Anime::query();

        $query->orderBy('id', 'desc');

        $query->with('filters:id');

        $filtersList = Filter::groupedList();

        $studios = Studio::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        $paginator = $query->paginate(24)->appends($request->query());
        $items = $paginator->items();
        $anime = AnimeResource::collection($items)->resolve();

        return response()->json([
            'anime' => $anime,
            'studios' => $studios,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'filtersList' => $filtersList,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(AnimeResource::validationRules());
        $filterIds = $validated['filter_ids'] ?? [];
        unset($validated['filter_ids']);

        $anime = Anime::create($validated);
        $this->syncFilters($anime, $filterIds);
        $anime->load('filters:id');
        $this->clearAnimeCaches();

        return response()->json([
            'message' => 'Anime created successfully',
            'anime' => (new AnimeResource($anime))->resolve(),
        ], 201);
    }

    public function update(Request $request, Anime $anime)
    {
        $validated = $request->validate(AnimeResource::validationRules());
        $filterIds = $validated['filter_ids'] ?? null;
        unset($validated['filter_ids']);

        $anime->update($validated);
        if (is_array($filterIds)) {
            $this->syncFilters($anime, $filterIds);
        }

        $anime->load('filters:id');
        $this->clearAnimeCaches();

        return response()->json([
            'message' => 'Anime updated successfully',
            'anime' => (new AnimeResource($anime))->resolve(),
        ]);
    }

    public function destroy(Anime $anime)
    {
        if (!empty($anime->featured_image)) {
            Storage::disk('public')->delete($anime->featured_image);
        }

        $anime->filters()->detach();
        $anime->delete();
        $this->clearAnimeCaches();

        return response()->json([
            'message' => 'Anime deleted successfully',
        ]);
    }

    public function uploadImage(Request $request, Anime $anime)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:8192'],
        ]);

        $file = $request->file('image');

        if (!empty($anime->featured_image)) {
            Storage::disk('public')->delete($anime->featured_image);
        }

        $dateFolder = date('Y') . '/' . date('m') . '/' . date('d');
        $baseFolder = "images/anime/{$dateFolder}/{$anime->id}";
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs($baseFolder, "featured.{$ext}", 'public');

        $anime->update([
            'featured_image' => $path,
        ]);

        return response()->json([
            'message' => 'Image updated successfully',
            'featured_image' => $path,
            'featured_image_url' => Storage::url($path),
        ], 201);
    }

    private function syncFilters(Anime $anime, array $filterIds): void
    {
        $ids = array_values(array_unique(array_filter($filterIds, fn($id) => $id !== null)));
        if (empty($ids)) {
            $anime->filters()->detach();
            return;
        }

        $groupMap = Filter::query()
            ->whereIn('id', $ids)
            ->pluck('filter_group_id', 'id');

        $syncData = [];
        foreach ($ids as $id) {
            if (!$groupMap->has($id)) {
                continue;
            }
            $syncData[$id] = ['filter_group_id' => $groupMap[$id]];
        }

        $anime->filters()->sync($syncData);
    }

    private function clearAnimeCaches(): void
    {
        Cache::forget('anime:years');
        Cache::forget('anime:filters-list');
        Cache::forget('anime:studios');
    }
}
