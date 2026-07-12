<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMeal;
use Carbon\CarbonImmutable;

/**
 * Резолвинг приёма из отчёта (тип/время → строка nutrition_meals), запись факта
 * еды и детерминированный текст про сдвинутые окна. Общая точка для текстовых
 * отчётов, поздних фото (после дизамбигуации) и ручного ввода времени.
 */
class MealLogger
{
    private const FORBIDDEN = 'сахар, мучное/выпечка, жареное, фастфуд, газировка/пакетированные соки, алкоголь';

    /**
     * Записывает приёмы из классифицированного отчёта. Однозначные — помечает
     * съеденными (в порядке возрастания времени), неоднозначные — уточняет кнопками.
     *
     * @param  array<string, mixed>  $update
     * @param  array<int, array{meal: ?string, time: ?string, food: string}>  $reports
     */
    public static function logReports(array $update, CarbonImmutable $now, array $reports, string $reply): void
    {
        $tg = app(TelegramClient::class);
        $chatId = Tg::chatId($update);

        Planner::ensureDay($now);

        $resolved = [];
        $hasAmbiguous = false;

        foreach ($reports as $report) {
            $meal = self::resolve($now, $report['meal'] ?? null, $report['time'] ?? null);
            if ($meal === null) {
                $hasAmbiguous = true;

                continue;
            }

            $resolved[] = ['meal' => $meal, 'at' => self::atTime($now, $report['time'] ?? null)];
        }

        // Несколько приёмов — по возрастанию времени, чтобы цепочка окон считалась верно.
        usort($resolved, fn ($a, $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        foreach ($resolved as $item) {
            Planner::markEaten($item['meal'], $item['at'], null, null);
        }

        if ($resolved !== []) {
            Planner::recalculate($now->startOfDay());
        }

        // Хоть один неоднозначный отчёт — уточняем приём кнопками.
        if ($hasAmbiguous) {
            self::askMeal($tg, $now, $reply, $chatId);

            return;
        }

        $text = trim($reply);
        $tail = self::windowsTail($now);
        if ($tail !== '') {
            $text = ($text !== '' ? $text."\n\n" : '').$tail;
        }
        if ($text === '') {
            $text = 'Записал приём 👌🏻';
        }

        $tg->send($text, chatId: $chatId);
    }

    /**
     * Резолвит отчёт в конкретный приём за сегодня, либо null (неоднозначно).
     */
    public static function resolve(CarbonImmutable $now, ?string $mealType, ?string $time): ?NutritionMeal
    {
        $meals = self::todayMeals($now);

        if ($mealType !== null && in_array($mealType, MealPlan::TYPES, true)) {
            return $meals[$mealType] ?? null;
        }

        $notEaten = array_filter($meals, fn ($m) => in_array($m->status, ['pending', 'missed'], true));

        if ($time !== null) {
            $target = self::minutes($time);
            $defaults = Settings::get('default_windows');
            $best = null;
            $bestDiff = null;

            foreach (MealPlan::TYPES as $type) {
                if (! isset($notEaten[$type])) {
                    continue;
                }

                $diff = abs(self::minutes($defaults[$type]['start']) - $target);
                if ($bestDiff === null || $diff < $bestDiff) {
                    $bestDiff = $diff;
                    $best = $notEaten[$type];
                }
            }

            return $best;
        }

        return count($notEaten) === 1 ? array_values($notEaten)[0] : null;
    }

    /**
     * Промпт vision для оценки фото приёма (единый источник для HandlePhoto и колбэков).
     */
    public static function foodPrompt(string $type): string
    {
        $portion = (int) Settings::get('portion_adjustment');
        $portionStr = ($portion > 0 ? '+' : '').$portion.'%';

        return 'На фото приём: '.MealPlan::LABELS[$type].".\n"
            .'Ожидаемый состав: '.MealPlan::COMPOSITION[$type].".\n"
            .'Запрещёнка (кратко): '.self::FORBIDDEN.".\n"
            .'Поправка порций: '.$portionStr.".\n"
            .'Оцени приём в стиле Насти — тепло и по делу, 1–3 предложения; при необходимости кратко объясни «почему» через физиологию.';
    }

    /**
     * Детерминированный хвост: перечисляет pending-приёмы, чьё окно отличается от
     * дефолтного (т.е. сдвинуто пересчётом). Пустая строка, если ничего не сдвинулось.
     */
    public static function windowsTail(CarbonImmutable $now): string
    {
        $meals = self::todayMeals($now);
        $defaults = Settings::get('default_windows');
        $lines = [];

        foreach (MealPlan::TYPES as $type) {
            $meal = $meals[$type] ?? null;
            if ($meal === null || $meal->status !== 'pending') {
                continue;
            }
            if ($meal->window_start === null || $meal->window_end === null) {
                continue;
            }

            $start = $meal->window_start->format('H:i');
            $end = $meal->window_end->format('H:i');
            if ($start === $defaults[$type]['start'] && $end === $defaults[$type]['end']) {
                continue;
            }

            $lines[] = MealPlan::LABELS[$type].' теперь '.$start.'–'.$end.' 🙌🏼';
        }

        return implode("\n", $lines);
    }

    private static function askMeal(TelegramClient $tg, CarbonImmutable $now, string $reply, ?int $chatId): void
    {
        $meals = self::todayMeals($now);
        $buttons = [];

        foreach (MealPlan::TYPES as $type) {
            $meal = $meals[$type] ?? null;
            if ($meal !== null && in_array($meal->status, ['pending', 'missed'], true)) {
                $buttons[] = [['text' => MealPlan::LABELS[$type], 'callback_data' => "ate:{$type}"]];
            }
        }

        $text = trim($reply);
        $text = ($text !== '' ? $text."\n\n" : '').'Какой это приём?';

        $tg->send($text, $buttons ?: null, chatId: $chatId);
    }

    private static function atTime(CarbonImmutable $now, ?string $time): CarbonImmutable
    {
        if ($time !== null && preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return $now->setTime((int) $m[1], (int) $m[2]);
        }

        return $now;
    }

    /**
     * @return array<string, NutritionMeal>
     */
    private static function todayMeals(CarbonImmutable $now): array
    {
        return NutritionMeal::query()
            ->whereDate('date', $now->format('Y-m-d'))
            ->get()
            ->keyBy('type')
            ->all();
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

        return (int) $h * 60 + (int) $m;
    }
}
