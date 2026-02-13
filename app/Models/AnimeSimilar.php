<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnimeSimilar extends Model
{
    protected $table = 'anime_similars';

    protected $fillable = [
        'anime_id',
        'similar_anime_id',
        'score',
        'position',
        'source',
    ];

    protected $casts = [
        'score' => 'float',
        'position' => 'int',
    ];

    public function anime(): BelongsTo{
        return $this->belongsTo(Anime::class, 'anime_id');
    }

    public function similarAnime(): BelongsTo {
        return $this->belongsTo(Anime::class, 'similar_anime_id');
    }
}
