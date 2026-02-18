<?php

namespace App\Http\Controllers;

use App\Models\Anime;
use App\Models\AnimeRelationGroupItem;
use App\Models\AnimeSimilar;
use App\Models\Episode;
use App\Models\Filter;
use App\Models\Studio;
use App\Services\AnimeSimilarSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class AnimeController extends Controller
{
    public function index(Request $request)
    {
        // Normalize inputs so downstream logic can assume arrays.
        $filtersInput = Arr::wrap($request->input('filters', []));
        $typesInput = Arr::wrap($request->input('type', []));
        $studiosInput = Arr::wrap($request->input('studios', []));
        $seasonInput = Arr::wrap($request->input('season', []));
        $yearInput = Arr::wrap($request->input('year', []));
        $ageRatingInput = Arr::wrap($request->input('age_rating', []));
        $sort = trim((string) $request->input('sort', 'id'));
        [$sortKey, $sortDirection] = array_pad(explode(':', $sort, 2), 2, null);
        $sortKey = $sortKey ?: 'id';
        $sortDirection = $sortDirection ?: 'asc';

        // Limit sorting to known columns and safe directions.
        $allowedSorts = [
            'id' => 'id',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
            'rating' => 'rating',
            'season_year' => 'season_year',
            'release_date' => 'release_date',
        ];
        $sortDirection = in_array($sortDirection, ['asc', 'desc'], true)
            ? $sortDirection
            : 'asc';

        $query = Anime::query();

        if ($sortKey === 'random') {
            // Stable random order per day.
            $daySeed = now()->timezone('Europe/Kyiv')->format('Y-m-d');
            $seed = hash('sha256', $daySeed . config('app.key'));
            $query->orderByRaw('CRC32(CONCAT(?, anime.id))', [$seed]);
        } else {
            $sortBy = $allowedSorts[$sortKey] ?? 'id';
            $query->orderBy($sortBy, $sortDirection);
        }
        $requestedFilterIds = collect($filtersInput)
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($requestedFilterIds->isNotEmpty()) {
            $visibleFilterIds = Filter::query()
                ->whereIn('id', $requestedFilterIds)
                ->where(function ($q) {
                    $q->where('visible', true)->orWhereNull('visible');
                })
                ->pluck('id')
                ->all();

            foreach ($visibleFilterIds as $filterId) {
                $query->whereHas('filters', fn($q) => $q->where('filters.id', $filterId));
            }
        }

        $typeValues = collect($typesInput)
            ->map(fn($value) => trim((string) $value))
            ->filter()
            ->values();

        if ($typeValues->contains('tv')) {
            $typeValues = $typeValues
                ->reject(fn($value) => $value === 'tv')
                ->merge(['tv_series', 'tv_short', 'tv_medium', 'tv_long'])
                ->unique()
                ->values();
        }

        $allowedTypes = [
            'tv_series',
            'tv_short',
            'tv_medium',
            'tv_long',
            'movie',
            'ova',
            'ona',
        ];

        $typeValues = $typeValues->filter(fn($value) => in_array($value, $allowedTypes, true));

        if ($typeValues->isNotEmpty()) {
            $query->whereIn('type', $typeValues->all());
        }

        $studioIds = collect($studiosInput)
            ->map(fn($value) => (int) $value)
            ->filter()
            ->unique()
            ->values();

        if ($studioIds->isNotEmpty()) {
            $query->whereIn('studio_id', $studioIds->all());
        }

        $yearValues = collect($yearInput)
            ->map(fn($value) => (int) $value)
            ->filter(fn($value) => $value >= 1900 && $value <= 2100)
            ->unique()
            ->values();

        if ($yearValues->isNotEmpty()) {
            $query->whereIn('season_year', $yearValues->all());
        }

        // Keep seasons limited to known values.
        $allowedSeasons = ['winter', 'spring', 'summer', 'fall'];
        $seasonValues = collect($seasonInput)
            ->map(fn($value) => (string) $value)
            ->filter(fn($value) => in_array($value, $allowedSeasons, true))
            ->unique()
            ->values();

        if ($seasonValues->isNotEmpty()) {
            $query->whereIn('season', $seasonValues->all());
        }

        $ageNormalize = collect($ageRatingInput)
            ->map(fn($v) => trim((string) $v))
            ->filter()
            ->unique()
            ->values();

        if ($ageNormalize->isNotEmpty()) {
            $query->whereIn('age_rating', $ageNormalize->all());
        }

        // filters list
        // Cache reference data that changes rare.
        $filtersList = Cache::remember('anime:filters-list', now()->addHours(6), function () {
            return Filter::groupedList(true);
        });

        // getting studios, year and season
        $studios = Cache::remember('anime:studios', now()->addHours(6), function () {
            return Studio::select(['id', 'name'])->orderBy('name')->get();
        });
        $query->with('studio');

        $year = Cache::remember('anime:years', now()->addHours(6), function () {
            return Anime::query()
                ->whereNotNull('season_year')
                ->select('season_year')
                ->distinct()
                ->orderByDesc('season_year')
                ->pluck('season_year')
                ->map(fn($y) => (string) $y)
                ->values();
        });

        $season = ['winter', 'spring', 'summer', 'fall',];

        $perPage = 24;
        $paginator = $query->paginate($perPage)->appends($request->query());
        $items = $paginator->items();

        return response()->json([
            'anime' => $items,
            'studios' => $studios,
            'year' => $year,
            'season' => $season,

            'pagination' => [
                'current_page'  => $paginator->currentPage(),
                'last_page'     => $paginator->lastPage(),
                'per_page'      => $paginator->perPage(),
                'total'         => $paginator->total(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'has_more'      => $paginator->hasMorePages(),
            ],

            'sort' => $sort,
            'filtersList' => $filtersList,
        ]);
    }

    // one anime page
    public function show(string $slug, AnimeSimilarSettingsService $similarSettings)
    {
        $anime = Anime::query()
            ->where('slug', $slug)
            ->with(['studio'])
            ->firstOrFail();

        $episodes = Episode::query()
            ->where('anime_id', $anime->id)
            ->with([
                'media' => fn($q) => $q->orderByDesc('is_primary')->orderBy('id'),
            ])
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->get()
            ->map(function ($episode) {
                $media = $episode->media->map(function ($item) {
                    $path = (string) ($item->path ?? '');
                    $url = $path === ''
                        ? null
                        : (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                            ? $path
                            : Storage::url($path));

                    return [
                        'id' => $item->id,
                        'type' => $item->type,
                        'quality' => $item->quality,
                        'path' => $item->path,
                        'url' => $url,
                        'mime' => $item->mime,
                        'size' => $item->size,
                        'duration' => $item->duration,
                        'language' => $item->language,
                        'is_primary' => (bool) $item->is_primary,
                    ];
                })->values();

                return [
                    'id' => $episode->id,
                    'season_number' => $episode->season_number,
                    'episode_number' => $episode->episode_number,
                    'title' => $episode->title,
                    'description' => $episode->description,
                    'duration' => $episode->duration,
                    'air_date' => $episode->air_date,
                    'media' => $media,
                ];
            })
            ->values();

        $groupItem = AnimeRelationGroupItem::query()
            ->where('anime_id', $anime->id)
            ->first();

        //Similar anime
        $similarItems = AnimeSimilar::query()
            ->with('similarAnime:id,name,slug,featured_image,season_year,season,type,status')
            ->where('anime_id', $anime->id)
            ->orderBy('position')
            ->orderByDesc('score')
            ->limit($similarSettings->getLimit())
            ->get()
            ->map(function (AnimeSimilar $item) {
                $related = $item->similarAnime;
                if ($related === null) {
                    return null;
                }

                $image = (string) ($related->featured_image ?? '');

                return [
                    'id' => $item->id,
                    'name' => $related->name,
                    'slug' => $related->slug,
                    'type' => $related->type,
                    'status' => $related->status,
                    'season_year' => $related->season_year,
                    'season' => $related->season,
                    'featured_image' => $related->featured_image,
                ];
            })
            ->filter()
            ->values();


        //Related anime
        $relatedItems = collect();

        if ($groupItem !== null) {
            $currentAnimeId = (int) $anime->id;

            $relatedItems = AnimeRelationGroupItem::query()
                ->with('anime:id,name,slug,featured_image,season_year,season,type,status')
                ->where('group_id', $groupItem->group_id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(function (AnimeRelationGroupItem $item) use ($currentAnimeId) {
                    $related = $item->anime;
                    if ($related === null) {
                        return null;
                    }

                    $image = (string) ($related->featured_image ?? '');

                    return [
                        'id' => $item->id,
                        'sort_order' => (int) $item->sort_order,
                        'is_current' => (int) $item->anime_id === $currentAnimeId,
                        'related_anime' => [
                            'id' => $related->id,
                            'name' => $related->name,
                            'slug' => $related->slug,
                            'type' => $related->type,
                            'status' => $related->status,
                            'season_year' => $related->season_year,
                            'season' => $related->season,
                            'featured_image' => $related->featured_image,
                        ],
                    ];
                })
                ->filter()
                ->values();
        }

        return response()->json([
            'anime' => $anime,
            'episode_items' => $episodes,
            'related_items' => $relatedItems,
            'similar_items' => $similarItems,
        ]);
    }
}
