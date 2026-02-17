<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AnimeSimilarDispatchService;
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

    public function rebuildNow(Request $request, AnimeSimilarDispatchService $dispatchService)
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'string', 'in:all,week,month,two_months,three_months'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'chunk' => ['nullable', 'integer', 'min:50', 'max:1000'],
            'defer_to_night' => ['nullable', 'boolean'],
        ]);

        $scope = $dispatchService->normalizeScope($validated['scope'] ?? 'three_months');
        $limit = (int) ($validated['limit'] ?? 12);
        $chunk = (int) ($validated['chunk'] ?? 200);
        $deferToNight = (bool) ($validated['defer_to_night'] ?? false);

        $startAt = null;
        if ($deferToNight) {
            $startAt = now()->setTime(3, 30, 0);
            if ($startAt->lessThanOrEqualTo(now())) {
                $startAt = $startAt->addDay();
            }
        }

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
