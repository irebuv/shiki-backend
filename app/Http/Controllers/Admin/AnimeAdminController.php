<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnimeResource;
use App\Models\Anime;
use App\Models\Episode;
use App\Models\EpisodeMedia;
use App\Models\Filter;
use App\Models\Studio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class AnimeAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = Anime::query();

        $query->orderBy('id', 'desc');

        $query->with("filters:id");


        // filters list
        $filtersList = Filter::groupedList();

        $studios = Studio::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        $paginator = $query->paginate(24)->appends($request->query());
        $items = $paginator->items();
        $anime = AnimeResource::collection($items)->resolve();

        return response()->json([
            'anime' => $anime,
            'studios' => $studios,

            'pagination' => [
                'current_page'  => $paginator->currentPage(),
                'last_page'     => $paginator->lastPage(),
                'per_page'      => $paginator->perPage(),
                'total'         => $paginator->total(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'has_more'      => $paginator->hasMorePages(),
            ],

            'filtersList' => $filtersList,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(AnimeResource::validationRules());
        $filterIds = $validated['filter_ids'] ?? [];
        unset($validated['filter_ids']);

        $anime = Anime::create($validated);
        $this->syncFilters($anime, $filterIds);
        $anime->load('filters:id');
        $this->clearAnimeCaches();

        return response()->json([
            'message' => 'Anime created successfully',
            'anime' => (new AnimeResource($anime))->resolve(),
        ], 201);
    }

    public function update(Request $request, Anime $anime)
    {
        $validated = $request->validate(AnimeResource::validationRules());
        $filterIds = $validated['filter_ids'] ?? null;
        unset($validated['filter_ids']);

        $anime->update($validated);
        if (is_array($filterIds)) {
            $this->syncFilters($anime, $filterIds);
        }
        $anime->load('filters:id');
        $this->clearAnimeCaches();

        return response()->json([
            'message' => 'Anime updated successfully',
            'anime' => (new AnimeResource($anime))->resolve(),
        ]);
    }

    public function destroy(Anime $anime)
    {
        $disk = 'public';

        if (!empty($anime->featured_image)) {
            Storage::disk($disk)->delete($anime->featured_image);
        }

        $anime->filters()->detach();
        $anime->delete();
        $this->clearAnimeCaches();

        return response()->json([
            'message' => 'Anime deleted successfully',
        ]);
    }

    public function uploadImage(Request $request, Anime $anime)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:8192'],
        ]);

        $file = $request->file('image');
        $disk = 'public';

        // Delete old image if exists
        if (!empty($anime->featured_image)) {
            Storage::disk($disk)->delete($anime->featured_image);
        }

        $dateFolder = date('Y') . '/' . date('m') . '/' . date('d');
        $baseFolder = "images/anime/{$dateFolder}/{$anime->id}";
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs($baseFolder, "featured.{$ext}", $disk);

        $anime->update([
            'featured_image' => $path,
        ]);

        return response()->json([
            'message' => 'Image updated successfully',
            'featured_image' => $path,
            'featured_image_url' => Storage::url($path),
        ], 201);
    }

    public function episodes(Anime $anime)
    {
        $episodes = Episode::query()
            ->where('anime_id', $anime->id)
            ->with(['media' => fn($q) => $q->orderByDesc('is_primary')->orderBy('id')])
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->get()
            ->map(function (Episode $episode) {
                return [
                    'id' => $episode->id,
                    'season_number' => $episode->season_number,
                    'episode_number' => $episode->episode_number,
                    'title' => $episode->title,
                    'description' => $episode->description,
                    'duration' => $episode->duration,
                    'air_date' => $episode->air_date,
                    'media' => $episode->media->map(function (EpisodeMedia $media) {
                        $path = (string) ($media->path ?? '');
                        $url = $path === ''
                            ? null
                            : (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                                ? $path
                                : Storage::url($path));

                        return [
                            'id' => $media->id,
                            'type' => $media->type,
                            'quality' => $media->quality,
                            'path' => $media->path,
                            'url' => $url,
                            'mime' => $media->mime,
                            'size' => $media->size,
                            'duration' => $media->duration,
                            'language' => $media->language,
                            'is_primary' => (bool) $media->is_primary,
                        ];
                    })->values(),
                ];
            })
            ->values();

        return response()->json([
            'episodes' => $episodes,
        ]);
    }

    public function storeEpisode(Request $request, Anime $anime)
    {
        $activeEpisodeId = $this->getActiveAnimeTranscodeEpisodeId($anime->id);
        if ($activeEpisodeId !== null) {
            return response()->json([
                'message' => "Transcoding is in progress for episode #{$activeEpisodeId}. Please wait until it finishes.",
            ], 409);
        }

        $validated = $request->validate([
            'season_number' => ['nullable', 'integer', 'min:1'],
            'episode_number' => ['required', 'integer', 'min:1'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration' => ['nullable', 'integer', 'min:1'],
            'air_date' => ['nullable', 'date'],
        ]);

        $seasonNumber = (int) ($validated['season_number'] ?? 1);
        $episodeNumber = (int) $validated['episode_number'];

        $episode = Episode::query()->updateOrCreate(
            [
                'anime_id' => $anime->id,
                'season_number' => $seasonNumber,
                'episode_number' => $episodeNumber,
            ],
            [
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'duration' => $validated['duration'] ?? null,
                'air_date' => $validated['air_date'] ?? null,
            ],
        );

        return response()->json([
            'message' => 'Episode saved successfully',
            'episode' => $episode,
        ]);
    }

    public function destroyEpisode(Anime $anime, Episode $episode)
    {
        abort_if($episode->anime_id !== $anime->id, 404);

        $episode->load('media');
        foreach ($episode->media as $media) {
            $path = (string) ($media->path ?? '');
            if ($path !== '' && !str_starts_with($path, 'http://') && !str_starts_with($path, 'https://')) {
                Storage::disk('public')->delete($path);
            }
        }

        $episode->delete();

        return response()->json([
            'message' => 'Episode deleted successfully',
        ]);
    }

    public function deleteEpisodeMedia(Anime $anime, Episode $episode, EpisodeMedia $media)
    {
        abort_if($episode->anime_id !== $anime->id, 404);
        abort_if($media->episode_id !== $episode->id, 404);

        $path = (string) ($media->path ?? '');
        if ($path !== '' && !str_starts_with($path, 'http://') && !str_starts_with($path, 'https://')) {
            Storage::disk('public')->delete($path);
        }

        $media->delete();

        return response()->json([
            'message' => 'Media deleted successfully',
        ]);
    }

    public function uploadEpisodeSource(Request $request, Anime $anime, Episode $episode)
    {
        abort_if($episode->anime_id !== $anime->id, 404);

        $activeEpisodeId = $this->getActiveAnimeTranscodeEpisodeId($anime->id);
        if ($activeEpisodeId !== null && $activeEpisodeId !== $episode->id) {
            return response()->json([
                'message' => "Transcoding is in progress for episode #{$activeEpisodeId}. Please wait until it finishes.",
            ], 409);
        }

        $request->validate([
            'source' => ['required', 'file', 'max:1048576'],
        ]);

        $file = $request->file('source');
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $allowed = ['mp4', 'mkv', 'avi', 'mov', 'webm'];
        if (!in_array($ext, $allowed, true)) {
            return response()->json([
                'message' => 'Unsupported source file extension',
            ], 422);
        }

        $dateFolder = date('Y') . '/' . date('m') . '/' . date('d');
        $baseFolder = "videos/source/{$dateFolder}/anime/{$anime->id}/s{$episode->season_number}/e{$episode->episode_number}";
        $fileName = 'source-' . time() . '.' . $ext;
        $path = $file->storeAs($baseFolder, $fileName, 'local');

        return response()->json([
            'message' => 'Source uploaded successfully',
            'source_path' => $path,
        ], 201);
    }

    public function transcodeEpisode(Request $request, Anime $anime, Episode $episode)
    {
        abort_if($episode->anime_id !== $anime->id, 404);

        $validated = $request->validate([
            'source_path' => ['required', 'string', 'max:2048'],
            'qualities' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:50'],
            'overwrite' => ['nullable', 'boolean'],
            'keep_source' => ['nullable', 'boolean'],
        ]);

        $progressKey = $this->transcodeProgressKey($episode->id);
        $current = Cache::get($progressKey, []);
        $currentStage = (string) ($current['stage'] ?? 'idle');
        if (in_array($currentStage, ['probing', 'transcoding'], true)) {
            return response()->json([
                'message' => 'Transcoding is already in progress for this episode.',
            ], 409);
        }

        $activeEpisodeId = $this->getActiveAnimeTranscodeEpisodeId($anime->id);
        if ($activeEpisodeId !== null && $activeEpisodeId !== $episode->id) {
            return response()->json([
                'message' => "Transcoding is in progress for episode #{$activeEpisodeId}. Please wait until it finishes.",
            ], 409);
        }

        Cache::put($this->animeTranscodeLockKey($anime->id), $episode->id, now()->addHours(6));
        Cache::put($progressKey, [
            'episode_id' => $episode->id,
            'stage' => 'probing',
            'progress' => 0,
            'quality' => null,
            'quality_index' => 0,
            'qualities_total' => 0,
            'quality_progress' => 0,
            'message' => 'Queued for transcoding...',
            'error' => null,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(6));

        $qualities = (string) ($validated['qualities'] ?? '1080');
        $language = (string) ($validated['language'] ?? 'ru');
        $overwrite = (bool) ($validated['overwrite'] ?? true);
        $keepSource = (bool) ($validated['keep_source'] ?? false);

        try {
            $parts = [
                escapeshellarg(PHP_BINARY ?: 'php'),
                'artisan',
                'anime:episode:transcode',
                escapeshellarg((string) $episode->id),
                escapeshellarg((string) $validated['source_path']),
                escapeshellarg('--qualities=' . $qualities),
                escapeshellarg('--language=' . $language),
            ];
            if ($overwrite) {
                $parts[] = '--overwrite';
            }
            if ($keepSource) {
                $parts[] = '--keep-source';
            }

            $logPath = storage_path("logs/transcode-episode-{$episode->id}.log");
            $shellCommand = implode(' ', $parts) . ' >> ' . escapeshellarg($logPath) . ' 2>&1 &';

            Process::path(base_path())->run(['sh', '-lc', $shellCommand]);
        } catch (\Throwable $e) {
            Cache::forget($this->animeTranscodeLockKey($anime->id));
            Cache::put($progressKey, [
                'episode_id' => $episode->id,
                'stage' => 'failed',
                'progress' => 0,
                'quality' => null,
                'quality_index' => 0,
                'qualities_total' => 0,
                'quality_progress' => 0,
                'message' => 'Failed to start transcoding process.',
                'error' => $e->getMessage(),
                'updated_at' => now()->toIso8601String(),
            ], now()->addHours(6));

            return response()->json([
                'message' => 'Failed to start transcoding process.',
            ], 422);
        }

        return response()->json([
            'message' => 'Transcoding started',
            'process_id' => null,
        ]);
    }

    public function transcodeProgress(Anime $anime, Episode $episode)
    {
        abort_if($episode->anime_id !== $anime->id, 404);

        $progress = Cache::get($this->transcodeProgressKey($episode->id), [
            'episode_id' => $episode->id,
            'stage' => 'idle',
            'progress' => 0,
            'quality' => null,
            'quality_index' => 0,
            'qualities_total' => 0,
            'quality_progress' => 0,
            'message' => null,
            'error' => null,
            'updated_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'progress' => $progress,
        ]);
    }

    private function syncFilters(Anime $anime, array $filterIds): void
    {
        $ids = array_values(array_unique(array_filter($filterIds, fn($id) => $id !== null)));
        if (empty($ids)) {
            $anime->filters()->detach();
            return;
        }

        $groupMap = Filter::query()
            ->whereIn('id', $ids)
            ->pluck('filter_group_id', 'id');

        $syncData = [];
        foreach ($ids as $id) {
            if (!$groupMap->has($id)) {
                continue;
            }
            $syncData[$id] = ['filter_group_id' => $groupMap[$id]];
        }

        $anime->filters()->sync($syncData);
    }

    private function clearAnimeCaches(): void
    {
        // Clear reference caches used on the public anime listing.
        Cache::forget('anime:years');
        Cache::forget('anime:filters-list');
        Cache::forget('anime:studios');
    }

    private function transcodeProgressKey(int $episodeId): string
    {
        return "anime:episode:transcode:{$episodeId}:progress";
    }

    private function animeTranscodeLockKey(int $animeId): string
    {
        return "anime:transcode:lock:{$animeId}";
    }

    private function getActiveAnimeTranscodeEpisodeId(int $animeId): ?int
    {
        $episodeId = (int) Cache::get($this->animeTranscodeLockKey($animeId), 0);
        if ($episodeId <= 0) {
            return null;
        }

        $progress = Cache::get($this->transcodeProgressKey($episodeId), []);
        $stage = (string) ($progress['stage'] ?? 'idle');
        if (in_array($stage, ['probing', 'transcoding'], true)) {
            return $episodeId;
        }

        Cache::forget($this->animeTranscodeLockKey($animeId));
        return null;
    }
}
