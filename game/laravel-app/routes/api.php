<?php

use App\Http\Controllers\Api\AnswerController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\RoundController;
use Illuminate\Support\Facades\Route;

Route::prefix('games')->group(function () {
    Route::post('/', [GameController::class, 'store']);
    Route::get('/{game}', [GameController::class, 'show']);
    Route::post('/{game}/players', [PlayerController::class, 'store']);
    Route::post('/{game}/rounds', [RoundController::class, 'store']);
});

Route::post('/rounds/{round}/answers', [AnswerController::class, 'store']);
Route::get('/rounds/{round}', [RoundController::class, 'show']);
