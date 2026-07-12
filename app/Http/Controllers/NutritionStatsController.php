<?php

namespace App\Http\Controllers;

use App\Models\NutritionProfile;
use App\Support\Nutrition\NutritionStats;
use Inertia\Inertia;
use Inertia\Response;

class NutritionStatsController extends Controller
{
    /**
     * Публичная (подписанная) страница статистики профиля. Только чтение.
     */
    public function show(NutritionProfile $profile): Response
    {
        return Inertia::render('nutrition/stats', NutritionStats::for($profile));
    }
}
