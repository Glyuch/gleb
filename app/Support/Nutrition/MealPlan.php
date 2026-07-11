<?php

namespace App\Support\Nutrition;

use Carbon\CarbonImmutable;

class MealPlan
{
    public const TYPES = ['breakfast', 'lunch', 'snack', 'dinner'];

    public const LABELS = [
        'breakfast' => 'Завтрак',
        'lunch' => 'Обед',
        'snack' => 'Полдник',
        'dinner' => 'Ужин',
    ];

    /** Состав приёма по схеме — используется в напоминаниях и промптах. */
    public const COMPOSITION = [
        'breakfast' => 'Сложные углеводы (с кулак) + фрукт/горсть ягод или овощи',
        'lunch' => 'Правило тарелки: сложные углеводы (3–5 ст. л.) + белок с ладонь + свежий салат полтарелки (мин. 3 вида овощей) + фрукт',
        'snack' => 'Белок с ладонь + припущенные овощи al dente или салат полтарелки + вкусняшка (углеводистая)',
        'dinner' => 'Только белок с ладонь (кроме красного мяса), при голоде + овощи',
    ];

    /**
     * @param  array<string, array{start: string, end: string}>  $defaultWindows
     * @param  array<string, array{status: string, eaten_at: ?CarbonImmutable}>  $facts
     * @return array<string, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function windows(CarbonImmutable $date, array $defaultWindows, array $facts, string $sleepTime): array
    {
        $at = fn (string $hhmm) => $date->setTimeFromTimeString($hhmm);
        $sleep = $at($sleepTime);

        $result = [];
        $anchor = null;       // CarbonImmutable|null — время, от которого считать следующий приём
        $chainBroken = false; // true после первого факта (eaten/skipped/missed)

        foreach (self::TYPES as $type) {
            $fact = $facts[$type];
            $default = [
                'start' => $at($defaultWindows[$type]['start']),
                'end' => $at($defaultWindows[$type]['end']),
            ];

            $window = ($chainBroken && $anchor !== null)
                ? ['start' => $anchor->addHours(3), 'end' => $anchor->addHours(4)]
                : $default;

            if ($type === 'dinner') {
                $latestEnd = $sleep->subHours(2);
                if ($window['start']->greaterThan($latestEnd)) {
                    $window = ['start' => $sleep->subHours(3), 'end' => $latestEnd];
                } elseif ($window['end']->greaterThan($latestEnd)) {
                    $window['end'] = $latestEnd;
                }
            }

            if ($fact['status'] === 'eaten' && $fact['eaten_at'] !== null) {
                $anchor = $fact['eaten_at'];
                $chainBroken = true;

                continue; // окно съеденного приёма не возвращаем
            }

            if (in_array($fact['status'], ['skipped', 'missed'], true)) {
                $anchor = $window['end'];
                $chainBroken = true;

                continue;
            }

            // pending: пересчитанное окно получает только первый pending после последнего
            // факта; дальше цепочка не распространяется — последующие приёмы на дефолте
            $result[$type] = $window;
            $chainBroken = false;
            $anchor = null;
        }

        return $result;
    }
}
