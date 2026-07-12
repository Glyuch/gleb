<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use App\Models\NutritionProfile;
use Carbon\CarbonImmutable;

class PromptBuilder
{
    /**
     * Персона нутрициолога — первая часть system-промпта. Имя клиента берётся из
     * профиля (пустое → «клиент»), чтобы персона не была захардкожена под владельца.
     */
    private static function persona(?NutritionProfile $profile): string
    {
        $name = $profile?->displayName() ?? 'клиент';

        return <<<TXT
            Ты — персональный нутрициолог клиента по имени {$name}, работающий по программе TriDaily (10 недель + поддержка).
            Ты совмещаешь две роли из настоящей команды: тёплые короткие реакции ассистента
            («Идеально! 🙌🏼», «Приятного аппетита!», «Поели полдник?☺️», эмодзи 👌🏻🙌🏼☺️⏰)
            и экспертные объяснения главного нутрициолога — всегда объясняешь «почему» через
            физиологию (инсулин, метаболизм, клетчатка), уверенно и по-дружески на «ты».
            Отвечай кратко: реакции на еду — 1–3 предложения; ответы на вопросы — до 6.
            Не выдумывай правил, которых нет в базе знаний. Не назначай лекарства и добавки;
            при медицинских вопросах вне питания советуй врача. Пиши по-русски.
            TXT;
    }

    /**
     * Собирает system-промпт: персона + содержимое resources/nutrition/knowledge/*.md
     * (в сортировке имён; файлы 04-* — legacy-профиль Глеба — исключены, профиль
     * клиента теперь приходит из БД) + персональный ai_profile переданного профиля.
     * Нечитаемые файлы пропускаются молча.
     */
    public static function system(?NutritionProfile $profile = null): string
    {
        $parts = [self::persona($profile)];

        $files = glob(resource_path('nutrition/knowledge/*.md')) ?: [];
        sort($files);

        foreach ($files as $file) {
            if (str_starts_with(basename($file), '04-')) {
                continue;
            }

            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $parts[] = trim($content);
        }

        if ($profile !== null && filled($profile->ai_profile)) {
            $parts[] = "# Профиль клиента\n".trim((string) $profile->ai_profile);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Текстовый контекст дня для промптов чата/саммари: фаза и день программы,
     * настройки, приёмы за дату, метрики за 7 дней и последние 30 сообщений.
     */
    public static function dayContext(NutritionProfile $profile, CarbonImmutable $date): string
    {
        $lines = [];

        $lines[] = 'Клиент: '.$profile->displayName().'.';

        // Фаза и день программы. Номер дня — единый источник: ProgramStatus::day().
        if ($profile->phase === 'program' && $profile->program_started_on !== null) {
            $lines[] = 'Фаза: День '.ProgramStatus::day($profile).' программы TriDaily.';
        } else {
            $lines[] = 'Фаза: Режим поддержки.';
        }

        // Настройки.
        $portion = (int) $profile->setting('portion_adjustment');
        $portionStr = ($portion > 0 ? '+' : '').$portion.'%';
        $lines[] = 'Настройки: цель шагов '.$profile->setting('steps_target')
            .'; поправка порций '.$portionStr
            .'; подъём '.$profile->setting('wake_time')
            .', отбой '.$profile->setting('sleep_time').'.';

        $windows = $profile->setting('default_windows');
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
            ->where('profile_id', $profile->id)
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
            if ($meal->score !== null) {
                $line .= ', балл '.$meal->score.'/10';
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
            ->where('profile_id', $profile->id)
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
            ->where('profile_id', $profile->id)
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

    /**
     * Детерминированный дайджест рейтингов за период [fromDate, toDate] (Y-m-d):
     * средний балл съеденных приёмов и список нарушений-запрещёнки. Готовый текст
     * для промптов саммари/чек-апа — считается из БД, а не из ответа ИИ.
     */
    public static function ratingsDigest(NutritionProfile $profile, string $fromDate, string $toDate, string $prefix = ''): string
    {
        $meals = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'eaten')
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->get();

        $scores = $meals->pluck('score')->filter(fn ($s) => $s !== null)->values();

        $forbidden = [];
        foreach ($meals as $meal) {
            $rating = $meal->rating;
            if (is_array($rating) && is_array($rating['forbidden'] ?? null)) {
                foreach ($rating['forbidden'] as $item) {
                    $str = trim((string) $item);
                    if ($str !== '') {
                        $forbidden[$str] = $str;
                    }
                }
            }
        }

        $avg = $scores->isEmpty()
            ? 'нет данных'
            : number_format((float) $scores->avg(), 1, '.', '').'/10 (по '.$scores->count().' приёмам)';

        $lines = [$prefix.'Средний балл приёмов: '.$avg.'.'];
        $lines[] = 'Нарушения (запрещёнка): '.($forbidden === [] ? 'нет' : implode(', ', array_values($forbidden))).'.';

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
