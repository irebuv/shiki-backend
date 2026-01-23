<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnimeMedia extends Model
{
    protected $table = 'anime_media';

    protected $fillable = [
        'anime_id',
        'type',
        'role',
        'path',
        'mime',
        'size',
        'width',
        'height',
        'duration',
    ];

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }
}
