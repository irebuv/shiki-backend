<?php

use App\Http\Controllers\Admin\AnimeAdminController;
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
        Route::get('/anime', [AnimeAdminController::class, 'index']);
        Route::post('/anime', [AnimeAdminController::class, 'store']);
        Route::put('/anime/{anime}', [AnimeAdminController::class, 'update']);
        Route::patch('/anime/{anime}', [AnimeAdminController::class, 'update']);
        Route::delete('/anime/{anime}', [AnimeAdminController::class, 'destroy']);
        Route::post('/anime/{anime}/image', [AnimeAdminController::class, 'uploadImage']);
    });
