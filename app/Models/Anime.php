<?php

namespace App\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;

class Anime extends Model {
    use Sluggable;
    protected $table = 'anime';
    protected $fillable = [
        'name',
        'description',
        'rating',
        'featured_image',
        'files',
    ];

    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name',
                'maxLength' => '20',
            ]
        ];
    }
}
