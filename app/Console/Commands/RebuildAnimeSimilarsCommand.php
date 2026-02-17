<?php

namespace App\Console\Commands;

use App\Models\Anime;
use App\Services\AnimeSimilarDispatchService;
use App\Services\AnimeSimilarService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RebuildAnimeSimilarsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'anime:rebuild-similars {anime_id?} {--limit=12} {--scope=all} {--queue} {--chunk=200}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild similar anime list for one anime or all/scope';

    /**
     * Execute the console command.
     */
    public function handle(AnimeSimilarService $service, AnimeSimilarDispatchService $dispatchService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $chunk = max(50, (int) $this->option('chunk'));
        $animeId = $this->argument('anime_id');
        $queue = (bool) $this->option('queue');

        try {
            $scope = $dispatchService->normalizeScope((string) $this->option('scope'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($animeId) {
            $anime = Anime::query()->find((int) $animeId);
            if (!$anime) {
                $this->error("Anime {$animeId} not found");
                return self::FAILURE;
            }

            if($queue){
                $dispatchService->dispatchOne((int) $anime->id, $limit);
                $this->info("Queued rebuild for anime #{$anime->id}");
            } else {
                $service->rebuildForAnime($anime, $limit);
                $this->info("Similars rebuilt for anime #{$anime->id}");
            }

            return self::SUCCESS;
        }

        if ($queue){
            $queued = $dispatchService->dispatchByScope($scope, $limit, $chunk);
            $this->info("Queued rebuild for {$queued} anime (scope: {$scope})");
            return self::SUCCESS;
        }

        if($scope !== AnimeSimilarDispatchService::SCOPE_ALL){
            $this->warn('Scope is ignored without --queue. Running full sync rebuild.');
        }

        $service->rebuildAll($limit);
        $this->info('Similars rebuilt for all anime');
        return self::SUCCESS;
    }
}
