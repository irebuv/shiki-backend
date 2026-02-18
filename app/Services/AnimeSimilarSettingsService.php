<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class AnimeSimilarSettingsService
{
    private const SETTING_KEY = 'anime_similar_limit';
    private const CACHE_KEY = 'settings:anime_similar_limit';

    public function constraints(): array
    {
        return [
            'default' => (int) config('similar.limit.default', 12),
            'min' => (int) config('similar.limit.min', 4),
            'max' => (int) config('similar.limit.max', 24),
            'step' => max(1, (int) config('similar.limit.step', 4)),
        ];
    }

    public function getLimit(): int
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $cfg = $this->constraints();

            $row = AppSetting::query()->firstOrCreate(
                ['key' => self::SETTING_KEY],
                ['value' => (string) $cfg['default']]
            );

            $value = (int) $row->value;

            if (!$this->isValidLimit($value, $cfg)) {
                $value = $cfg['default'];
                $row->value = (string) $value;
                $row->save();
            }

            return $value;
        });
    }

    public function setLimit(int $limit): int
    {
        $normalized = $this->normalizeLimit($limit);

        AppSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => (string) $normalized]
        );

        Cache::forget(self::CACHE_KEY);

        return $normalized;
    }

    public function normalizeLimit(?int $limit): int
    {
        $cfg = $this->constraints();
        $value = $limit ?? $cfg['default'];

        if (!$this->isValidLimit($value, $cfg)) {
            throw new InvalidArgumentException(
                "Unsupported limit: {$value}. Allowed {$cfg['min']}..{$cfg['max']} and multiple of {$cfg['step']}."
            );
        }

        return $value;
    }

    private function isValidLimit(int $value, array $cfg): bool
    {
        if ($value < $cfg['min'] || $value > $cfg['max']) {
            return false;
        }

        return $value % $cfg['step'] === 0;
    }
}
