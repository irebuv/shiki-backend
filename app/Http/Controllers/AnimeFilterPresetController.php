<?php

namespace App\Http\Controllers;

use App\Models\AnimeFilterPreset;
use Illuminate\Http\Request;

class AnimeFilterPresetController extends Controller
{
    private const MAX_PRESETS = 3;

    public function index(Request $request)
    {
        $user = $request->user();

        $presets = AnimeFilterPreset::query()
            ->where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'presets' => $presets,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:50'],
            'filters' => ['required', 'array'],
            'filters.sort' => ['nullable', 'string', 'max:50'],
            'filters.type' => ['nullable', 'array'],
            'filters.type.*' => ['string'],
            'filters.filters' => ['nullable', 'array'],
            'filters.filters.*' => ['string'],
            'filters.studios' => ['nullable', 'array'],
            'filters.studios.*' => ['string'],
            'filters.age_rating' => ['nullable', 'array'],
            'filters.age_rating.*' => ['string'],
        ]);

        $count = AnimeFilterPreset::query()
            ->where('user_id', $user->id)
            ->count();

        if ($count >= self::MAX_PRESETS) {
            return response()->json([
                'message' => 'Preset limit reached. You can store up to 3 presets.',
            ], 422);
        }

        $preset = AnimeFilterPreset::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'filters' => $validated['filters'],
        ]);

        return response()->json([
            'message' => 'Preset created successfully',
            'preset' => $preset,
        ], 201);
    }

    public function update(Request $request, AnimeFilterPreset $anime_filter_preset)
    {
        $user = $request->user();

        $preset = $anime_filter_preset;

        if ((int) $preset->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:50'],
            'filters' => ['sometimes', 'required', 'array'],
            'filters.sort' => ['nullable', 'string', 'max:50'],
            'filters.type' => ['nullable', 'array'],
            'filters.type.*' => ['string'],
            'filters.filters' => ['nullable', 'array'],
            'filters.filters.*' => ['string'],
            'filters.studios' => ['nullable', 'array'],
            'filters.studios.*' => ['string'],
            'filters.age_rating' => ['nullable', 'array'],
            'filters.age_rating.*' => ['string'],
        ]);

        $preset->update($validated);

        return response()->json([
            'message' => 'Preset updated successfully',
            'preset' => $preset->fresh(),
        ]);
    }

    public function destroy(Request $request, AnimeFilterPreset $anime_filter_preset)
    {
        $user = $request->user();

        $preset = $anime_filter_preset;

        if ((int) $preset->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $preset->delete();

        return response()->json([
            'message' => 'Preset deleted successfully',
        ]);
    }
}
