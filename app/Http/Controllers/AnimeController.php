<?php

namespace App\Http\Controllers;

use App\Models\Anime;
use Illuminate\Http\Request;

class AnimeController extends Controller
{
    public function index(Request $request){

        $query = Anime::query();
        $perPage = 12;
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
        ]);
    }
}
