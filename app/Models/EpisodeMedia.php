<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpisodeMedia extends Model
{
    protected $table = 'episode_media';

    protected $fillable = [
        'episode_id',
        'type',
        'quality',
        'path',
        'mime',
        'size',
        'duration',
        'language',
        'is_primary',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }
}
