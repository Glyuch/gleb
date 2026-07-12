<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionProfile;
use Carbon\CarbonImmutable;

class Planner
{
    /**
     * Создаёт 4 строки nutrition_meals профиля на дату с дефолтными окнами (идемпотентно).
     */
    public static function ensureDay(NutritionProfile $profile, CarbonImmutable $date): void
    {
        $defaultWindows = $profile->setting('default_windows');
        $day = $date->startOfDay();

        foreach (MealPlan::TYPES as $type) {
            NutritionMeal::query()->firstOrCreate(
                ['profile_id' => $profile->id, 'date' => $day, 'type' => $type],
                [
                    'window_start' => $date->setTimeFromTimeString($defaultWindows[$type]['start'])->format('Y-m-d H:i:s'),
                    'window_end' => $date->setTimeFromTimeString($defaultWindows[$type]['end'])->format('Y-m-d H:i:s'),
                    'status' => 'pending',
                ],
            );
        }
    }

    /**
     * Перечитывает факты из БД и обновляет окна pending-приёмов профиля на дату.
     */
    public static function recalculate(NutritionProfile $profile, CarbonImmutable $date): void
    {
        $meals = self::mealsForDate($profile, $date);

        $facts = [];
        foreach (MealPlan::TYPES as $type) {
            $meal = $meals[$type] ?? null;
            $facts[$type] = [
                'status' => $meal?->status ?? 'pending',
                'eaten_at' => self::toMoscow($meal?->eaten_at),
            ];
        }

        $windows = MealPlan::windows(
            $date,
            $profile->setting('default_windows'),
            $facts,
            $profile->setting('sleep_time'),
        );

        foreach ($windows as $type => $window) {
            $meal = $meals[$type] ?? null;
            if ($meal === null) {
                continue;
            }

            $meal->update([
                'window_start' => $window['start']->format('Y-m-d H:i:s'),
                'window_end' => $window['end']->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Отмечает приём съеденным и пересчитывает окна оставшихся.
     */
    public static function markEaten(NutritionProfile $profile, NutritionMeal $meal, CarbonImmutable $at, ?string $photoFileId, ?string $feedback): void
    {
        $meal->update([
            'status' => 'eaten',
            'eaten_at' => $at->format('Y-m-d H:i:s'),
            'photo_file_id' => $photoFileId,
            'ai_feedback' => $feedback,
        ]);

        self::recalculate($profile, self::dateOf($meal));
    }

    /**
     * Первый pending-приём профиля, чьё окно ещё не закрыто (window_end >= now), иначе null.
     */
    public static function currentMeal(NutritionProfile $profile, CarbonImmutable $now): ?NutritionMeal
    {
        return NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('status', 'pending')
            ->where('window_end', '>=', $now->format('Y-m-d H:i:s'))
            ->orderBy('window_start')
            ->first();
    }

    /**
     * Переводит просроченные pending-приёмы профиля (window_end < now - missed_after) в missed и пересчитывает.
     */
    public static function markMissed(NutritionProfile $profile, CarbonImmutable $now): void
    {
        $missedAfter = (int) config('nutrition.reminders.missed_after', 90);
        $threshold = $now->subMinutes($missedAfter)->format('Y-m-d H:i:s');

        $overdue = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('status', 'pending')
            ->where('window_end', '<', $threshold)
            ->get();

        if ($overdue->isEmpty()) {
            return;
        }

        foreach ($overdue as $meal) {
            $meal->update(['status' => 'missed']);
        }

        self::recalculate($profile, $now->startOfDay());
    }

    /**
     * @return array<string, NutritionMeal>
     */
    private static function mealsForDate(NutritionProfile $profile, CarbonImmutable $date): array
    {
        return NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $date->format('Y-m-d'))
            ->get()
            ->keyBy('type')
            ->all();
    }

    /**
     * Наивный московский datetime из БД → CarbonImmutable в Europe/Moscow.
     */
    private static function toMoscow(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value->format('Y-m-d H:i:s'), 'Europe/Moscow');
    }

    private static function dateOf(NutritionMeal $meal): CarbonImmutable
    {
        return CarbonImmutable::parse($meal->date->format('Y-m-d'), 'Europe/Moscow');
    }
}
