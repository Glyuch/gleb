<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionProfile;
use App\Models\NutritionTopic;
use Carbon\CarbonImmutable;

class StartProgram
{
    /** Смещения в днях от старта для позиций тем 1..12 (≈2 темы в неделю). */
    private const OFFSET_STEP = 5;

    private const FIRST_OFFSET = 3;

    /**
     * Стартует программу профиля: фиксирует дату старта на профиле, переводит в
     * фазу program и раскладывает scheduled_on тем (позиция 1 → день 3, каждая
     * следующая +5 дней, позиция 12 → день 58). Идемпотентно: повторный запуск
     * пересчитывает даты.
     *
     * Раскладка тем остаётся глобальной (NutritionTopic.scheduled_on) — перевод
     * на per-profile NutritionTopicSend делает Task 3.
     *
     * @return string человекочитаемое резюме (дата старта, сколько тем запланировано)
     */
    public function handle(NutritionProfile $profile, ?CarbonImmutable $date = null): string
    {
        $date = $date?->setTimezone('Europe/Moscow');
        $start = ($date ?? CarbonImmutable::now('Europe/Moscow'))->startOfDay();

        $profile->update([
            'program_started_on' => $start->format('Y-m-d'),
            'phase' => 'program',
        ]);

        $topics = NutritionTopic::query()->orderBy('position')->get();

        foreach ($topics as $topic) {
            $offset = self::FIRST_OFFSET + ($topic->position - 1) * self::OFFSET_STEP;
            $topic->update(['scheduled_on' => $start->addDays($offset)->format('Y-m-d')]);
        }

        $first = $topics->first();
        $last = $topics->last();

        $summary = "Программа стартует {$start->format('d.m.Y')}. Запланировано тем: {$topics->count()}.";

        if ($first !== null && $last !== null) {
            $firstOn = $start->addDays(self::FIRST_OFFSET)->format('d.m.Y');
            $lastOffset = self::FIRST_OFFSET + ($last->position - 1) * self::OFFSET_STEP;
            $lastOn = $start->addDays($lastOffset)->format('d.m.Y');
            $summary .= " Первая тема «{$first->title}» — {$firstOn}, последняя «{$last->title}» — {$lastOn}.";
        }

        return $summary;
    }
}
