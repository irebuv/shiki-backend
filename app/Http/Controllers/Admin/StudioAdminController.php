<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudioResource;
use App\Models\Studio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudioAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = Studio::query();

        $query->orderBy('id', 'desc');

        $paginator = $query->paginate(24)->appends($request->query());
        $items = $paginator->items();

        return response()->json([
            'studios' => $items,
        ]);
    }

    public function store(Request $request){
        $validated = $request->validate(StudioResource::validationRules());
        $studio = Studio::create($validated);

        return response()->json([
            'message' => 'Studio created successfully',
            'studio' => (new StudioResource($studio))->resolve(),
        ], 201);
    }

    public function update(Request $request, Studio $studio){
        $validated = $request->validate(StudioResource::validationRules());
        $studio->update($validated);

        return response()->json([
            'message' => 'Studio updated successfully',
            'studio' => (new StudioResource($studio))->resolve(),
        ]);
    }

    public function uploadImage(Request $request, Studio $studio) {
        $request->validate([
            'image' => ['required', 'image', 'max:8192'],
        ]);

        $file = $request->file('image');
        $disk = 'public';

        // Delete old image if exists
        if(!empty($studio->image)){
            Storage::disk($disk)->delete($studio->image);
        }

        $dateFolder = date('Y') . '/' . date('m') . '/' . date('d');
        $baseFolder = "images/studios/{$dateFolder}/{$studio->id}";
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs($baseFolder, "featured.{$ext}", $disk);

        $studio->update([
            'image' => $path,
        ]);

        return response()->json([
            'message' => 'Image updated successfully',
            'image' => $path,
            'image_url' => Storage::url($path),
        ], 201);
    }

    public function destroy(Studio $studio){
        $disk = 'public';

        if(!empty($studio->image)) {
            Storage::disk($disk)->delete($studio->image);
        }

        $studio->delete();

        return response()->json([
            'message' => 'Studio has been deleted',
        ]);
    }
}
