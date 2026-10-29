<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlueprintController;
use App\Http\Controllers\Api\BrandKitController;
use App\Http\Controllers\Api\ContentIdeaController;
use App\Http\Controllers\Api\ContentPostController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MetaAccountController;
use App\Http\Controllers\Api\MetaAuthController;
use App\Http\Controllers\Api\RadarController;
use App\Http\Controllers\Api\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);

    Route::get('radar', [RadarController::class, 'index']);
    Route::post('radar/sync', [RadarController::class, 'sync']);

    Route::get('meta/redirect', [MetaAuthController::class, 'redirect']);
    Route::get('meta/accounts', [MetaAccountController::class, 'index']);
    Route::patch('meta/accounts/{metaAccount}', [MetaAccountController::class, 'update']);
    Route::post('meta/accounts/{metaAccount}/sync', [MetaAccountController::class, 'sync']);

    Route::get('posts', [ContentPostController::class, 'index']);

    Route::get('watchlist/accounts', [WatchlistController::class, 'accounts']);
    Route::post('watchlist/accounts', [WatchlistController::class, 'storeAccount']);
    Route::post('watchlist/seed-psychology', [WatchlistController::class, 'seedPsychology']);
    Route::delete('watchlist/accounts/{watchlistAccount}', [WatchlistController::class, 'destroyAccount']);
    Route::get('watchlist/posts', [WatchlistController::class, 'posts']);
    Route::post('watchlist/posts', [WatchlistController::class, 'storePost']);
    Route::delete('watchlist/posts/{watchlistPost}', [WatchlistController::class, 'destroyPost']);

    Route::get('ideas', [ContentIdeaController::class, 'index']);
    Route::post('ideas', [ContentIdeaController::class, 'store']);
    Route::post('ideas/generate', [ContentIdeaController::class, 'generate']);
    Route::patch('ideas/{contentIdea}', [ContentIdeaController::class, 'update']);
    Route::delete('ideas/{contentIdea}', [ContentIdeaController::class, 'destroy']);

    Route::get('brand-kit', [BrandKitController::class, 'show']);
    Route::put('brand-kit', [BrandKitController::class, 'update']);
    Route::post('brand-kit/test-gemini', [BrandKitController::class, 'testGemini']);

    Route::get('blueprints', [BlueprintController::class, 'index']);
    Route::post('blueprints/generate', [BlueprintController::class, 'generate']);
    Route::get('blueprints/{blueprint}', [BlueprintController::class, 'show']);
    Route::patch('blueprints/{blueprint}', [BlueprintController::class, 'update']);
    Route::delete('blueprints/{blueprint}', [BlueprintController::class, 'destroy']);
    Route::get('blueprints/{blueprint}/export', [BlueprintController::class, 'export']);
});
