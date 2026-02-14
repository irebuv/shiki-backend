<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnimeRelation extends Model
{
    protected $table = 'anime_relations';

    protected $fillable = [
        'anime_id',
        'related_anime_id',
        'relation_type',
        'sort_order',
        'pair_key',
    ];

    protected $casts = [
        'anime_id' => 'integer',
        'related_anime_id' => 'integer',
        'sort_order' => 'integer',
        'pair_key' => 'string',
    ];

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class, 'anime_id');
    }

    public function relatedAnime(): BelongsTo
    {
        return $this->belongsTo(Anime::class, 'related_anime_id');
    }
}
