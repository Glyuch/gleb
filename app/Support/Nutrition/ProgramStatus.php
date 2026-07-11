<?php

namespace App\Support\Nutrition;

use Carbon\CarbonImmutable;

/**
 * Статус 10-недельной программы относительно сегодняшнего дня.
 */
class ProgramStatus
{
    /**
     * Номер текущего дня программы (день старта = 1). 0, если программа не запущена.
     */
    public static function day(): int
    {
        $startedOn = Settings::get('program_started_on');
        if ($startedOn === null) {
            return 0;
        }

        $start = CarbonImmutable::parse((string) $startedOn, 'Europe/Moscow')->startOfDay();
        $today = CarbonImmutable::now('Europe/Moscow')->startOfDay();

        return (int) $start->diffInDays($today) + 1;
    }
}
