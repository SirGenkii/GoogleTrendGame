<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\GamePlayController;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::post('/games', [GamePlayController::class, 'create'])->name('games.create');
Route::post('/games/join', [GamePlayController::class, 'join'])->name('games.join');
Route::get('/games/{game}', [GamePlayController::class, 'show'])->name('games.show');
Route::post('/games/{game}/rounds', [GamePlayController::class, 'startRound'])->name('games.rounds.start');
Route::post('/rounds/{round}/answers', [GamePlayController::class, 'submitAnswer'])->name('rounds.answers.submit');
