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
        {"intent": "meal_report|question|other", "reports": [{"meal": "breakfast|lunch|snack|dinner|null", "time": "HH:MM|null", "food": "краткое описание"}], "reply": "текст в стиле нутрициолога"}

        intent:
        - "meal_report" — клиент сообщает, что уже поел (в т.ч. без фото: «позавтракал», «съел обед», «перекусил»). reports непусто.
        - "question" — вопрос по питанию/программе. reports = [].
        - "other" — всё прочее (болтовня, статусы). reports = [].

        Для meal_report заполни reports (можно несколько приёмов в одном сообщении):
        - meal: тип из явного слова (позавтракал→breakfast, пообедал→lunch, полдник→snack, поужинал→dinner) ИЛИ инференс по времени/окнам из контекста; если непонятно — null.
        - time: из «в 10:00»/«час назад»/«утром» в формате HH:MM (24ч, Europe/Moscow); если не указано — null.
        - food: краткое описание съеденного.

        reply — тёплая короткая реакция на еду (для meal_report) ИЛИ ответ на вопрос (для question), в стиле нутрициолога.
        TXT;

    /**
     * @return array{intent: string, reports: array<int, array{meal: ?string, time: ?string, food: string}>, reply: string}|null
     */
    public static function classify(NutritionProfile $profile, string $text, CarbonImmutable $now): ?array
    {
        $prompt = PromptBuilder::dayContext($profile, $now)."\n\n".self::INSTRUCTION."\n\nСообщение клиента: ".$text;

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

                $reports[] = [
                    'meal' => $meal,
                    'time' => $time,
                    'food' => (string) ($report['food'] ?? ''),
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
