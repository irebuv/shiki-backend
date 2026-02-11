<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use App\Models\AnimeRelation;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AnimeRelationAdminController extends Controller
{
    public function relations(Anime $anime)
    {
        $relations = AnimeRelation::query()
            ->where('anime_id', $anime->id)
            ->with(['relatedAnime:id,name,slug,featured_image,season_year,season,type,status'])
            ->orderBy('relation_type')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn(AnimeRelation $relation) => $this->serializeRelation($relation))
            ->values();

        return response()->json([
            'relations' => $relations,
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

        $query = Anime::query()
            ->select(['id', 'name', 'slug', 'featured_image', 'season_year', 'season', 'type', 'status'])
            ->where('id', '!=', $anime->id)
            ->orderByDesc('id');

        if ($queryText !== '') {
            $query->where('name', 'like', '%' . $queryText . '%');
        }

        $items = $query
            ->limit($limit)
            ->get()
            ->map(function (Anime $item) {
                $image = (string) ($item->featured_image ?? '');

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
            'relation_type' => ['required', 'string', 'max:32', Rule::in($this->relationTypes())],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $relationType = (string) $validated['relation_type'];
        $sortOrder = array_key_exists('sort_order', $validated)
            ? (int) $validated['sort_order']
            : ((int) AnimeRelation::query()
                ->where('anime_id', $anime->id)
                ->where('relation_type', $relationType)
                ->max('sort_order')) + 1;

        try {
            $relation = AnimeRelation::query()->updateOrCreate(
                [
                    'anime_id' => $anime->id,
                    'related_anime_id' => (int) $validated['related_anime_id'],
                    'relation_type' => $relationType,
                ],
                [
                    'sort_order' => $sortOrder,
                ],
            );
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Could not save relation.',
                'error' => $e->getMessage(),
            ], 422);
        }

        $relation->load('relatedAnime:id,name,slug,featured_image,season_year,season,type,status');

        return response()->json([
            'message' => 'Relation saved successfully',
            'relation' => $this->serializeRelation($relation),
        ], 201);
    }

    public function updateRelation(Request $request, Anime $anime, AnimeRelation $relation)
    {
        abort_if($relation->anime_id !== $anime->id, 404);

        $validated = $request->validate([
            'related_anime_id' => ['nullable', 'integer', 'exists:anime,id', 'not_in:' . $anime->id],
            'relation_type' => ['nullable', 'string', 'max:32', Rule::in($this->relationTypes())],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $nextRelatedAnimeId = (int) ($validated['related_anime_id'] ?? $relation->related_anime_id);
        $nextRelationType = (string) ($validated['relation_type'] ?? $relation->relation_type);

        $duplicateExists = AnimeRelation::query()
            ->where('anime_id', $anime->id)
            ->where('related_anime_id', $nextRelatedAnimeId)
            ->where('relation_type', $nextRelationType)
            ->where('id', '!=', $relation->id)
            ->exists();

        if ($duplicateExists) {
            return response()->json([
                'message' => 'This relation already exists.',
            ], 409);
        }

        $relation->update([
            'related_anime_id' => $nextRelatedAnimeId,
            'relation_type' => $nextRelationType,
            'sort_order' => array_key_exists('sort_order', $validated)
                ? (int) $validated['sort_order']
                : $relation->sort_order,
        ]);

        $relation->load('relatedAnime:id,name,slug,featured_image,season_year,season,type,status');

        return response()->json([
            'message' => 'Relation updated successfully',
            'relation' => $this->serializeRelation($relation),
        ]);
    }

    public function destroyRelation(Anime $anime, AnimeRelation $relation)
    {
        abort_if($relation->anime_id !== $anime->id, 404);

        $relation->delete();

        return response()->json([
            'message' => 'Relation deleted successfully',
        ]);
    }

    private function relationTypes(): array
    {
        return [
            'sequel',
            'prequel',
            'movie',
            'ova',
            'ona',
            'side_story',
            'special',
            'alternative',
            'other',
        ];
    }

    private function serializeRelation(AnimeRelation $relation): array
    {
        $related = $relation->relatedAnime;
        $image = (string) ($related?->featured_image ?? '');

        return [
            'id' => $relation->id,
            'anime_id' => $relation->anime_id,
            'related_anime_id' => $relation->related_anime_id,
            'relation_type' => $relation->relation_type,
            'sort_order' => $relation->sort_order,
            'created_at' => optional($relation->created_at)?->toIso8601String(),
            'updated_at' => optional($relation->updated_at)?->toIso8601String(),
            'related_anime' => $related ? [
                'id' => $related->id,
                'name' => $related->name,
                'slug' => $related->slug,
                'type' => $related->type,
                'status' => $related->status,
                'season_year' => $related->season_year,
                'season' => $related->season,
                'featured_image' => $related->featured_image,
                'featured_image_url' => $image === '' ? null : Storage::url($image),
            ] : null,
        ];
    }
}

