<?php

use App\Http\Controllers\Admin\GameContentController;
use App\Http\Controllers\Admin\GameReturnsController;
use App\Http\Controllers\Admin\GameStatsController;
use App\Http\Controllers\Admin\GameSurveyController;
use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

// Game landing handles its own guest gate (branded RU register/login) inside the controller.
Route::get('/game', [GameController::class, 'show'])->name('game');
Route::get('/game/register', [GameController::class, 'showRegister'])->name('game.register');
Route::get('/game/login', [GameController::class, 'showLogin'])->name('game.login');

Route::middleware(['auth'])->group(function () {
    Route::post('/game/result', [GameController::class, 'store'])->name('game.result');
    Route::get('/game/leaderboard', [GameController::class, 'leaderboard'])->name('game.leaderboard');
    Route::post('/game/event', [GameController::class, 'event'])->name('game.event');
});

Route::middleware(['auth', 'admin'])->prefix('admin/game')->name('admin.game.')->group(function () {
    Route::get('/', [GameContentController::class, 'edit'])->name('content');
    Route::put('/', [GameContentController::class, 'update'])->name('content.update');
    Route::get('/survey', [GameSurveyController::class, 'edit'])->name('survey');
    Route::put('/survey', [GameSurveyController::class, 'update'])->name('survey.update');
    Route::get('/returns', [GameReturnsController::class, 'edit'])->name('returns');
    Route::put('/returns', [GameReturnsController::class, 'update'])->name('returns.update');
    Route::get('/stats', [GameStatsController::class, 'index'])->name('stats');
});

require __DIR__.'/settings.php';
