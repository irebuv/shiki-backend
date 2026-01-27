<?php

namespace App\Http\Controllers;

use App\Models\Anime;
use App\Models\Filter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnimeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->input('filters', []);
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
        if (!empty($filters)) {
            foreach ($filters as $filterId) {
                $query->whereHas('filters', fn($q) => $q->where('filters.id', $filterId));
            }
        }
        
        // filters list
        $filtersList = Filter::groupedList();

        $perPage = 24;
        $paginator = $query->paginate($perPage)->appends($request->query());
        $items = $paginator->items();

        return response()->json([
            'anime' => $items,

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
}
