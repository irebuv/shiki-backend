<?php

namespace App\Services;

use App\Models\Anime;
use App\Models\AnimeRelationGroupItem;
use App\Models\AnimeSimilar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;


class AnimeSimilarService
{
    public function rebuildForAnime(Anime $anime, int $limit = 12): void
    {
        $filterIds = $anime->filters()->pluck('filters.id')->map(fn($id) => (int) $id)->all();

        $groupId = AnimeRelationGroupItem::query()
            ->where('anime_id', $anime->id)
            ->value('group_id');

        $excludedAnimeIds = collect([$anime->id]);

        if ($groupId !== null) {
            $excludedAnimeIds = AnimeRelationGroupItem::query()
                ->where('group_id', $groupId)
                ->pluck('anime_id');
        }

        $excludedAnimeIds = $excludedAnimeIds
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $candidates = Anime::query()
            ->whereNotIn('id', $excludedAnimeIds)
            ->withCount([
                'filters as shared_filters_count' => function ($q) use ($filterIds) {
                    if (empty($filterIds)) {
                        $q->whereRaw('1 = 0');
                        return;
                    }
                    $q->whereIn('filters.id', $filterIds);
                },
            ])->get(['id', 'type', 'studio_id', 'season_year']);

        $scored = $candidates->map(function (Anime $candidate) use ($anime) {
            $sharedFilters = (int) ($candidate->shared_filters_count ?? 0);
            $sameType = ($anime->type && $candidate->type === $anime->type) ? 1 : 0;
            $sameStudio = ($anime->studio_id && $candidate->studio_id === $anime->studio_id) ? 1 : 0;

            $yearScore = 0.0;
            if ($anime->season_year && $candidate->season_year) {
                $diff = abs((int) $anime->season_year - (int) $candidate->season_year);
                $yearScore = max(0, 3 - min(3, (int) floor($diff / 2)));
            }

            $typePenalty = $sameType ? 0 : 8;

            $score = ($sharedFilters * 3) + ($sameType * 7) + ($sameStudio * 5) + $yearScore - $typePenalty;

            return [
                'similar_anime_id' => (int) $candidate->id,
                'score' => round($score, 3),
            ];
        })->filter(fn($row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->values()
            ->take($limit);

        DB::transaction(function () use ($anime, $scored) {
            AnimeSimilar::query()
                ->where('anime_id', $anime->id)
                ->where('source', 'auto')
                ->delete();

            foreach ($scored as $index => $row) {
                AnimeSimilar::updateOrCreate(
                    [
                        'anime_id' => $anime->id,
                        'similar_anime_id' => $row['similar_anime_id'],
                    ],
                    [
                        'score' => $row['score'],
                        'position' => $index + 1,
                        'source' => 'auto',
                    ]
                );
            }
        });

        Anime::withoutTimestamps(function () use ($anime): void {
            $anime->forceFill([
                'similar_rebuilt_at' => now(),
            ])->saveQuietly();
        });
    }

    public function rebuildByAnimeId(int $animeId, int $limit = 12): bool
    {
        $anime = Anime::query()->find($animeId);
        if (!$anime) {
            return false;
        }

        $this->rebuildForAnime($anime, $limit);
        return true;
    }

    public function rebuildAll(int $limit = 12): void
    {
        Anime::query()->chunkById(100, function (Collection $animes) use ($limit) {
            foreach ($animes as $anime) {
                $this->rebuildForAnime($anime, $limit);
            }
        });
    }
}
