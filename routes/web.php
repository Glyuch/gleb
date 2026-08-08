<?php

use App\Http\Controllers\Admin\GameContentController;
use App\Http\Controllers\Admin\GameResultsDashboardController;
use App\Http\Controllers\Admin\GameReturnsController;
use App\Http\Controllers\Admin\GameSurveyController;
use App\Http\Controllers\Admin\NutritionAdminController;
use App\Http\Controllers\Admin\SiteDashboardController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\NutritionBotController;
use App\Http\Controllers\NutritionStatsController;
use App\Http\Controllers\Shtab\AssignmentsController;
use App\Http\Controllers\Shtab\MetricsController;
use App\Http\Controllers\Shtab\ObjectsController;
use App\Http\Controllers\Shtab\PeopleController;
use App\Http\Controllers\Shtab\ShtabController;
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

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/dashboards/site');

    Route::prefix('dashboards')->name('dashboards.')->group(function () {
        Route::get('/gameresults', [GameResultsDashboardController::class, 'index'])->name('gameresults');
        Route::get('/site', [SiteDashboardController::class, 'index'])->name('site');
    });

    Route::prefix('game')->name('game.')->group(function () {
        Route::get('/', [GameContentController::class, 'edit'])->name('content');
        Route::put('/', [GameContentController::class, 'update'])->name('content.update');
        Route::get('/survey', [GameSurveyController::class, 'edit'])->name('survey');
        Route::put('/survey', [GameSurveyController::class, 'update'])->name('survey.update');
        Route::get('/returns', [GameReturnsController::class, 'edit'])->name('returns');
        Route::put('/returns', [GameReturnsController::class, 'update'])->name('returns.update');
        Route::redirect('/stats', '/admin/dashboards/gameresults');
    });

    Route::prefix('nutrition')->name('nutrition.')->group(function () {
        Route::get('/', [NutritionAdminController::class, 'index'])->name('index');
        Route::post('/invite', [NutritionAdminController::class, 'invite'])->name('invite');
        Route::get('/{profile}', [NutritionAdminController::class, 'show'])->name('show');
        Route::put('/{profile}', [NutritionAdminController::class, 'update'])->name('update');
        Route::post('/{profile}/pause', [NutritionAdminController::class, 'pause'])->name('pause');
    });
});

Route::middleware(['auth', 'admin'])->prefix('shtab')->name('shtab.')->group(function () {
    Route::get('/', [ShtabController::class, 'index'])->name('index');
    Route::post('/assignments', [AssignmentsController::class, 'store'])->name('assignments.store');
    Route::post('/assignments/{assignment}/end', [AssignmentsController::class, 'end'])->name('assignments.end');
    Route::post('/assignments/{assignment}/move', [AssignmentsController::class, 'move'])->name('assignments.move');
    Route::patch('/metrics/{metric}/status', [MetricsController::class, 'status'])->name('metrics.status');
    Route::patch('/objects/{object}/focus', [ObjectsController::class, 'focus'])->name('objects.focus');

    Route::post('/people', [PeopleController::class, 'store'])->name('people.store');
    Route::put('/people/{person}', [PeopleController::class, 'update'])->name('people.update');
    Route::post('/people/{person}/archive', [PeopleController::class, 'archive'])->name('people.archive');

    Route::post('/objects', [ObjectsController::class, 'store'])->name('objects.store');
    Route::put('/objects/{object}', [ObjectsController::class, 'update'])->name('objects.update');
    Route::post('/objects/{object}/archive', [ObjectsController::class, 'archive'])->name('objects.archive');

    Route::post('/metrics', [MetricsController::class, 'store'])->name('metrics.store');
    Route::put('/metrics/{metric}', [MetricsController::class, 'update'])->name('metrics.update');
    Route::delete('/metrics/{metric}', [MetricsController::class, 'destroy'])->name('metrics.destroy');
});

Route::post('/nutrition-bot/webhook', [NutritionBotController::class, 'webhook'])
    ->name('nutrition.webhook');

Route::get('/nutrition/s/{profile}', [NutritionStatsController::class, 'show'])
    ->name('nutrition.stats')
    ->middleware('signed');

require __DIR__.'/settings.php';
