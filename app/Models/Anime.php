<?php

namespace App\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Anime extends Model
{
    use Sluggable;
    protected $table = 'anime';
    protected $fillable = [
        'name',
        'description',
        'rating',
        'featured_image',
        'files',
        'featured_image_original_name',
        'type',
        'episodes',
        'episode_time',
        'release_date',
        'status',
        'age_rating',
        'studio',
        'related',
        'authors',
        'main_characters',
        'similar',
        'reviews',
        'rate',
    ];
    public function filters()
    {
        return $this->belongsToMany(Filter::class, 'filter_anime', 'anime_id', 'filter_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(AnimeMedia::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name',
                'maxLength' => '20',
            ]
        ];
    }
}
