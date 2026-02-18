<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AnimeSimilarDispatchService;
use App\Services\AnimeSimilarSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnimeSimilarAdminController extends Controller
{
    public function status()
    {
        $jobsQuery = DB::table('jobs')->where('payload', 'like', '%RebuildAnimeSimilarsJob%');
        $nowTs = now()->timestamp;

        $ready = (clone $jobsQuery)->where('available_at', '<=', $nowTs)->count();
        $scheduled = (clone $jobsQuery)->where('available_at', '>', $nowTs)->count();
        $nextAvailableTs = (clone $jobsQuery)->min('available_at');

        return response()->json([
            'message' => 'Similar rebuild status',
            'data' => [
                'status' => $ready > 0 ? 'running' : ($scheduled > 0 ? 'scheduled' : 'idle'),
                'ready' => (int) $ready,
                'scheduled' => (int) $scheduled,
                'pending' => (int) ($ready + $scheduled),
                'failed' => (int) DB::table('failed_jobs')
                    ->where('payload', 'like', '%RebuildAnimeSimilarsJob%')
                    ->count(),
                'next_available_at' => $nextAvailableTs
                    ? CarbonImmutable::createFromTimestamp((int) $nextAvailableTs, config('app.timezone'))->toIso8601String()
                    : null,
            ],
            'errors' => null,
        ]);
    }

    public function settings(AnimeSimilarSettingsService $settingsService)
    {
        $cfg = $settingsService->constraints();

        return response()->json([
            'message' => 'Similar setting',
            'data' => [
                'limit' => $settingsService->getLimit(),
                'min' => $cfg['min'],
                'max' => $cfg['max'],
                'step' => $cfg['step'],
            ],
            'errors' => null,
        ]);
    }

    public function updateSettings(Request $request, AnimeSimilarSettingsService $settingsService)
    {
        $cfg = $settingsService->constraints();

        $validated = $request->validate([
            'limit' => [
                'required',
                'integer',
                "min:{$cfg['min']}",
                "max:{$cfg['max']}",
                function (string $attribute, mixed $value, \Closure $fail) use ($cfg) {
                    if (((int) $value) % $cfg['step'] !== 0) {
                        $fail("The {$attribute} must be a multiple of {$cfg['step']}.");
                    }
                },
            ],
        ]);

        $limit = $settingsService->setLimit((int) $validated['limit']);

        return response()->json([
            'message' => 'Similar limit updated.',
            'data' => [
                'limit' => $limit,
                'min' => $cfg['min'],
                'max' => $cfg['max'],
                'step' => $cfg['step'],
            ],
            'errors' => null,
        ]);
    }

    public function rebuildNow(Request $request, AnimeSimilarDispatchService $dispatchService, AnimeSimilarSettingsService $settingsService)
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'string', 'in:all,week,month,two_months,three_months'],
            'chunk' => ['nullable', 'integer', 'min:50', 'max:1000'],
            'defer_to_night' => ['nullable', 'boolean'],
        ]);

        $scope = $dispatchService->normalizeScope($validated['scope'] ?? 'three_months');
        $chunk = (int) ($validated['chunk'] ?? (int) config('similar.rebuild.chunk_default', 200));
        $deferToNight = (bool) ($validated['defer_to_night'] ?? false);

        $startAt = null;
        if ($deferToNight) {
            $startAt = now()->setTime(3, 30, 0);
            if ($startAt->lessThanOrEqualTo(now())) {
                $startAt = $startAt->addDay();
            }
        }

        $limit = $settingsService->getLimit();
        $queued = $dispatchService->dispatchByScope($scope, $limit, $chunk, $startAt);

        return response()->json([
            'message' => $deferToNight
                ? "Queued rebuild for {$queued} anime at {$startAt?->format('Y-m-d H:i')}."
                : "Queued rebuild for {$queued} anime (starts now).",
            'data' => [
                'mode' => 'queue',
                'queued' => $queued,
                'scheduled_for' => $startAt?->toIso8601String(),
                'scope' => $scope,
                'limit' => $limit,
                'chunk' => $chunk,
            ],
            'errors' => null,
        ], 202);
    }

    public function clearQueue(Request $request)
    {
        $validated = $request->validate([
            'clear_failed' => ['nullable', 'boolean'],
        ]);

        $clearFailed = (bool) ($validated['clear_failed'] ?? false);

        $queuedDeleted = DB::table('jobs')
            ->where('payload', 'like', '%RebuildAnimeSimilarsJob%')
            ->delete();

        $failedDeleted = 0;
        if ($clearFailed) {
            $failedDeleted = DB::table('failed_jobs')
                ->where('payload', 'like', '%RebuildAnimeSimilarsJob%')
                ->delete();
        }

        return response()->json([
            'message' => 'Similar queue cleared.',
            'data' => [
                'queued_deleted' => (int) $queuedDeleted,
                'failed_deleted' => (int) $failedDeleted,
            ],
            'errors' => null,
        ]);
    }
}
