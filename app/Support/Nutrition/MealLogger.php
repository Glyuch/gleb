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
            $parts[] = Address::ensure($profile, trim($reply));
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

        // Кнопка переоценки под текст-разбором: цепляем к последнему записанному
        // приёму (resolved уже отсортирован по времени), чтобы клиент мог поправить
        // состав. Ничего не записали (только «уже отмечен») — без кнопки.
        $keyboard = null;
        if ($resolved !== []) {
            $keyboard = self::mealActions(end($resolved)['meal']);
        }

        $tg->send(implode("\n\n", $parts), $keyboard, chatId: $chatId);
    }

    /**
     * Переоценивает УЖЕ разобранный приём по авторитетному описанию клиента
     * (текстовая модель, sonnet). Слова клиента важнее прошлого разбора: фото могло
     * ввести в заблуждение, поэтому фото повторно НЕ гоняем. Выход — тот же формат,
     * что у фото-разбора (parseFood): {feedback, score, extra}.
     *
     * @return array{feedback: ?string, score: ?int, extra: array{composition_ok: ?bool, forbidden: array<int, string>, comment: ?string}|null}
     */
    public static function reevaluate(NutritionProfile $profile, NutritionMeal $meal, string $userText): array
    {
        $raw = Claude::text(
            [['type' => 'text', 'text' => self::reevalPrompt($profile, $meal, $userText)]],
            (string) config('nutrition.models.fast'),
            400,
            $profile,
        );

        return self::parseFood($raw);
    }

    /**
     * Текстовый промпт переоценки: тип приёма + правила состава/запрещёнка (как в
     * фото-разборе), прошлый вердикт как контекст и авторитетное уточнение клиента.
     * Приоритет — за словами клиента. Просит СТРОГИЙ JSON того же формата, что
     * foodPrompt, НО без обращения по имени в feedback (обращение добавляет вызывающий
     * код через Address::ensure — чтобы имя не дублировалось).
     */
    public static function reevalPrompt(NutritionProfile $profile, NutritionMeal $meal, string $userText): string
    {
        $type = $meal->type;
        $portion = (int) $profile->setting('portion_adjustment');
        $portionStr = ($portion > 0 ? '+' : '').$portion.'%';

        $prior = '';
        if ($meal->score !== null) {
            $prior .= 'Ранее приёму поставлен балл '.$meal->score.'/10';
            $prior .= filled($meal->ai_feedback) ? ' с фидбеком: '.$meal->ai_feedback."\n" : ".\n";
        } elseif (filled($meal->ai_feedback)) {
            $prior .= 'Ранее бот написал: '.$meal->ai_feedback."\n";
        }

        return 'Переоцени УЖЕ записанный приём по УТОЧНЕНИЮ клиента. Прошлый разбор мог быть по фото и ошибиться — словам клиента о составе и способе готовки верь БОЛЬШЕ, чем прежней оценке.'."\n"
            .'Приём: '.MealPlan::LABELS[$type].".\n"
            .'Ожидаемый состав: '.MealPlan::COMPOSITION[$type].".\n"
            .'Запрещёнка (кратко): '.self::FORBIDDEN.".\n"
            .'Поправка порций: '.$portionStr.".\n"
            .$prior
            .'Клиент уточняет: '.$userText."\n"
            .'Пересчитай оценку по словам клиента и верни ОТВЕТ СТРОГО в формате JSON без пояснений и без markdown-заборов:'."\n"
            .'{"feedback": "реакция нутрициолога — тепло и по делу, 1–3 предложения, без обращения по имени; при необходимости кратко «почему» через физиологию", '
            .'"score": 8, "composition_ok": true, "forbidden": ["наименование запрещёнки, если есть"], "comment": "кратко для истории"}'."\n"
            .'score — целое 1–10 (насколько уточнённый приём соответствует ожидаемому составу и без запрещёнки). '
            .'composition_ok — соответствует ли состав схеме. forbidden — список найденной запрещёнки (пустой, если нет).';
    }

    /**
     * Последний разобранный (СЪЕДЕН, с eaten_at) приём за сегодня — цель для
     * переоценки по естественному тексту. $type задан → строго этот тип (иначе null).
     */
    public static function lastEvaluatedMeal(NutritionProfile $profile, CarbonImmutable $now, ?string $type = null): ?NutritionMeal
    {
        $query = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('status', 'eaten')
            ->whereNotNull('eaten_at');

        if ($type !== null) {
            if (! in_array($type, MealPlan::TYPES, true)) {
                return null;
            }
            $query->where('type', $type);
        }

        return $query->orderByDesc('eaten_at')->first();
    }

    /**
     * Inline-ряд действий под сообщением-разбором приёма: «переоценить» (callback
     * reeval:{meal_id}) и «отменить» (callback cancel:{meal_id}) — обе кнопки в
     * одном ряду reply_markup.inline_keyboard. Переоценка меняет балл, не удаляя
     * приём; отмена возвращает приём в pending, чтобы прислать заново.
     *
     * @return array<int, array<int, array<string, string>>>
     */
    public static function mealActions(NutritionMeal $meal): array
    {
        return [[
            ['text' => '🔄 Состав другой / переоценить', 'callback_data' => 'reeval:'.$meal->id],
            ['text' => '↩️ Отменить', 'callback_data' => 'cancel:'.$meal->id],
        ]];
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
            .'{"feedback": "реакция нутрициолога, начни с обращения к клиенту по имени '.$profile->displayName().' (звательно) — тепло и по делу, 1–3 предложения, при необходимости кратко «почему» через физиологию", '
            .'"score": 8, "composition_ok": true, "forbidden": ["наименование запрещёнки, если есть"], "comment": "кратко для истории"}'."\n"
            .'score — целое 1–10 (насколько приём соответствует ожидаемому составу и без запрещёнки). '
            .'composition_ok — соответствует ли состав схеме. forbidden — список найденной запрещёнки (пустой, если нет).';
    }

    /**
     * Разбирает ответ vision на фидбек + структуру рейтинга.
     * Не-JSON → feedback = сырой текст, score null, extra null.
     * Валидный JSON → feedback СТРОГО из поля feedback (сырой JSON пользователю
     * не уходит; пустое поле → нейтральная фраза), score = validScore (битый/
     * отсутствующий → null), extra сохраняется всегда — консистентно с MealIntent:
     * невалидный score нулит только score, composition_ok/forbidden/comment остаются.
     *
     * @return array{feedback: ?string, score: ?int, extra: array{composition_ok: ?bool, forbidden: array<int, string>, comment: ?string}|null}
     */
    public static function parseFood(?string $raw): array
    {
        if ($raw === null) {
            return ['feedback' => null, 'score' => null, 'extra' => null];
        }

        $data = json_decode(self::stripFences($raw), true);

        // Не-JSON (или JSON-скаляр) → фидбек = сырой текст, без структуры.
        if (! is_array($data)) {
            return ['feedback' => trim($raw), 'score' => null, 'extra' => null];
        }

        $feedback = trim((string) ($data['feedback'] ?? ''));

        return [
            'feedback' => $feedback !== '' ? $feedback : 'Записал приём 👌🏻',
            'score' => self::validScore($data['score'] ?? null),
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
