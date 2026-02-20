<?php

use App\Http\Controllers\Admin\AnimeAdminController;
use App\Http\Controllers\Admin\AnimeEpisodeAdminController;
use App\Http\Controllers\Admin\AnimeRelationAdminController;
use App\Http\Controllers\Admin\AnimeSimilarAdminController;
use App\Http\Controllers\Admin\FilterAdminController;
use App\Http\Controllers\Admin\FilterGroupAdminController;
use App\Http\Controllers\Admin\StudioAdminController;
use App\Http\Controllers\AnimeCommentController;
use App\Http\Controllers\AnimeController;
use App\Http\Controllers\AnimeFilterPresetController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'message' => 'pong from Laravel1',
    ], 201);
});
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login'])->name('login');

// anime page and comment index
Route::get('/anime', [AnimeController::class, 'index']);
Route::get('/anime/{slug}', [AnimeController::class, 'show']);
Route::get('/anime/{slug}/comments', [AnimeCommentController::class, 'index']);


Route::middleware('auth:api')->group(function () {
    Route::get('/me',      [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::apiResource('/anime-filter-presets', AnimeFilterPresetController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    // routes related with comments
    Route::post('/anime/{anime}/comments', [AnimeCommentController::class, 'store']);
    Route::patch('/comments/{comment}', [AnimeCommentController::class, 'update']);
    Route::delete('/comments/{comment}', [AnimeCommentController::class, 'destroy']);
    Route::post('/comments/{comment}/vote', [AnimeCommentController::class, 'vote']);
});


Route::prefix('admin')
    ->middleware(['auth:api', 'admin'])
    ->group(function () {
        //anime routes
        Route::apiResource('/anime', AnimeAdminController::class)->except(['show']);
        Route::post('/anime/{anime}/image', [AnimeAdminController::class, 'uploadImage']);
        Route::get('/anime/{anime}/episodes', [AnimeEpisodeAdminController::class, 'episodes']);
        Route::post('/anime/{anime}/episodes', [AnimeEpisodeAdminController::class, 'storeEpisode']);
        Route::delete('/anime/{anime}/episodes/{episode}', [AnimeEpisodeAdminController::class, 'destroyEpisode']);
        Route::delete('/anime/{anime}/episodes/{episode}/media/{media}', [AnimeEpisodeAdminController::class, 'deleteEpisodeMedia']);
        Route::post('/anime/{anime}/episodes/{episode}/source', [AnimeEpisodeAdminController::class, 'uploadEpisodeSource']);
        Route::post('/anime/{anime}/episodes/{episode}/transcode', [AnimeEpisodeAdminController::class, 'transcodeEpisode']);
        Route::get('/anime/{anime}/episodes/{episode}/transcode/progress', [AnimeEpisodeAdminController::class, 'transcodeProgress']);
        Route::get('/anime/{anime}/relations', [AnimeRelationAdminController::class, 'relations']);
        Route::get('/anime/{anime}/relations/candidates', [AnimeRelationAdminController::class, 'relationCandidates']);
        Route::post('/anime/{anime}/relations', [AnimeRelationAdminController::class, 'storeRelation']);
        Route::post('/anime/{anime}/relations/reorder', [AnimeRelationAdminController::class, 'reorderRelations']);
        Route::delete('/anime/{anime}/relations/current', [AnimeRelationAdminController::class, 'detachCurrentFromGroup']);
        Route::delete('/anime/{anime}/relations/{relation}', [AnimeRelationAdminController::class, 'destroyRelation']);

        // anime similar routes | it separated from others
        Route::post('/anime/similars/rebuild', [AnimeSimilarAdminController::class, 'rebuildNow']);
        Route::get('/anime/similars/status', [AnimeSimilarAdminController::class, 'status']);
        Route::delete('/anime/similars/queue', [AnimeSimilarAdminController::class, 'clearQueue']);
        Route::get('/anime/similars/settings', [AnimeSimilarAdminController::class, 'settings']);
        Route::put('/anime/similars/settings', [AnimeSimilarAdminController::class, 'updateSettings']);

        //filters routes
        Route::apiResource('/filters', FilterAdminController::class)->except(['show']);

        //filter groups routes
        Route::apiResource('/filter-groups', FilterGroupAdminController::class)->except(['show']);

        //studios routes
        Route::apiResource('/studios', StudioAdminController::class)->except(['show']);
        Route::post('/studios/{studio}/image', [StudioAdminController::class, 'uploadImage']);
    });
