<?php

use App\Http\Controllers\Admin\AnimeAdminController;
use App\Http\Controllers\Admin\FilterAdminController;
use App\Http\Controllers\Admin\FilterGroupAdminController;
use App\Http\Controllers\Admin\StudioAdminController;
use App\Http\Controllers\AnimeController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'message' => 'pong from Laravel1',
    ], 201);
});
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login'])->name('login');
Route::get('/anime', [AnimeController::class, 'index']);

Route::middleware('auth:api')->group(function () {
    Route::get('/me',      [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});


Route::prefix('admin')
    ->middleware(['auth:api', 'admin'])
    ->group(function () {
        //anime routes
        Route::apiResource('/anime', AnimeAdminController::class)->except(['show']);
        Route::post('/anime/{anime}/image', [AnimeAdminController::class, 'uploadImage']);

        //filters routes
        Route::apiResource('/filters', FilterAdminController::class)->except(['show']);

        //filter groups routes
        Route::apiResource('/filter-groups', FilterGroupAdminController::class)->except(['show']);

        //studios routes
        Route::apiResource('/studios', StudioAdminController::class)->except(['show']);
        Route::post('/studios/{studio}/image', [StudioAdminController::class, 'uploadImage']);
    });
