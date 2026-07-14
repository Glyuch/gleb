<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionProfile;
use Carbon\CarbonImmutable;

/**
 * Классификатор свободного текста: отличает отчёт о еде от вопроса/прочего и,
 * для отчёта, извлекает список приёмов (тип/время/описание). Один вызов Claude
 * с контекстом дня (окна и статусы приёмов) — ИИ по нему резолвит приём и время.
 */
class MealIntent
{
    private const INSTRUCTION = <<<'TXT'
        Классифицируй сообщение клиента ниже. Верни ОТВЕТ СТРОГО в формате JSON без пояснений и без markdown-заборов:
        {"intent": "meal_report|question|other", "reports": [{"meal": "breakfast|lunch|snack|dinner|null", "time": "HH:MM|null", "food": "краткое описание", "score": 8, "composition_ok": true, "forbidden": ["наименование запрещёнки, если есть"], "comment": "кратко для истории"}], "reply": "текст в стиле нутрициолога"}

        intent:
        - "meal_report" — клиент сообщает, что УЖЕ поел (в т.ч. без фото: «позавтракал», «съел обед», «перекусил»). reports непусто.
        - "question" — вопрос по питанию/программе. reports = [].
        - "other" — всё прочее (болтовня, статусы). reports = [].

        ВАЖНО: сообщение о НАМЕРЕНИИ поесть или о будущем приёме («собираюсь съесть», «планирую на обед», «буду ужинать», «можно ли мне X?») — это intent=question, а НЕ meal_report; reports = []. meal_report только про уже съеденное.

        Для meal_report заполни reports (можно несколько приёмов в одном сообщении):
        - meal: тип из явного слова (позавтракал→breakfast, пообедал→lunch, полдник→snack, поужинал→dinner) ИЛИ инференс по времени/окнам из контекста; если непонятно — null.
        - time: из «в 10:00»/«час назад»/«утром» в формате HH:MM (24ч, в местном времени пользователя); если не указано — null.
        - food: краткое описание съеденного.
        - score: целое 1–10 — насколько приём соответствует ожидаемому составу и без запрещёнки.
        - composition_ok: соответствует ли состав схеме приёма (true/false).
        - forbidden: список найденной запрещёнки (сахар, мучное/выпечка, жареное, фастфуд, газировка/соки, алкоголь); пустой, если нет.
        - comment: краткая пометка для истории.

        reply — тёплая короткая реакция на еду (для meal_report) ИЛИ ответ на вопрос (для question), в стиле нутрициолога.
        TXT;

    /**
     * Доп-инструкция про intent=correct_meal. Подмешивается ТОЛЬКО когда за сегодня
     * есть разобранный (СЪЕДЕН) приём — иначе переоценивать нечего, и обычный
     * вопрос/болтовня не должны триггерить переоценку.
     */
    private const CORRECT_MEAL_HINT = <<<'TXT'
        ДОПОЛНИТЕЛЬНО: сегодня уже есть разобранный (СЪЕДЕН) приём, поэтому допустим ещё intent="correct_meal":
        - "correct_meal" — клиент УТОЧНЯЕТ или ОСПАРИВАЕТ состав/оценку уже разобранного СЕГОДНЯ приёма («это не паштет, а куриная грудка су-вид», «там была гречка, а не рис») ИЛИ прямо просит пересчитать оценку («переоцени ужин», «пересчитай оценку», «а балл?»). reports = [].
        Для correct_meal добавь в объект поле "target": "breakfast|lunch|snack|dinner|null" — тип приёма, если он ЯВНО назван клиентом; иначе null (возьмём последний разобранный).
        correct_meal — это КОРРЕКЦИЯ уже съеденного приёма. Новый отчёт о ещё не записанной еде — это meal_report, а общий вопрос по питанию — question. Сообщение о будущем/намерении — по-прежнему question.
        TXT;

