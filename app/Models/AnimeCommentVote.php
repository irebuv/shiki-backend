<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnimeCommentVote extends Model
{
    protected $fillable = [
        'comment_id',
        'user_id',
        'vote',
    ];

    protected $casts = [
        'vote' => 'integer',
    ];

    public function comment(): BelongsTo{
        return $this->belongsTo(AnimeComment::class, 'comment_id');
    }

    public function user():BelongsTo{
        return $this->belongsTo(User::class);
    }
}
