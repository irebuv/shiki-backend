<?php

namespace App\Http\Controllers;

use App\Models\Anime;
use Illuminate\Http\Request;

class AnimeController extends Controller
{
    public function index(Request $request)
    {

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

        $perPage = 12;
        logger($split);
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

            'filters' => [
                'sort' => $sort,
            ],
        ]);
    }
}
