<?php

use App\Http\Controllers\Api\MetaAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/meta/callback', [MetaAuthController::class, 'callback'])
    ->middleware('auth')
    ->name('meta.callback');

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|sanctum|meta|up).*$');
