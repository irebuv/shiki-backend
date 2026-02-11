<?php

namespace App\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(AnimeRelation::class, 'anime_id');
    }

    public function incomingRelations(): HasMany
    {
        return $this->hasMany(AnimeRelation::class, 'related_anime_id');
    }

    public function relatedAnime(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'anime_relations',
            'anime_id',
            'related_anime_id'
        )->withPivot(['relation_type', 'sort_order'])->withTimestamps();
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
