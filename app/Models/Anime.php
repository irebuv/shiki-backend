<?php

namespace App\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;

class Anime extends Model
{
    use Sluggable;
    protected $table = 'anime';
    protected $fillable = [
        'name',
        'description',
        'rating',
        'featured_image',
        'files',
        'rate',
    ];
    public function filters()
    {
        return $this->belongsToMany(Filter::class, 'filter_anime', 'product_id', 'filter_id');
    }
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
