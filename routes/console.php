<?php

use App\Models\Episode;
use App\Models\EpisodeMedia;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'anime:episode:transcode {episode_id} {source} {--qualities=1080} {--language=ru} {--ffmpeg=ffmpeg} {--ffprobe=ffprobe} {--overwrite} {--keep-source}',
    function () {
        $episodeId = (int) $this->argument('episode_id');
        $episode = Episode::query()->find($episodeId);
        $progressKey = "anime:episode:transcode:{$episodeId}:progress";

        $setProgress = function (array $data) use ($progressKey, $episodeId): void {
            $current = Cache::get($progressKey, []);
            $payload = array_merge([
                'episode_id' => $episodeId,
                'stage' => 'idle',
                'progress' => 0,
                'quality' => null,
                'quality_index' => 0,
                'qualities_total' => 0,
                'quality_progress' => 0,
                'message' => null,
                'error' => null,
            ], $current, $data, [
                'updated_at' => now()->toIso8601String(),
            ]);

            Cache::put($progressKey, $payload, now()->addHours(6));
        };

        $setProgress([
            'stage' => 'probing',
            'message' => 'Preparing source...',
            'progress' => 0,
            'quality_progress' => 0,
            'error' => null,
        ]);

        if (!$episode) {
            $this->error("Episode #{$episodeId} was not found.");
            $setProgress([
                'stage' => 'failed',
                'message' => 'Episode was not found.',
                'error' => "Episode #{$episodeId} was not found.",
            ]);
            return 1;
        }

        $animeLockKey = "anime:transcode:lock:{$episode->anime_id}";
        try {
        $sourceArg = trim((string) $this->argument('source'));
        $sourceCandidates = [
            $sourceArg,
            Storage::disk('local')->path(ltrim($sourceArg, '/\\')),
            base_path($sourceArg),
            storage_path($sourceArg),
            storage_path('app/' . ltrim($sourceArg, '/\\')),
            storage_path('app/private/' . ltrim($sourceArg, '/\\')),
            storage_path('app/public/' . ltrim($sourceArg, '/\\')),
        ];
        $sourcePath = collect($sourceCandidates)->first(fn($path) => is_file($path));

        if (!$sourcePath) {
            $this->error('Source file was not found.');
            $setProgress([
                'stage' => 'failed',
                'message' => 'Source file was not found.',
                'error' => 'Source file was not found.',
            ]);
            return 1;
        }

        $qualityPresets = [
            '1080' => ['label' => '1080p', 'width' => 1920, 'height' => 1080, 'crf' => 20],
            '720' => ['label' => '720p', 'width' => 1280, 'height' => 720, 'crf' => 22],
            '480' => ['label' => '480p', 'width' => 854, 'height' => 480, 'crf' => 24],
        ];

        $selectedQualities = collect(explode(',', (string) $this->option('qualities')))
            ->map(fn($item) => preg_replace('/[^0-9]/', '', trim((string) $item)))
            ->filter(fn($item) => $item !== null && $item !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($selectedQualities)) {
            $this->error('No valid qualities were provided.');
            $setProgress([
                'stage' => 'failed',
                'message' => 'No valid qualities were provided.',
                'error' => 'No valid qualities were provided.',
            ]);
            return 1;
        }

        foreach ($selectedQualities as $quality) {
            if (!array_key_exists($quality, $qualityPresets)) {
                $this->error("Unsupported quality: {$quality}");
                $setProgress([
                    'stage' => 'failed',
                    'message' => "Unsupported quality: {$quality}",
                    'error' => "Unsupported quality: {$quality}",
                ]);
                return 1;
            }
        }

        $language = trim((string) $this->option('language'));
        $ffmpegBinary = trim((string) $this->option('ffmpeg')) ?: 'ffmpeg';
        $ffprobeBinary = trim((string) $this->option('ffprobe')) ?: 'ffprobe';
        $overwrite = (bool) $this->option('overwrite');
        $keepSource = (bool) $this->option('keep-source');

        $probe = Process::timeout(30)->run([
            $ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=width,height',
            '-of',
            'csv=s=x:p=0',
            $sourcePath,
        ]);

        if ($probe->failed()) {
            $this->error('FFprobe failed to read source video stream info.');
            $errorOutput = trim($probe->errorOutput());
            if ($errorOutput !== '') {
                $this->line($errorOutput);
            }
            $setProgress([
                'stage' => 'failed',
                'message' => 'FFprobe failed to read source video stream info.',
                'error' => $errorOutput !== '' ? $errorOutput : 'FFprobe failed.',
            ]);
            return 1;
        }

        $resolution = trim($probe->output());
        [$sourceWidth, $sourceHeight] = array_pad(
            array_map('intval', explode('x', $resolution, 2)),
            2,
            0,
        );

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            $this->error('Could not detect source resolution.');
            $setProgress([
                'stage' => 'failed',
                'message' => 'Could not detect source resolution.',
                'error' => 'Could not detect source resolution.',
            ]);
            return 1;
        }

        $durationMicros = null;
        $durationProbe = Process::timeout(30)->run([
            $ffprobeBinary,
            '-v',
            'error',
            '-show_entries',
            'format=duration:stream=duration',
            '-of',
            'json',
            $sourcePath,
        ]);
        if ($durationProbe->successful()) {
            $decoded = json_decode($durationProbe->output(), true);
            $candidates = [];

            $formatDuration = (float) ($decoded['format']['duration'] ?? 0);
            if ($formatDuration > 0) {
                $candidates[] = $formatDuration;
            }

            foreach ((array) ($decoded['streams'] ?? []) as $stream) {
                $streamDuration = (float) ($stream['duration'] ?? 0);
                if ($streamDuration > 0) {
                    $candidates[] = $streamDuration;
                }
            }

            if (!empty($candidates)) {
                $durationMicros = (int) round(max($candidates) * 1_000_000);
            }
        }

        $parseClockToMicros = static function (string $clock): ?int {
            $clock = trim($clock);
            if (!preg_match('/^(\\d+):(\\d+):(\\d+(?:\\.\\d+)?)$/', $clock, $matches)) {
                return null;
            }

            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = (float) $matches[3];
            $totalSeconds = ($hours * 3600) + ($minutes * 60) + $seconds;
            if ($totalSeconds <= 0) {
                return null;
            }

            return (int) round($totalSeconds * 1_000_000);
        };

        $eligibleQualities = [];
        $skippedQualities = [];
        foreach ($selectedQualities as $quality) {
            $preset = $qualityPresets[$quality];
            if ($preset['height'] > $sourceHeight) {
                $skippedQualities[] = $quality;
                continue;
            }
            $eligibleQualities[] = $quality;
        }

        if (!empty($skippedQualities)) {
            $this->warn(
                'Skipped qualities above source height (' . $sourceHeight . 'p): ' .
                implode(', ', $skippedQualities)
            );
        }

        if (empty($eligibleQualities)) {
            $this->error(
                "No eligible quality remains for source {$sourceWidth}x{$sourceHeight}. " .
                'Use a lower quality value in --qualities.'
            );
            $setProgress([
                'stage' => 'failed',
                'message' => 'No eligible quality remains for this source.',
                'error' => "No eligible quality remains for source {$sourceWidth}x{$sourceHeight}.",
            ]);
            return 1;
        }

        $outputDir = "videos/anime/{$episode->anime_id}/s{$episode->season_number}/e{$episode->episode_number}";
        Storage::disk('public')->makeDirectory($outputDir);

        EpisodeMedia::query()
            ->where('episode_id', $episode->id)
            ->where('type', 'video')
            ->update(['is_primary' => false]);

        $isPrimary = true;
        $publicRoot = storage_path('app/public');
        $qualitiesTotal = count($eligibleQualities);

        foreach ($eligibleQualities as $index => $quality) {
            $preset = $qualityPresets[$quality];
            $prefix = $language !== '' ? "{$language}-" : '';
            $fileName = "{$prefix}{$preset['label']}.mp4";
            $relativePath = "{$outputDir}/{$fileName}";
            $absolutePath = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            $this->line("Transcoding {$preset['label']} -> {$relativePath}");
            $setProgress([
                'stage' => 'transcoding',
                'quality' => $preset['label'],
                'quality_index' => $index + 1,
                'qualities_total' => $qualitiesTotal,
                'quality_progress' => 0,
                'progress' => round(($index / max(1, $qualitiesTotal)) * 100, 2),
                'message' => "Transcoding {$preset['label']}...",
                'error' => null,
            ]);

            $command = [
                $ffmpegBinary,
                $overwrite ? '-y' : '-n',
                '-i',
                $sourcePath,
                // Force stable stream selection for browser/Windows playback.
                '-map',
                '0:v:0',
                '-map',
                '0:a:0?',
                '-vf',
                "scale='min({$preset['width']},iw)':'min({$preset['height']},ih)':force_original_aspect_ratio=decrease",
                '-c:v',
                'libx264',
                '-pix_fmt',
                'yuv420p',
                '-preset',
                'medium',
                '-crf',
                (string) $preset['crf'],
                '-c:a',
                'aac',
                '-b:a',
                '128k',
                '-ac',
                '2',
                '-ar',
                '48000',
                '-movflags',
                '+faststart',
                '-progress',
                'pipe:1',
                '-nostats',
                $absolutePath,
            ];

            $ffmpegProcess = new SymfonyProcess($command);
            $ffmpegProcess->setTimeout(null);
            $ffmpegProcess->start();

            $stdoutBuffer = '';
            $lastWritten = -1.0;
            $lastWriteTime = microtime(true);
            $errorTail = '';

            while ($ffmpegProcess->isRunning()) {
                $incrementalOutput = $ffmpegProcess->getIncrementalOutput();
                if ($incrementalOutput !== '') {
                    $stdoutBuffer .= str_replace("\r", "\n", $incrementalOutput);
                    $lines = explode("\n", $stdoutBuffer);
                    $stdoutBuffer = (string) array_pop($lines);

                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }

                        if (
                            str_starts_with($line, 'out_time_ms=') ||
                            str_starts_with($line, 'out_time_us=') ||
                            str_starts_with($line, 'out_time=')
                        ) {
                            $rawValue = trim((string) preg_replace('/^[^=]+=/', '', $line));
                            $outTimeMicros = 0;

                            if (str_starts_with($line, 'out_time=')) {
                                $outTimeMicros = $parseClockToMicros($rawValue);
                            } else {
                                $numeric = (int) $rawValue;
                                if ($numeric > 0) {
                                    if (
                                        str_starts_with($line, 'out_time_ms=') &&
                                        $durationMicros &&
                                        $durationMicros > 0 &&
                                        $numeric <= (($durationMicros / 1000) * 1.5)
                                    ) {
                                        // Some ffmpeg builds expose out_time_ms in milliseconds.
                                        $outTimeMicros = $numeric * 1000;
                                    } else {
                                        $outTimeMicros = $numeric;
                                    }
                                }
                            }

                            if ($outTimeMicros > 0 && (!$durationMicros || $durationMicros <= 0)) {
                                $durationMicros = max($durationMicros ?? 0, $outTimeMicros);
                            }

                            if ($outTimeMicros <= 0 || !$durationMicros || $durationMicros <= 0) {
                                continue;
                            }

                            $qualityProgress = min(100, max(0, ($outTimeMicros / $durationMicros) * 100));
                            $overallProgress = (($index + ($qualityProgress / 100)) / max(1, $qualitiesTotal)) * 100;
                            $overallProgress = min(99.5, max(0, $overallProgress));

                            $now = microtime(true);
                            $shouldWrite = $overallProgress > ($lastWritten + 0.2) || ($now - $lastWriteTime) > 1.0;
                            if ($shouldWrite) {
                                $setProgress([
                                    'stage' => 'transcoding',
                                    'quality' => $preset['label'],
                                    'quality_index' => $index + 1,
                                    'qualities_total' => $qualitiesTotal,
                                    'quality_progress' => round($qualityProgress, 2),
                                    'progress' => round($overallProgress, 2),
                                    'message' => "Transcoding {$preset['label']}...",
                                ]);
                                $lastWritten = $overallProgress;
                                $lastWriteTime = $now;
                            }
                        }
                    }
                }

                $incrementalError = $ffmpegProcess->getIncrementalErrorOutput();
                if ($incrementalError !== '') {
                    $errorTail = substr($errorTail . $incrementalError, -4000);
                }

                usleep(200000);
            }

            $remainingOutput = $ffmpegProcess->getIncrementalOutput();
            if ($remainingOutput !== '') {
                $stdoutBuffer .= str_replace("\r", "\n", $remainingOutput);
            }

            $ffmpegProcess->wait();
            if (!$ffmpegProcess->isSuccessful()) {
                $this->error("FFmpeg failed for quality {$preset['label']}.");
                $errorOutput = trim($errorTail !== '' ? $errorTail : $ffmpegProcess->getErrorOutput());
                if ($errorOutput !== '') {
                    $this->line($errorOutput);
                }
                $setProgress([
                    'stage' => 'failed',
                    'quality' => $preset['label'],
                    'quality_index' => $index + 1,
                    'qualities_total' => $qualitiesTotal,
                    'message' => "FFmpeg failed for {$preset['label']}.",
                    'error' => $errorOutput !== '' ? $errorOutput : "FFmpeg failed for {$preset['label']}.",
                ]);
                return 1;
            }

            $setProgress([
                'stage' => 'transcoding',
                'quality' => $preset['label'],
                'quality_index' => $index + 1,
                'qualities_total' => $qualitiesTotal,
                'quality_progress' => 100,
                'progress' => round((($index + 1) / max(1, $qualitiesTotal)) * 100, 2),
                'message' => "Completed {$preset['label']}.",
            ]);

            $size = is_file($absolutePath) ? filesize($absolutePath) : null;

            EpisodeMedia::query()->updateOrCreate(
                [
                    'episode_id' => $episode->id,
                    'type' => 'video',
                    'quality' => $preset['label'],
                    'language' => $language !== '' ? $language : null,
                ],
                [
                    'path' => $relativePath,
                    'mime' => 'video/mp4',
                    'size' => $size === false ? null : $size,
                    'is_primary' => $isPrimary,
                ],
            );

            $isPrimary = false;
        }

        $this->info('Transcoding finished and media records were updated.');
        $setProgress([
            'stage' => 'done',
            'quality_progress' => 100,
            'progress' => 100,
            'message' => 'Transcoding finished and media records were updated.',
            'error' => null,
        ]);

        if (!$keepSource) {
            $sourceDeleted = false;
            $sourceDeleteAttempted = false;

            $relativeLocalPath = ltrim(str_replace('\\', '/', $sourceArg), '/');
            if ($relativeLocalPath !== '') {
                $sourceDeleteAttempted = true;
                $sourceDeleted = Storage::disk('local')->delete($relativeLocalPath);
            }

            if (!$sourceDeleted) {
                $realSourcePath = realpath($sourcePath);
                $realLocalRoot = realpath(Storage::disk('local')->path(''));
                if (
                    $realSourcePath !== false &&
                    $realLocalRoot !== false &&
                    str_starts_with($realSourcePath, $realLocalRoot . DIRECTORY_SEPARATOR)
                ) {
                    $sourceDeleteAttempted = true;
                    $sourceDeleted = @unlink($realSourcePath);
                }
            }

            if ($sourceDeleteAttempted && !$sourceDeleted) {
                $this->warn('Transcoding finished, but source cleanup failed.');
            }
        }

        return 0;
        } finally {
            Cache::forget($animeLockKey);
        }
    }
)->purpose('Transcode one episode source into multiple MP4 qualities and store episode_media rows.');