    /**
     * Доп-инструкция про intent=cancel_meal. Подмешивается вместе с correct_meal-хинтом
     * ТОЛЬКО когда за сегодня есть разобранный (СЪЕДЕН) приём — отменять иначе нечего.
     */
    private const CANCEL_MEAL_HINT = <<<'TXT'
        ЕЩЁ допустим intent="cancel_meal":
        - "cancel_meal" — клиент просит ОТМЕНИТЬ / УДАЛИТЬ / СБРОСИТЬ уже записанный СЕГОДНЯ приём (не тот приём, или чтобы прислать заново): «отмени завтрак», «удали ужин», «это не ужин», «я ещё не ужинал», «я ещё не ел», «щас пришлю другое фото», «сейчас другое фото», «пришлю заново». reports = [].
        Для cancel_meal добавь поля:
        - "target": "breakfast|lunch|snack|dinner|null" — тип приёма, если он ЯВНО назван клиентом; иначе null (возьмём последний разобранный).
        - "resend_photo": true|false — true ТОЛЬКО если клиент прямо обещает прислать/переснять фото сейчас («щас пришлю другое фото», «сейчас другое фото», «пришлю заново»); иначе false.
        СТРОГО отличай cancel_meal от correct_meal: поправка состава/оценки уже съеденного приёма («это куриная грудка, а не паштет», «переоцени ужин») — это correct_meal, приём НЕ удаляется. Удаление/пересъёмка приёма («отмени», «это не тот приём», «я ещё не ел», «пришлю заново») — cancel_meal. Сообщение о будущем/намерении поесть — по-прежнему question.
        TXT;

    /**
     * @return array{intent: string, reports: array<int, array{meal: ?string, time: ?string, food: string, score: ?int, composition_ok: ?bool, forbidden: array<int, string>, comment: ?string}>, reply: string, target: ?string, resend_photo: bool}|null
     */
    public static function classify(NutritionProfile $profile, string $text, CarbonImmutable $now): ?array
    {
        // Гейт correct_meal: активен только при наличии разобранного приёма сегодня.
        $hasEvaluated = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('status', 'eaten')
            ->whereNotNull('eaten_at')
            ->exists();

        $instruction = self::INSTRUCTION;
        if ($hasEvaluated) {
            $instruction .= "\n\n".self::CORRECT_MEAL_HINT."\n\n".self::CANCEL_MEAL_HINT;
        }

        $prompt = PromptBuilder::dayContext($profile, $now)."\n\n".$instruction
            ."\n\nВ поле reply начни с обращения к клиенту по имени ".$profile->displayName().' (звательно, по-русски естественно).'
            ."\n\nСообщение клиента: ".$text;

        $raw = Claude::text(
            [['type' => 'text', 'text' => $prompt]],
            (string) config('nutrition.models.fast'),
            500,
            $profile,
        );

        if ($raw === null) {
            return null;
        }

        $data = json_decode(self::stripFences($raw), true);

        if (! is_array($data) || ! isset($data['intent'])) {
            return null;
        }

        $allowed = ['meal_report', 'question', 'other'];
        if ($hasEvaluated) {
            $allowed[] = 'correct_meal';
            $allowed[] = 'cancel_meal';
        }

        $intent = (string) $data['intent'];
        if (! in_array($intent, $allowed, true)) {
            return null;
        }

        // Тип-цель для переоценки/отмены (опц., только для correct_meal/cancel_meal).
        $target = null;
        if (in_array($intent, ['correct_meal', 'cancel_meal'], true)) {
            $candidate = $data['target'] ?? null;
            if (in_array($candidate, MealPlan::TYPES, true)) {
                $target = $candidate;
            }
        }

        // Флаг «пришлю фото заново» (опц., только для cancel_meal) — включает
        // replace_photo-поток: следующее фото перезапишет отменённый приём.
        $resendPhoto = $intent === 'cancel_meal' && ($data['resend_photo'] ?? false) === true;

        $reports = [];
        if ($intent === 'meal_report' && isset($data['reports']) && is_array($data['reports'])) {
            foreach ($data['reports'] as $report) {
                if (! is_array($report)) {
                    continue;
                }

                $meal = $report['meal'] ?? null;
                if (! in_array($meal, MealPlan::TYPES, true)) {
                    $meal = null;
                }

                $time = $report['time'] ?? null;
                if (! is_string($time) || ! preg_match('/^\d{1,2}:\d{2}$/', $time)) {
                    $time = null;
                }

                $extra = MealLogger::ratingExtra(is_array($report) ? $report : []);

                $reports[] = [
                    'meal' => $meal,
                    'time' => $time,
                    'food' => (string) ($report['food'] ?? ''),
                    'score' => MealLogger::validScore($report['score'] ?? null),
                    'composition_ok' => $extra['composition_ok'],
                    'forbidden' => $extra['forbidden'],
                    'comment' => $extra['comment'],
                ];
            }
        }

        return [
            'intent' => $intent,
            'reports' => $reports,
            'reply' => (string) ($data['reply'] ?? ''),
            'target' => $target,
            'resend_photo' => $resendPhoto,
        ];
    }

    private static function stripFences(string $raw): string
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }
}
