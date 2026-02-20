<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AnimeComment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'anime_id',
        'user_id',
        'parent_id',
        'reply_to_user_id',
        'body',
        'has_spoiler',
        'is_edited',
        'edited_at',
        'likes_count',
        'dislikes_count',
        'replies_count',
        'status',
    ];

    protected $casts = [
        'has_spoiler' => 'boolean',
        'is_edited' => 'boolean',
        'edited_at' => 'datetime',
        'likes_count' => 'integer',
        'dislikes_count' => 'integer',
        'replies_count' => 'integer',
    ];

    public function anime(): BelongsTo{
        return $this->belongsTo(Anime::class, 'anime_id');
    }

    public function user(): BelongsTo{
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo{
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany{
        return $this->hasMany(self::class, 'parent_id');
    }

    public function replyToUser(): BelongsTo{
        return $this->belongsTo(User::class, 'reply_to_user_id');
    }

    public function votes(): HasMany{
        return $this->hasMany(AnimeCommentVote::class, 'comment_id');
    }
}
