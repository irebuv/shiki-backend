<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnimeRelationGroupItem extends Model
{
    protected $table = 'anime_relation_group_items';

    protected $fillable = [
        'group_id',
        'anime_id',
        'sort_order',
    ];

    protected $casts = [
        'group_id' => 'integer',
        'anime_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(AnimeRelationGroup::class, 'group_id');
    }

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class, 'anime_id');
    }
}

