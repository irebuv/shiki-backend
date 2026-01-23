<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AnimeResource extends JsonResource
{
    public static function validationRules(): array
    {
        return [
            'name' => 'required|string|min:3|max:120',
            'description' => 'nullable|string|max:500',
            'rating' => 'required',
            'type' => 'nullable|string|max:50',
            'episodes' => 'nullable|integer|min:1',
            'episode_time' => 'nullable|integer|min:1',
            'release_date' => 'nullable|date',
            'status' => 'nullable|string|max:50',
            'age_rating' => 'nullable|string|max:20',
            'studio' => 'nullable|string|max:255',
            'related' => 'nullable|string',
            'authors' => 'nullable|string',
            'main_characters' => 'nullable|string',
            'similar' => 'nullable|string',
            'reviews' => 'nullable|string',
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
            'created_at' => $this->created_at
                ? $this->created_at->format('d.m.Y H:i')
                : null,
            'updated_at' => $this->updated_at
                ? $this->updated_at->format('d.m.Y H:i')
                : null,
        ]);
    }
}
