<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Episode extends Model
{
    protected $table = 'episodes';

    protected $fillable = [
        'anime_id',
        'season_number',
        'episode_number',
        'title',
        'description',
        'duration',
        'air_date',
    ];

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(EpisodeMedia::class);
    }
}
