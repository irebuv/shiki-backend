<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\Ga4AnalyticsService;
use Illuminate\Http\Request;
use Throwable;

class AnalyticsAdminController extends Controller{
    public function __construct(
        private readonly Ga4AnalyticsService $analyticsService
    ){}

    public function overview(Request $request){
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        try {
            $days = (int) ($validated['days'] ?? 14);
            $data = $this->analyticsService->overview($days);

            return response()->json([
                'message' => 'Analytics loaded.',
                'data' => $data,
                'errors' => null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Analytics unavailable.',
                'data' => null,
                'errors' => null,
            ], 503);
        }
    }

    public function realtime(){
        try {
            $data = $this->analyticsService->realtime();

            return response()->json([
                'message' => 'Realtime analytics loaded.',
                'data' => $data,
                'errors' => null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Realtime analytics unavailable.',
                'data' => null,
                'errors' => null,
            ], 503);
        }
    }
}
