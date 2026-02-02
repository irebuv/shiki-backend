<?php

namespace App\Http\Controllers;

use App\Models\Anime;
use App\Models\Filter;
use App\Models\Studio;
use Illuminate\Http\Request;

class AnimeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->input('filters', []);
        $types = $request->input('type', []);
        $studiosInput = $request->input('studios', []);
        $age_rating = $request->input('age_rating', []);
        $sort       = trim((string) $request->input('sort', 'id'));
        $split = explode(':', $sort);
        $query = Anime::query();

        if ($split[0] === 'random') {
            $daySeed = now()->timezone('Europe/Kyiv')->format('Y-m-d');
            $seed = hash('sha256', $daySeed . config('app.key'));
            $query->orderByRaw('CRC32(CONCAT(?, anime.id))', [$seed]);
        } else {
            $direction = $split[1];
            $sortBy = $split[0];
            $query->orderBy($sortBy, $direction);
        }
        $requestedFilterIds = collect($filters)
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

        $typeValues = collect(is_array($types) ? $types : [$types])
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

        $studioIds = collect(is_array($studiosInput) ? $studiosInput : [$studiosInput])
            ->map(fn($value) => (int) $value)
            ->filter()
            ->unique()
            ->values();

        if ($studioIds->isNotEmpty()) {
            $query->whereIn('studio_id', $studioIds->all());
        }

        $age_normalize = collect(is_array($age_rating) ? $age_rating : [$age_rating])
            ->map(fn($v) => trim((string) $v))
            ->filter()
            ->unique()
            ->values();

        if (!empty($age_normalize->isNotEmpty())){
           $query->whereIn('age_rating', $age_normalize->all());
        }

        // filters list
        $filtersList = Filter::groupedList(true);

        // getting studios
        $studios = Studio::select(['id', 'name'])->orderBy('name')->get();
        $query->with('studio');

        $perPage = 24;
        $paginator = $query->paginate($perPage)->appends($request->query());
        $items = $paginator->items();

        return response()->json([
            'anime' => $items,
            'studios' => $studios,

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
    public function show(string $slug){
        $anime = Anime::query()->where('slug', $slug)->with(['studio'])->firstOrFail();

        return response()->json([
            'anime' => $anime,
        ]);
    }
}
