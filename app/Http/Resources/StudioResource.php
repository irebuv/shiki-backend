<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudioResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }

    public static function validationRules(): array
    {
        return [
            'name' => 'required|string|min:3|max:120',
            'description' => 'nullable|string|max:5000',
            
        ];
    }
}
