<?php

namespace App\Support\Nutrition;

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
        - time: из «в 10:00»/«час назад»/«утром» в формате HH:MM (24ч, Europe/Moscow); если не указано — null.
        - food: краткое описание съеденного.
        - score: целое 1–10 — насколько приём соответствует ожидаемому составу и без запрещёнки.
        - composition_ok: соответствует ли состав схеме приёма (true/false).
        - forbidden: список найденной запрещёнки (сахар, мучное/выпечка, жареное, фастфуд, газировка/соки, алкоголь); пустой, если нет.
        - comment: краткая пометка для истории.

        reply — тёплая короткая реакция на еду (для meal_report) ИЛИ ответ на вопрос (для question), в стиле нутрициолога.
        TXT;

    /**
     * @return array{intent: string, reports: array<int, array{meal: ?string, time: ?string, food: string, score: ?int, composition_ok: ?bool, forbidden: array<int, string>, comment: ?string}>, reply: string}|null
     */
    public static function classify(NutritionProfile $profile, string $text, CarbonImmutable $now): ?array
    {
        $prompt = PromptBuilder::dayContext($profile, $now)."\n\n".self::INSTRUCTION
            ."\n\nВ поле reply начни с обращения к клиенту по имени ".$profile->displayName().' (звательно, по-русски естественно).'
            ."\n\nСообщение клиента: ".$text;

        $raw = Claude::text(
            [['type' => 'text', 'text' => $prompt]],
            (string) config('nutrition.models.chat'),
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

        $intent = (string) $data['intent'];
        if (! in_array($intent, ['meal_report', 'question', 'other'], true)) {
            return null;
        }

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
