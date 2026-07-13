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
                'eaten_at' => self::toLocal($meal?->eaten_at, $profile->tz()),
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
     *
     * $score — балл 1–10 от ИИ (null для кнопки «Поел» и не-JSON ответов).
     * $ratingExtra — ИИ-составляющие рейтинга {composition_ok, forbidden, comment};
     * null → в rating попадают только детерминированные interval_ok/window_ok.
     *
     * @param  array{composition_ok?: ?bool, forbidden?: array<int, string>, comment?: ?string}|null  $ratingExtra
     */
    public static function markEaten(NutritionProfile $profile, NutritionMeal $meal, CarbonImmutable $at, ?string $photoFileId, ?string $feedback, ?int $score = null, ?array $ratingExtra = null): void
    {
        // Детерминированные флаги считаем ДО перевода приёма в eaten:
        // interval_ok опирается на предыдущий съеденный приём сегодня.
        $rating = ($ratingExtra ?? []) + [
            'interval_ok' => self::intervalOk($profile, $meal, $at),
            'window_ok' => self::windowOk($meal, $at, $profile->tz()),
        ];

        $meal->update([
            'status' => 'eaten',
            'eaten_at' => $at->format('Y-m-d H:i:s'),
            'photo_file_id' => $photoFileId,
            'ai_feedback' => $feedback,
            'score' => $score,
            'rating' => $rating,
        ]);

        self::recalculate($profile, self::dateOf($meal, $profile->tz()));
    }

    /**
     * Доклеивает фото/разбор к УЖЕ съеденному приёму (отмечен кнопкой), НЕ трогая
     * eaten_at, статус и окна — иначе поздний фидбэк сдвинул бы время приёма и
     * пересчитал бы окна следующих. Детерминированные флаги считаем от исходного
     * eaten_at.
     */
    public static function attachPhoto(NutritionProfile $profile, NutritionMeal $meal, ?string $photoFileId, ?string $feedback, ?int $score = null, ?array $ratingExtra = null): void
    {
        $at = self::toLocal($meal->eaten_at, $profile->tz()) ?? $profile->now();

        $rating = ($ratingExtra ?? []) + [
            'interval_ok' => self::intervalOk($profile, $meal, $at),
            'window_ok' => self::windowOk($meal, $at, $profile->tz()),
        ];

        $meal->update([
            'photo_file_id' => $photoFileId,
            'ai_feedback' => $feedback,
            'score' => $score,
            'rating' => $rating,
        ]);
    }

    /**
     * Переоценка УЖЕ съеденного приёма по уточнению клиента: перезаписывает
     * score/rating/ai_feedback, НЕ трогая eaten_at, status, окна и photo_file_id
     * (паттерн attachPhoto). Детерминированные interval_ok/window_ok пересчитываем
     * от исходного eaten_at (окна не сдвигаются), в rating ставим reevaluated=true —
     * пометка, что оценка правилась по словам клиента. recalculate не зовём: время
     * приёма не изменилось.
     *
     * @param  array{feedback: ?string, score: ?int, extra: array{composition_ok?: ?bool, forbidden?: array<int, string>, comment?: ?string}|null}  $eval
     */
    public static function updateEvaluation(NutritionProfile $profile, NutritionMeal $meal, array $eval): void
    {
        $at = self::toLocal($meal->eaten_at, $profile->tz()) ?? $profile->now();

        $rating = ($eval['extra'] ?? []) + [
            'interval_ok' => self::intervalOk($profile, $meal, $at),
            'window_ok' => self::windowOk($meal, $at, $profile->tz()),
            'reevaluated' => true,
        ];

        $meal->update([
            'ai_feedback' => $eval['feedback'] ?? null,
            'score' => $eval['score'] ?? null,
            'rating' => $rating,
        ]);
    }

    /**
     * Запись фото приёма: приём ещё не съеден — помечаем съеденным (markEaten);
     * уже съеден (кнопкой) — только доклеиваем фото/разбор (attachPhoto),
     * сохраняя исходные eaten_at/окна.
     */
    public static function recordFoodPhoto(NutritionProfile $profile, NutritionMeal $meal, CarbonImmutable $now, ?string $photoFileId, ?string $feedback, ?int $score = null, ?array $ratingExtra = null): void
    {
        if ($meal->status === 'eaten') {
            self::attachPhoto($profile, $meal, $photoFileId, $feedback, $score, $ratingExtra);

            return;
        }

        self::markEaten($profile, $meal, $now, $photoFileId, $feedback, $score, $ratingExtra);
    }

    /**
     * Интервал между приёмами: 2.5–4.5 ч от прошлого съеденного приёма сегодня.
     * Первый приём дня (нет предыдущего eaten) → true.
     */
    private static function intervalOk(NutritionProfile $profile, NutritionMeal $meal, CarbonImmutable $at): bool
    {
        $prev = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', self::dateOf($meal, $profile->tz())->format('Y-m-d'))
            ->where('status', 'eaten')
            ->whereNotNull('eaten_at')
            ->where('id', '!=', $meal->id)
            ->orderByDesc('eaten_at')
            ->first();

        if ($prev === null || $prev->eaten_at === null) {
            return true;
        }

        $prevAt = self::toLocal($prev->eaten_at, $profile->tz());
        $minutes = abs($prevAt->diffInMinutes($at));

        return $minutes >= 150 && $minutes <= 270;
    }

    /**
     * Попадание во временное окно приёма: eaten_at в [window_start, window_end+15м].
     * Нет окна → null (не оцениваем).
     */
    private static function windowOk(NutritionMeal $meal, CarbonImmutable $at, string $tz): ?bool
    {
        if ($meal->window_start === null || $meal->window_end === null) {
            return null;
        }

        $start = self::toLocal($meal->window_start, $tz);
        $end = self::toLocal($meal->window_end, $tz)->addMinutes(15);

        return $at->greaterThanOrEqualTo($start) && $at->lessThanOrEqualTo($end);
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
     * Наивный локальный datetime из БД → CarbonImmutable в поясе профиля $tz.
     */
    private static function toLocal(mixed $value, string $tz): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value->format('Y-m-d H:i:s'), $tz);
    }

    private static function dateOf(NutritionMeal $meal, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse($meal->date->format('Y-m-d'), $tz);
    }
}
