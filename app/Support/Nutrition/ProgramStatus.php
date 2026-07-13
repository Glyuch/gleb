<?php

namespace App\Support\Nutrition;

use App\Models\NutritionProfile;
use Carbon\CarbonImmutable;

/**
 * Статус 10-недельной программы профиля относительно сегодняшнего дня.
 */
class ProgramStatus
{
    /**
     * Номер текущего дня программы профиля (день старта = 1). 0, если не запущена.
     */
    public static function day(NutritionProfile $profile): int
    {
        $startedOn = $profile->program_started_on;
        if ($startedOn === null) {
            return 0;
        }

        $start = CarbonImmutable::parse($startedOn->format('Y-m-d'), $profile->tz())->startOfDay();
        $today = $profile->now()->startOfDay();

        return (int) $start->diffInDays($today) + 1;
    }
}
