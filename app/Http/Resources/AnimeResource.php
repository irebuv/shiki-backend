<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AnimeResource extends JsonResource
{
    public static function validationRules(): array
    {
        return [
            'name' => 'required|string|min:3|max:120',
            'description' => 'nullable|string|max:5000',
            'rating' => 'required',
            'type' => [
                'nullable',
                'string',
                'max:50',
                Rule::in([
                    'tv_short',
                    'tv_medium',
                    'tv_long',
                    'movie',
                    'ova',
                    'ona',
                ]),
            ],
            'episodes' => 'nullable|integer|min:1',
            'episode_time' => 'nullable|integer|min:1',
            'release_date' => 'nullable|date',
            'status' => 'nullable|string|max:50',
            'age_rating' => 'nullable|string|max:20',
            'studio_id' => 'nullable|integer|exists:studios,id',
            'related' => 'nullable|string',
            'authors' => 'nullable|string',
            'main_characters' => 'nullable|string',
            'similar' => 'nullable|string',
            'reviews' => 'nullable|string',
            'filter_ids' => 'nullable|array',
            'filter_ids.*' => 'integer|exists:filters,id',
        ];
    }

    public function toArray($request)
    {
        $featuredUrl = $this->featured_image ? Storage::url($this->featured_image) : null;

        return array_merge(parent::toArray($request), [
            'featured_image_url' => $featuredUrl,
            'images' => [
                'original' => $featuredUrl,
            ],
            'filter_ids' => $this->whenLoaded('filters', function () {
                return $this->filters->pluck('id')->values();
            }),
            'created_at' => $this->created_at
                ? $this->created_at->format('d.m.Y H:i')
                : null,
            'updated_at' => $this->updated_at
                ? $this->updated_at->format('d.m.Y H:i')
                : null,
        ]);
    }
}
