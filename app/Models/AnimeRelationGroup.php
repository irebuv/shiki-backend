<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnimeRelationGroup extends Model
{
    protected $table = 'anime_relation_groups';

    protected $fillable = [
        'group_key',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(AnimeRelationGroupItem::class, 'group_id');
    }
}

