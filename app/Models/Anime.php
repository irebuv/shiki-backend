<?php

namespace App\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'season_year',
        'season',
        'status',
        'age_rating',
        'studio_id',
        'related',
        'authors',
        'main_characters',
        'similar',
        'reviews',
        'rate',
        'similar_rebuilt_at',
    ];
    protected $casts = [
        'similar_rebuilt_at' => 'datetime',
    ];
    public function filters()
    {
        return $this->belongsToMany(Filter::class, 'filter_anime', 'anime_id', 'filter_id')
            ->withPivot('filter_group_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(AnimeMedia::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function relationGroupItem(): HasOne
    {
        return $this->hasOne(AnimeRelationGroupItem::class, 'anime_id');
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
