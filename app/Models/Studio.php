<?php

namespace App\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Studio extends Model
{
    use Sluggable;
    protected $fillable = [
        'name',
        'slug',
        'description',
        'image',
    ];

    public function anime(): HasMany
    {
        return $this->hasMany(Anime::class);
    }
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name',
                'maxLength' => '10'
            ]
        ];
    }
}
