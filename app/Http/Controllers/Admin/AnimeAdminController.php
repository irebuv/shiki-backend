<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnimeResource;
use App\Models\Anime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnimeAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = Anime::query();
 
        $query->orderBy('id', 'desc');

        $paginator = $query->paginate(24)->appends($request->query());
        $items = $paginator->items();
        $anime = AnimeResource::collection($items)->resolve();

        return response()->json([
            'anime' => $anime,

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

    public function store(Request $request)
    {
        $validated = $request->validate(AnimeResource::validationRules());

        $anime = Anime::create($validated);

        return response()->json([
            'message' => 'Anime created successfully',
            'anime' => (new AnimeResource($anime))->resolve(),
        ], 201);
    }

    public function update(Request $request, Anime $anime)
    {
        $validated = $request->validate(AnimeResource::validationRules());

        $anime->update($validated);

        return response()->json([
            'message' => 'Anime updated successfully',
            'anime' => (new AnimeResource($anime))->resolve(),
        ]);
    }

    public function destroy(Anime $anime)
    {
        $disk = 'public';

        if (!empty($anime->featured_image)) {
            Storage::disk($disk)->delete($anime->featured_image);
        }

        $anime->delete();

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
        $disk = 'public';

        // Delete old image if exists
        if (!empty($anime->featured_image)) {
            Storage::disk($disk)->delete($anime->featured_image);
        }

        $dateFolder = date('Y') . '/' . date('m') . '/' . date('d');
        $baseFolder = "images/anime/{$dateFolder}/{$anime->id}";
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs($baseFolder, "featured.{$ext}", $disk);

        $anime->update([
            'featured_image' => $path,
        ]);

        return response()->json([
            'message' => 'Image updated successfully',
            'featured_image' => $path,
            'featured_image_url' => Storage::url($path),
        ], 201);
    }
}
