<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

class Filter extends Model
{
    protected $fillable = [
        'title',
        'visible',
        'filter_group_id',
    ];

    protected $casts = [
        'visible' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FilterGroup::class, 'filter_group_id');
    }

    public function animes(): BelongsToMany
    {
        return $this->belongsToMany(Anime::class, 'filter_anime', 'filter_id', 'anime_id')
            ->withPivot('filter_group_id');
    }

    /**
     * Build filters grouped by filter group title for admin UI.
     */
    public static function groupedList(bool $onlyVisible = false): Collection
    {
        $rowsQuery = self::query()
            ->join('filter_groups', 'filters.filter_group_id', '=', 'filter_groups.id')
            ->select(
                'filters.id as filter_id',
                'filters.title as filter_title',
                'filters.visible as filter_visible',
                'filters.filter_group_id as filter_group_id',
                'filter_groups.title as group_title'
            )
            ->orderBy('filter_groups.title')
            ->orderBy('filters.title');

        if ($onlyVisible) {
            $rowsQuery->where(function ($q) {
                $q->where('filters.visible', true)->orWhereNull('filters.visible');
            });
        }

        $rows = $rowsQuery->get();

        return $rows->groupBy('group_title')->map(function ($items) {
            return $items->map(fn($row) => [
                'id' => $row->filter_id,
                'title' => $row->filter_title,
                'visible' => $row->filter_visible === null ? true : (bool) $row->filter_visible,
                'filter_group_id' => $row->filter_group_id,
            ])->values();
        });
    }
}
