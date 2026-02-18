<?php

namespace App\Services;

use App\Jobs\RebuildAnimeSimilarsJob;
use App\Models\Anime;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class AnimeSimilarDispatchService
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_WEEK = 'week';
    public const SCOPE_MONTH = 'month';
    public const SCOPE_TWO_MONTHS = 'two_months';
    public const SCOPE_THREE_MONTHS = 'three_months';

    public const SCOPES = [
        self::SCOPE_ALL,
        self::SCOPE_WEEK,
        self::SCOPE_MONTH,
        self::SCOPE_TWO_MONTHS,
        self::SCOPE_THREE_MONTHS,
    ];

    public function __construct(
        private readonly AnimeSimilarSettingsService $settingsService
    ) {}

    public function normalizeScope(?string $scope): string
    {
        $value = strtolower(trim((string) $scope));
        if ($value === '') {
            return self::SCOPE_THREE_MONTHS;
        }

        if (!in_array($value, self::SCOPES, true)) {
            throw new InvalidArgumentException("Unsupported scope: {$scope}");
        }

        return $value;
    }

    public function normalizeLimit(?int $limit): int
    {
        return $this->settingsService->normalizeLimit($limit ?? $this->settingsService->getLimit());
    }

    public function dispatchOne(int $animeId, ?int $limit = null): void
    {
        $limit = $this->normalizeLimit($limit);
        RebuildAnimeSimilarsJob::dispatch($animeId, $limit);
    }

    public function dispatchByScope(string $scope, ?int $limit = null, int $chunk = 200, ?\DateTimeInterface $startAt = null): int
    {
        $scope = $this->normalizeScope($scope);
        $cutoff = $this->cutoffForScope($scope);
        $limit = $this->normalizeLimit($limit);

        $queued = 0;
        $query = Anime::query()->select('id')->orderBy('id');

        if ($cutoff !== null) {
            $query->where(function ($q) use ($cutoff) {
                $q->whereNull('similar_rebuilt_at')->orWhere('similar_rebuilt_at', '<=', $cutoff);
            });
        }

        $baseDelay = $startAt ? max(0, now()->diffInSeconds($startAt, false)) : 0;
        $delaySeconds = $baseDelay;
        $step = max(1, (int) config('similar.rebuild.delay_step_seconds', 5));

        $query->chunkById($chunk, function ($rows) use ($limit, &$queued, &$delaySeconds, $step) {
            foreach ($rows as $row) {
                RebuildAnimeSimilarsJob::dispatch((int) $row->id, $limit)
                    ->delay(now()->addSeconds($delaySeconds));
                $queued++;
                $delaySeconds += $step;
            }
        });

        return $queued;
    }

    public function cutoffForScope(string $scope): ?CarbonImmutable
    {
        return match ($scope) {
            self::SCOPE_ALL => null,
            self::SCOPE_WEEK => now()->subWeek()->toImmutable(),
            self::SCOPE_MONTH => now()->subMonth()->toImmutable(),
            self::SCOPE_TWO_MONTHS => now()->subMonths(2)->toImmutable(),
            self::SCOPE_THREE_MONTHS => now()->subMonths(3)->toImmutable(),
            default => null,
        };
    }
}
