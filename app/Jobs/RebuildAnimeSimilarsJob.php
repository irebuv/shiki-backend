<?php

namespace App\Jobs;

use App\Services\AnimeSimilarService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RebuildAnimeSimilarsJob implements ShouldQueue{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $animeId,
        public int $limit = 12,
    )
    {}

    public function handle(AnimeSimilarService $service): void{
        $service->rebuildByAnimeId($this->animeId, $this->limit);
    }
}
