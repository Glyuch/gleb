<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use Carbon\CarbonImmutable;

class PromptBuilder
{
    /** Персона нутрициолога — дословно, первая часть system-промпта. */
    private const PERSONA = <<<'TXT'
        Ты — персональный нутрициолог Глеба, работающий по программе TriDaily (10 недель + поддержка).
        Ты совмещаешь две роли из настоящей команды: тёплые короткие реакции ассистента
        («Идеально! 🙌🏼», «Приятного аппетита!», «Поели полдник?☺️», эмодзи 👌🏻🙌🏼☺️⏰)
        и экспертные объяснения главного нутрициолога — всегда объясняешь «почему» через
        физиологию (инсулин, метаболизм, клетчатка), уверенно и по-дружески на «ты».
        Отвечай кратко: реакции на еду — 1–3 предложения; ответы на вопросы — до 6.
        Не выдумывай правил, которых нет в базе знаний. Не назначай лекарства и добавки;
        при медицинских вопросах вне питания советуй врача. Пиши по-русски.
        TXT;

    /**
     * Собирает system-промпт: персона + содержимое всех resources/nutrition/knowledge/*.md
     * (в сортировке имён). Нечитаемые файлы пропускаются молча.
     */
    public static function system(): string
    {
        $parts = [self::PERSONA];

        $files = glob(resource_path('nutrition/knowledge/*.md')) ?: [];
        sort($files);

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $parts[] = trim($content);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Текстовый контекст дня для промптов чата/саммари: фаза и день программы,
     * настройки, приёмы за дату, метрики за 7 дней и последние 30 сообщений.
     */
    public static function dayContext(CarbonImmutable $date): string
    {
        $lines = [];

        // Фаза и день программы.
        $phase = Settings::get('phase');
        $startedOn = Settings::get('program_started_on');
        if ($phase === 'program' && $startedOn !== null) {
            $start = CarbonImmutable::parse((string) $startedOn, 'Europe/Moscow')->startOfDay();
            $dayNum = (int) abs($start->diffInDays($date->startOfDay())) + 1;
            $lines[] = "Фаза: День {$dayNum} программы TriDaily.";
        } else {
            $lines[] = 'Фаза: Режим поддержки.';
        }

        // Настройки.
        $portion = (int) Settings::get('portion_adjustment');
        $portionStr = ($portion > 0 ? '+' : '').$portion.'%';
        $lines[] = 'Настройки: цель шагов '.Settings::get('steps_target')
            .'; поправка порций '.$portionStr
            .'; подъём '.Settings::get('wake_time')
            .', отбой '.Settings::get('sleep_time').'.';

        $windows = Settings::get('default_windows');
        $windowStrings = [];
        foreach (MealPlan::TYPES as $type) {
            if (isset($windows[$type])) {
                $windowStrings[] = MealPlan::LABELS[$type].' '.$windows[$type]['start'].'–'.$windows[$type]['end'];
            }
        }
        $lines[] = 'Окна по умолчанию: '.implode(', ', $windowStrings).'.';

        // Приёмы за дату.
        $lines[] = '';
        $lines[] = 'Приёмы за '.$date->format('d.m.Y').':';
        $meals = NutritionMeal::query()
            ->whereDate('date', $date->format('Y-m-d'))
            ->get()
            ->keyBy('type');

        foreach (MealPlan::TYPES as $type) {
            $label = MealPlan::LABELS[$type];
            $meal = $meals[$type] ?? null;

            if ($meal === null) {
                $lines[] = "— {$label}: нет данных.";

                continue;
            }

            $window = ($meal->window_start !== null && $meal->window_end !== null)
                ? $meal->window_start->format('H:i').'–'.$meal->window_end->format('H:i')
                : '—';

            $line = "— {$label} ({$window}): {$meal->status}";
            if ($meal->eaten_at !== null) {
                $line .= ', съеден в '.$meal->eaten_at->format('H:i');
            }
            if (filled($meal->ai_feedback)) {
                $line .= '. Фидбек: '.$meal->ai_feedback;
            }

            $lines[] = $line.'.';
        }

        // Метрики за последние 7 дней.
        $lines[] = '';
        $lines[] = 'Метрики за последние 7 дней:';
        $metrics = NutritionMetric::query()
            ->whereDate('date', '>=', $date->subDays(6)->format('Y-m-d'))
            ->whereDate('date', '<=', $date->format('Y-m-d'))
            ->orderBy('date')
            ->orderBy('type')
            ->get();

        if ($metrics->isEmpty()) {
            $lines[] = '— нет данных.';
        } else {
            foreach ($metrics as $metric) {
                $lines[] = '— '.$metric->date->format('d.m').' '
                    .self::metricLabel($metric->type).': '.self::metricValue($metric);
            }
        }

        // Последние 30 сообщений.
        $lines[] = '';
        $lines[] = 'Последние сообщения:';
        $messages = NutritionMessage::query()
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->reverse();

        if ($messages->isEmpty()) {
            $lines[] = '— нет данных.';
        } else {
            foreach ($messages as $message) {
                $content = (string) $message->content;
                if (mb_strlen($content) > 200) {
                    $content = mb_substr($content, 0, 200).'…';
                }

                $arrow = $message->direction === 'in' ? '←' : '→';
                $kind = filled($message->kind) ? "[{$message->kind}] " : '';
                $lines[] = trim("{$arrow} {$kind}{$content}");
            }
        }

        return implode("\n", $lines);
    }

    private static function metricLabel(string $type): string
    {
        return match ($type) {
            'weight' => 'вес',
            'steps' => 'шаги',
            'water' => 'вода',
            default => $type,
        };
    }

    private static function metricValue(NutritionMetric $metric): string
    {
        $number = fn (float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');

        return match ($metric->type) {
            'weight' => $number($metric->value).' кг',
            'steps' => (string) (int) $metric->value,
            'water' => $number($metric->value).' л',
            default => $number($metric->value),
        };
    }
}
