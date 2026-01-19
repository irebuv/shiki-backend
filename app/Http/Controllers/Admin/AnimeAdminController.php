<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Anime;
use Illuminate\Http\Request;

class AnimeAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = Anime::query();

        $paginator = $query->paginate(24)->appends($request->query());
        $items = $paginator->items();

        return response()->json([
            'anime' => $items,

            'pagination' => [],
        ]);
    }
}
