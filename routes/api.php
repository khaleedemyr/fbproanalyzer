<?php

use App\Http\Controllers\Api\AnalyticsTrackerController;
use App\Http\Controllers\Api\AngleFinderController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\YouTubeAuthController;
use App\Http\Controllers\Api\BlueprintController;
use App\Http\Controllers\Api\BrandKitController;
use App\Http\Controllers\Api\BriefStudioController;
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

    Route::get('angle-finder', [AngleFinderController::class, 'index']);
    Route::post('angle-finder/analyze', [AngleFinderController::class, 'analyze']);
    Route::get('angle-finder/{angleFinding}', [AngleFinderController::class, 'show']);
    Route::post('angle-finder/{angleFinding}/save-idea', [AngleFinderController::class, 'saveIdea']);
    Route::delete('angle-finder/{angleFinding}', [AngleFinderController::class, 'destroy']);

    Route::get('analytics', [AnalyticsTrackerController::class, 'index']);
    Route::post('analytics/sync', [AnalyticsTrackerController::class, 'sync']);
    Route::post('analytics/ai-analyze', [AnalyticsTrackerController::class, 'aiAnalyze']);
    Route::get('analytics/ai-analyses', [AnalyticsTrackerController::class, 'aiHistory']);
    Route::get('analytics/ai-analyses/{analyticsAiAnalysis}', [AnalyticsTrackerController::class, 'aiShow']);
    Route::delete('analytics/ai-analyses/{analyticsAiAnalysis}', [AnalyticsTrackerController::class, 'aiDestroy']);
    Route::post('analytics/youtube', [AnalyticsTrackerController::class, 'trackYoutube']);
    Route::delete('analytics/youtube/{trackedYoutubeVideo}', [AnalyticsTrackerController::class, 'destroyYoutube']);

    Route::get('youtube/status', [YouTubeAuthController::class, 'status']);
    Route::get('youtube/redirect', [YouTubeAuthController::class, 'redirect']);
    Route::delete('youtube/disconnect', [YouTubeAuthController::class, 'disconnect']);

    Route::get('brand-kit', [BrandKitController::class, 'show']);
    Route::put('brand-kit', [BrandKitController::class, 'update']);
    Route::post('brand-kit/test-gemini', [BrandKitController::class, 'testGemini']);

    Route::get('brief-studio/sources', [BriefStudioController::class, 'sources']);
    Route::get('brief-studio', [BriefStudioController::class, 'index']);
    Route::post('brief-studio/compose', [BriefStudioController::class, 'compose']);
    Route::get('brief-studio/{contentBrief}', [BriefStudioController::class, 'show']);
    Route::post('brief-studio/{contentBrief}/blueprint', [BriefStudioController::class, 'blueprint']);
    Route::delete('brief-studio/{contentBrief}', [BriefStudioController::class, 'destroy']);

    Route::get('blueprints', [BlueprintController::class, 'index']);
    Route::post('blueprints/generate', [BlueprintController::class, 'generate']);
    Route::post('blueprints/master-prompt', [BlueprintController::class, 'previewMasterPrompt']);
    Route::get('blueprints/{blueprint}', [BlueprintController::class, 'show']);
    Route::get('blueprints/{blueprint}/progress', [BlueprintController::class, 'progress']);
    Route::get('blueprints/{blueprint}/master-prompt', [BlueprintController::class, 'masterPrompt']);
    Route::post('blueprints/{blueprint}/finish-generate', [BlueprintController::class, 'finishGenerate']);
    Route::patch('blueprints/{blueprint}', [BlueprintController::class, 'update']);
    Route::delete('blueprints/{blueprint}', [BlueprintController::class, 'destroy']);
    Route::get('blueprints/{blueprint}/export', [BlueprintController::class, 'export']);
});
