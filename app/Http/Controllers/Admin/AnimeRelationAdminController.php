<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Models\AnimeRelationGroup;
use App\Models\AnimeRelationGroupItem;
use App\Services\AnimeSimilarDispatchService;
use App\Services\AnimeSimilarSettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AnimeRelationAdminController extends Controller
{
    public function __construct(
        private readonly AnimeSimilarDispatchService $similarDispatchService,
        private readonly AnimeSimilarSettingsService $similarSettingsService,
    ) {}

    public function relations(Anime $anime)
    {
        $sourceGroupItem = $this->groupItemForAnime($anime->id);

        if ($sourceGroupItem === null) {
            return response()->json([
                'relations' => [],
                'group' => null,
            ]);
        }

        $items = AnimeRelationGroupItem::query()
            ->with([
                'anime:id,name,slug,featured_image,season_year,season,type,status',
                'group:id,group_key',
            ])
            ->where('group_id', $sourceGroupItem->group_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $relations = $items
            ->map(fn(AnimeRelationGroupItem $item) => $this->serializeGroupItem($item, $anime->id))
            ->values();

        /** @var AnimeRelationGroup|null $group */
        $group = $items->first()?->group;

        return response()->json([
            'relations' => $relations,
            'group' => $group ? [
                'id' => $group->id,
                'group_key' => $group->group_key,
                'count' => $items->count(),
            ] : null,
        ]);
    }

    public function relationCandidates(Request $request, Anime $anime)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $queryText = trim((string) ($validated['q'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 20);

        $sourceGroupItem = $this->groupItemForAnime($anime->id);
        $sourceGroupId = (int) ($sourceGroupItem?->group_id ?? 0);

        $query = Anime::query()
            ->select(['id', 'name', 'slug', 'featured_image', 'season_year', 'season', 'type', 'status'])
            ->where('id', '!=', $anime->id)
            ->orderByDesc('id');

        if ($queryText !== '') {
            $query->where('name', 'like', '%' . $queryText . '%');
        }

        if ($sourceGroupId > 0) {
            $excludeAnimeIds = AnimeRelationGroupItem::query()
                ->where('group_id', $sourceGroupId)
                ->pluck('anime_id')
                ->map(fn($id) => (int) $id)
                ->all();

            if (!empty($excludeAnimeIds)) {
                $query->whereNotIn('id', $excludeAnimeIds);
            }
        }

        $candidates = $query
            ->limit($limit)
            ->get();

        $candidateIds = $candidates
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        $candidateGroupItems = AnimeRelationGroupItem::query()
            ->whereIn('anime_id', $candidateIds)
            ->get()
            ->keyBy('anime_id');

        $groupIds = $candidateGroupItems
            ->pluck('group_id')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $groupAnchors = AnimeRelationGroupItem::query()
            ->with([
                'anime:id,name,slug',
                'group:id,group_key',
            ])
            ->whereIn('group_id', $groupIds)
            ->orderBy('group_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('group_id')
            ->map(fn($rows) => $rows->first());

        $items = $candidates
            ->map(function (Anime $item) use ($candidateGroupItems, $groupAnchors, $sourceGroupId) {
                $image = (string) ($item->featured_image ?? '');
                /** @var AnimeRelationGroupItem|null $candidateGroupItem */
                $candidateGroupItem = $candidateGroupItems->get($item->id);
                $candidateGroupId = (int) ($candidateGroupItem?->group_id ?? 0);
                /** @var AnimeRelationGroupItem|null $anchor */
                $anchor = $candidateGroupId > 0
                    ? $groupAnchors->get($candidateGroupId)
                    : null;

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'slug' => $item->slug,
                    'type' => $item->type,
                    'status' => $item->status,
                    'season_year' => $item->season_year,
                    'season' => $item->season,
                    'featured_image' => $item->featured_image,
                    'featured_image_url' => $image === '' ? null : Storage::url($image),
                    'relation_group' => $candidateGroupId > 0 ? [
                        'id' => $candidateGroupId,
                        'group_key' => (string) ($anchor?->group?->group_key ?? ''),
                        'anchor_anime' => $anchor?->anime ? [
                            'id' => $anchor->anime->id,
                            'name' => $anchor->anime->name,
                            'slug' => $anchor->anime->slug,
                        ] : null,
                    ] : null,
                    'is_in_other_group' => $candidateGroupId > 0 && $candidateGroupId !== $sourceGroupId,
                ];
            })
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function storeRelation(Request $request, Anime $anime)
    {
        $validated = $request->validate([
            'related_anime_id' => ['required', 'integer', 'exists:anime,id', 'not_in:' . $anime->id],
        ]);

        $relatedAnimeId = (int) $validated['related_anime_id'];

        $sourceGroupItem = $this->groupItemForAnime($anime->id);
        $candidateGroupItem = $this->groupItemForAnime($relatedAnimeId);

        $sourceGroupId = (int) ($sourceGroupItem?->group_id ?? 0);
        $candidateGroupId = (int) ($candidateGroupItem?->group_id ?? 0);

        if ($sourceGroupId > 0 && $candidateGroupId > 0 && $sourceGroupId === $candidateGroupId) {
            return response()->json([
                'message' => 'This anime is already in the same relations group.',
            ], 409);
        }

        if ($sourceGroupId > 0 && $candidateGroupId > 0 && $sourceGroupId !== $candidateGroupId) {
            return response()->json([
                'message' => 'Candidate anime already belongs to another relations group.',
                'source_group_id' => $sourceGroupId,
                'candidate_group_id' => $candidateGroupId,
            ], 409);
        }

        try {
            $resultItem = DB::transaction(function () use (
                $anime,
                $relatedAnimeId,
                $sourceGroupId,
                $candidateGroupId,
            ) {
                if ($sourceGroupId === 0 && $candidateGroupId === 0) {
                    $group = AnimeRelationGroup::query()->create([
                        'group_key' => (string) Str::uuid(),
                    ]);

                    AnimeRelationGroupItem::query()->create([
                        'group_id' => $group->id,
                        'anime_id' => $anime->id,
                        'sort_order' => 1,
                    ]);

                    return AnimeRelationGroupItem::query()->create([
                        'group_id' => $group->id,
                        'anime_id' => $relatedAnimeId,
                        'sort_order' => 2,
                    ]);
                }

                if ($sourceGroupId > 0 && $candidateGroupId === 0) {
                    return AnimeRelationGroupItem::query()->create([
                        'group_id' => $sourceGroupId,
                        'anime_id' => $relatedAnimeId,
                        'sort_order' => $this->nextSortOrder($sourceGroupId),
                    ]);
                }

                if ($sourceGroupId === 0 && $candidateGroupId > 0) {
                    AnimeRelationGroupItem::query()->create([
                        'group_id' => $candidateGroupId,
                        'anime_id' => $anime->id,
                        'sort_order' => $this->nextSortOrder($candidateGroupId),
                    ]);

                    return AnimeRelationGroupItem::query()
                        ->where('group_id', $candidateGroupId)
                        ->where('anime_id', $relatedAnimeId)
                        ->firstOrFail();
                }

                throw new \RuntimeException('Unexpected relation group state.');
            });
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Could not save relation group item.',
                'error' => $e->getMessage(),
            ], 422);
        }

        $resultItem->load('anime:id,name,slug,featured_image,season_year,season,type,status');
        $this->dispatchSimilarRebuildForGroup((int) $resultItem->group_id);

        return response()->json([
            'message' => 'Anime added to relations group successfully.',
            'relation' => $this->serializeGroupItem($resultItem, $anime->id),
        ], 201);
    }

    public function reorderRelations(Request $request, Anime $anime)
    {
        $validated = $request->validate([
            'related_ids' => ['required', 'array', 'min:2'],
            'related_ids.*' => ['integer', 'distinct', 'exists:anime,id'],
        ]);

        $sourceGroupItem = $this->groupItemForAnime($anime->id);
        if ($sourceGroupItem === null) {
            return response()->json([
                'message' => 'Anime is not in any relations group.',
            ], 422);
        }

        $groupId = (int) $sourceGroupItem->group_id;

        $groupAnimeIds = AnimeRelationGroupItem::query()
            ->where('group_id', $groupId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('anime_id')
            ->map(fn($id) => (int) $id)
            ->values();

        $orderedIds = collect($validated['related_ids'])
            ->map(fn($id) => (int) $id)
            ->values();

        if (
            $orderedIds->count() !== $groupAnimeIds->count()
            || $orderedIds->diff($groupAnimeIds)->isNotEmpty()
            || $groupAnimeIds->diff($orderedIds)->isNotEmpty()
        ) {
            return response()->json([
                'message' => 'The order list must contain all anime from the current relations group exactly once.',
            ], 422);
        }

        DB::transaction(function () use ($groupId, $orderedIds) {
            foreach ($orderedIds as $index => $animeId) {
                AnimeRelationGroupItem::query()
                    ->where('group_id', $groupId)
                    ->where('anime_id', $animeId)
                    ->update(['sort_order' => $index + 1]);
            }
        });

        return response()->json([
            'message' => 'Relations order updated successfully.',
        ]);
    }

    public function destroyRelation(Anime $anime, AnimeRelationGroupItem $relation)
    {
        $sourceGroupItem = $this->groupItemForAnime($anime->id);
        if ($sourceGroupItem === null) {
            return response()->json([
                'message' => 'Anime is not in any relations group.',
            ], 404);
        }

        if ((int) $relation->group_id !== (int) $sourceGroupItem->group_id) {
            return response()->json([
                'message' => 'Relation item not found in this anime group.',
            ], 404);
        }

        $groupId = (int) $sourceGroupItem->group_id;
        $affectedAnimeIds = AnimeRelationGroupItem::query()
            ->where('group_id', $groupId)
            ->pluck('anime_id')
            ->map(fn($id) => (int) $id)
            ->all();
        $affectedAnimeIds[] = (int) $relation->anime_id;
        $affectedAnimeIds[] = (int) $anime->id;

        DB::transaction(function () use ($relation, $groupId) {
            $relation->delete();
            $this->normalizeSortOrder($groupId);
            $this->cleanupGroupIfTooSmall($groupId);
        });
        $this->dispatchSimilarRebuildForAnimeIds($affectedAnimeIds);

        return response()->json([
            'message' => 'Relation deleted successfully.',
        ]);
    }

    public function detachCurrentFromGroup(Anime $anime)
    {
        $sourceGroupItem = $this->groupItemForAnime($anime->id);
        if ($sourceGroupItem === null) {
            return response()->json([
                'message' => 'Anime is not in any relations group.',
            ]);
        }

        $groupId = (int) $sourceGroupItem->group_id;
        $affectedAnimeIds = AnimeRelationGroupItem::query()
            ->where('group_id', $groupId)
            ->pluck('anime_id')
            ->map(fn($id) => (int) $id)
            ->all();
        $affectedAnimeIds[] = (int) $anime->id;

        DB::transaction(function () use ($sourceGroupItem, $groupId) {
            $sourceGroupItem->delete();
            $this->normalizeSortOrder($groupId);
            $this->cleanupGroupIfTooSmall($groupId);
        });
        $this->dispatchSimilarRebuildForAnimeIds($affectedAnimeIds);

        return response()->json([
            'message' => 'Anime removed from relations group.',
        ]);
    }

    private function groupItemForAnime(int $animeId): ?AnimeRelationGroupItem
    {
        return AnimeRelationGroupItem::query()
            ->where('anime_id', $animeId)
            ->first();
    }

    private function nextSortOrder(int $groupId): int
    {
        return ((int) AnimeRelationGroupItem::query()
            ->where('group_id', $groupId)
            ->max('sort_order')) + 1;
    }

    private function normalizeSortOrder(int $groupId): void
    {
        $itemIds = AnimeRelationGroupItem::query()
            ->where('group_id', $groupId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach (array_values($itemIds) as $index => $itemId) {
            AnimeRelationGroupItem::query()
                ->where('id', (int) $itemId)
                ->update(['sort_order' => $index + 1]);
        }
    }

    private function cleanupGroupIfTooSmall(int $groupId): void
    {
        $count = (int) AnimeRelationGroupItem::query()
            ->where('group_id', $groupId)
            ->count();

        if ($count >= 2) {
            return;
        }

        AnimeRelationGroupItem::query()
            ->where('group_id', $groupId)
            ->delete();

        AnimeRelationGroup::query()
            ->where('id', $groupId)
            ->delete();
    }

    private function serializeGroupItem(AnimeRelationGroupItem $item, int $sourceAnimeId): array
    {
        $anime = $item->anime;
        $image = (string) ($anime?->featured_image ?? '');

        return [
            'id' => $item->id,
            'related_anime_id' => (int) $item->anime_id,
            'sort_order' => (int) $item->sort_order,
            'group_id' => (int) $item->group_id,
            'is_current' => (int) $item->anime_id === $sourceAnimeId,
            'created_at' => optional($item->created_at)?->toIso8601String(),
            'updated_at' => optional($item->updated_at)?->toIso8601String(),
            'related_anime' => $anime ? [
                'id' => $anime->id,
                'name' => $anime->name,
                'slug' => $anime->slug,
                'type' => $anime->type,
                'status' => $anime->status,
                'season_year' => $anime->season_year,
                'season' => $anime->season,
                'featured_image' => $anime->featured_image,
                'featured_image_url' => $image === '' ? null : Storage::url($image),
            ] : null,
        ];
    }

    private function dispatchSimilarRebuildForGroup(int $groupId): void
    {
        $animeIds = AnimeRelationGroupItem::query()
            ->where('group_id', $groupId)
            ->pluck('anime_id')
            ->map(fn($id) => (int) $id)
            ->all();

        $this->dispatchSimilarRebuildForAnimeIds($animeIds);
    }

    private function dispatchSimilarRebuildForAnimeIds(array $animeIds): void
    {
        $limit = $this->similarSettingsService->getLimit();
        $seen = [];

        foreach ($animeIds as $animeId) {
            $id = (int) $animeId;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $this->similarDispatchService->dispatchOne($id, $limit);
        }
    }
}
