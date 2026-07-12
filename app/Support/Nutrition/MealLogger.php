<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionProfile;
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
    public static function logReports(array $update, NutritionProfile $profile, CarbonImmutable $now, array $reports, string $reply): void
    {
        $tg = app(TelegramClient::class);
        $chatId = Tg::chatId($update);

        Planner::ensureDay($profile, $now);

        $resolved = [];
        $alreadyEaten = [];
        $hasAmbiguous = false;

        foreach ($reports as $report) {
            $meal = self::resolve($profile, $now, $report['meal'] ?? null, $report['time'] ?? null);
            if ($meal === null) {
                $hasAmbiguous = true;

                continue;
            }

            // Уже отмеченный приём не перезаписываем (иначе затрём eaten_at/фото/фидбек).
            if ($meal->status === 'eaten') {
                $alreadyEaten[$meal->type] = $meal->type;

                continue;
            }

            $resolved[] = [
                'meal' => $meal,
                'at' => self::atTime($now, $report['time'] ?? null),
                'score' => self::validScore($report['score'] ?? null),
                'extra' => [
                    'composition_ok' => $report['composition_ok'] ?? null,
                    'forbidden' => is_array($report['forbidden'] ?? null) ? array_values($report['forbidden']) : [],
                    'comment' => isset($report['comment']) ? (string) $report['comment'] : null,
                ],
            ];
        }

        // Несколько приёмов — по возрастанию времени, чтобы цепочка окон считалась верно.
        usort($resolved, fn ($a, $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        foreach ($resolved as $item) {
            Planner::markEaten($profile, $item['meal'], $item['at'], null, null, $item['score'], $item['extra']);
        }

        if ($resolved !== []) {
            Planner::recalculate($profile, $now->startOfDay());
        }

        $parts = [];
        if (trim($reply) !== '') {
            $parts[] = trim($reply);
        }
        foreach ($alreadyEaten as $type) {
            $parts[] = MealPlan::LABELS[$type].' уже отмечен 👌🏻';
        }
        $tail = self::windowsTail($profile, $now);
        if ($tail !== '') {
            $parts[] = $tail;
        }

        // Есть неоднозначные отчёты — уточняем приём кнопками; записанное/уже-отмеченное
        // подтверждаем в том же сообщении (не теряем записанные приёмы смешанного отчёта).
        if ($hasAmbiguous) {
            $parts[] = 'Какой это приём?';
            $tg->send(implode("\n\n", $parts), self::mealButtons($profile, $now), chatId: $chatId);

            return;
        }

        if ($parts === []) {
            $parts[] = 'Записал приём 👌🏻';
        }

        $tg->send(implode("\n\n", $parts), chatId: $chatId);
    }

    /**
     * Резолвит отчёт в конкретный приём за сегодня, либо null (неоднозначно).
     */
    public static function resolve(NutritionProfile $profile, CarbonImmutable $now, ?string $mealType, ?string $time): ?NutritionMeal
    {
        $meals = self::todayMeals($profile, $now);

        if ($mealType !== null && in_array($mealType, MealPlan::TYPES, true)) {
            return $meals[$mealType] ?? null;
        }

        $notEaten = array_filter($meals, fn ($m) => in_array($m->status, ['pending', 'missed'], true));

        if ($time !== null) {
            $target = self::minutes($time);
            $defaults = $profile->setting('default_windows');
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
     * Просит СТРОГИЙ JSON: фидбек в стиле Насти + балл и разбор состава/запрещёнки.
     */
    public static function foodPrompt(NutritionProfile $profile, string $type): string
    {
        $portion = (int) $profile->setting('portion_adjustment');
        $portionStr = ($portion > 0 ? '+' : '').$portion.'%';

        return 'На фото приём: '.MealPlan::LABELS[$type].".\n"
            .'Ожидаемый состав: '.MealPlan::COMPOSITION[$type].".\n"
            .'Запрещёнка (кратко): '.self::FORBIDDEN.".\n"
            .'Поправка порций: '.$portionStr.".\n"
            .'Оцени приём и верни ОТВЕТ СТРОГО в формате JSON без пояснений и без markdown-заборов:'."\n"
            .'{"feedback": "реакция в стиле Насти — тепло и по делу, 1–3 предложения, при необходимости кратко «почему» через физиологию", '
            .'"score": 8, "composition_ok": true, "forbidden": ["наименование запрещёнки, если есть"], "comment": "кратко для истории"}'."\n"
            .'score — целое 1–10 (насколько приём соответствует ожидаемому составу и без запрещёнки). '
            .'composition_ok — соответствует ли состав схеме. forbidden — список найденной запрещёнки (пустой, если нет).';
    }

    /**
     * Разбирает ответ vision на фидбек + структуру рейтинга. Валидный JSON с
     * целым score 1..10 → полная структура; иначе (не-JSON/битый score) —
     * fallback: feedback = сырой текст, score null, без ИИ-составляющих рейтинга.
     *
     * @return array{feedback: ?string, score: ?int, extra: array{composition_ok: ?bool, forbidden: array<int, string>, comment: ?string}|null}
     */
    public static function parseFood(?string $raw): array
    {
        if ($raw === null) {
            return ['feedback' => null, 'score' => null, 'extra' => null];
        }

        $data = json_decode(self::stripFences($raw), true);
        $score = is_array($data) ? self::validScore($data['score'] ?? null) : null;

        // Не-JSON или невалидный score → вся структура null, фидбек = сырой текст.
        if (! is_array($data) || $score === null) {
            return ['feedback' => trim($raw), 'score' => null, 'extra' => null];
        }

        return [
            'feedback' => isset($data['feedback']) ? (string) $data['feedback'] : trim($raw),
            'score' => $score,
            'extra' => self::ratingExtra($data),
        ];
    }

    /**
     * Целое 1..10 из значения ИИ, иначе null.
     */
    public static function validScore(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            if (! is_float($value) || floor($value) !== $value) {
                return null;
            }
        }

        $int = (int) $value;

        return ($int >= 1 && $int <= 10) ? $int : null;
    }

    /**
     * ИИ-составляющие рейтинга из декодированного объекта.
     *
     * @param  array<string, mixed>  $data
     * @return array{composition_ok: ?bool, forbidden: array<int, string>, comment: ?string}
     */
    public static function ratingExtra(array $data): array
    {
        $forbidden = [];
        if (isset($data['forbidden']) && is_array($data['forbidden'])) {
            foreach ($data['forbidden'] as $item) {
                $str = trim((string) $item);
                if ($str !== '') {
                    $forbidden[] = $str;
                }
            }
        }

        return [
            'composition_ok' => isset($data['composition_ok']) ? (bool) $data['composition_ok'] : null,
            'forbidden' => $forbidden,
            'comment' => isset($data['comment']) ? (string) $data['comment'] : null,
        ];
    }

    private static function stripFences(string $raw): string
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Детерминированный хвост: перечисляет pending-приёмы, чьё окно отличается от
     * дефолтного (т.е. сдвинуто пересчётом). Пустая строка, если ничего не сдвинулось.
     */
    public static function windowsTail(NutritionProfile $profile, CarbonImmutable $now): string
    {
        $meals = self::todayMeals($profile, $now);
        $defaults = $profile->setting('default_windows');
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

    /**
     * Кнопки по не-съеденным приёмам за сегодня (для уточнения приёма).
     *
     * @return array<int, array<int, array<string, string>>>|null
     */
    private static function mealButtons(NutritionProfile $profile, CarbonImmutable $now): ?array
    {
        $meals = self::todayMeals($profile, $now);
        $buttons = [];

        foreach (MealPlan::TYPES as $type) {
            $meal = $meals[$type] ?? null;
            if ($meal !== null && in_array($meal->status, ['pending', 'missed'], true)) {
                $buttons[] = [['text' => MealPlan::LABELS[$type], 'callback_data' => "ate:{$type}"]];
            }
        }

        return $buttons !== [] ? $buttons : null;
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
    private static function todayMeals(NutritionProfile $profile, CarbonImmutable $now): array
    {
        return NutritionMeal::query()
            ->where('profile_id', $profile->id)
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
