<?php

namespace App\Console\Commands;

use App\Models\Anime;
use App\Services\AnimeSimilarService;
use Illuminate\Console\Command;

class RebuildAnimeSimilarsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'anime:rebuild-similars {anime_id?} {--limit=12}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild similar anime list for one anime or all';

    /**
     * Execute the console command.
     */
    public function handle(AnimeSimilarService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $animeId = $this->argument('anime_id');

        if ($animeId) {
            $anime = Anime::query()->find((int) $animeId);
            if (!$anime) {
                $this->error("Anime {$animeId} not found");
                return self::FAILURE;
            }

            $service->rebuildForAnime($anime, $limit);
            $this->info("Similars rebuilt for anime #{$anime->id}");
            return self::SUCCESS;
        }

        $service->rebuildAll($limit);
        $this->info('Similars rebuilt for all anime');
        return self::SUCCESS;
    }
}
