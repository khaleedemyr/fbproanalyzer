<?php

use App\Http\Controllers\Api\MetaAuthController;
use App\Http\Controllers\Api\YouTubeAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/meta/callback', [MetaAuthController::class, 'callback'])
    ->middleware('auth')
    ->name('meta.callback');

Route::get('/youtube/callback', [YouTubeAuthController::class, 'callback'])
    ->middleware('auth')
    ->name('youtube.callback');

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|sanctum|meta|youtube|up).*$');
