<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FilterGroup extends Model
{
    protected $table = 'filter_groups';

    protected $fillable = [
        'title',
    ];

    public function filters(): HasMany
    {
        return $this->hasMany(Filter::class, 'filter_group_id');
    }
}

