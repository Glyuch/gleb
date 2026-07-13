<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionMetric;
use App\Models\NutritionProfile;

/**
 * Данные для страницы статистики профиля (подписанная ссылка) и — позже — админки.
 * Только чтение, наивное московское время.
 */
class NutritionStats
{
    /**
     * @return array{
     *   profile: array{name: string, phase: string, day: int},
     *   weights: list<array{date: string, value: float}>,
     *   scores: list<array{date: string, avg: float, count: int}>,
     *   adherence: list<array{date: string, eaten: int, missed: int, skipped: int}>,
     *   steps: list<array{date: string, value: int, target: int}>,
     *   water: list<array{date: string, value: float}>,
     *   recentMeals: list<array{date: string, type: string, label: string, time: ?string, score: ?int, forbidden: list<string>, window_ok: ?bool, interval_ok: ?bool}>,
     * }
     */
    public static function for(NutritionProfile $profile): array
    {
        $now = $profile->now();
        $from30 = $now->subDays(29)->format('Y-m-d');
        $from14 = $now->subDays(13)->format('Y-m-d');
        $target = (int) $profile->setting('steps_target');

        $weights = NutritionMetric::query()
            ->where('profile_id', $profile->id)
            ->where('type', 'weight')
            ->orderBy('date')
            ->get()
            ->map(fn (NutritionMetric $m) => [
                'date' => $m->date->format('Y-m-d'),
                'value' => (float) $m->value,
            ])
            ->values()
            ->all();

        $meals30 = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', '>=', $from30)
            ->get();

        $scores = $meals30
            ->filter(fn (NutritionMeal $m) => $m->score !== null)
            ->groupBy(fn (NutritionMeal $m) => $m->date->format('Y-m-d'))
            ->map(fn ($group, $date) => [
                'date' => $date,
                'avg' => round((float) $group->avg('score'), 1),
                'count' => $group->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();

        $adherence = $meals30
            ->groupBy(fn (NutritionMeal $m) => $m->date->format('Y-m-d'))
            ->map(fn ($group, $date) => [
                'date' => $date,
                'eaten' => $group->where('status', 'eaten')->count(),
                'missed' => $group->where('status', 'missed')->count(),
                'skipped' => $group->where('status', 'skipped')->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();

        $steps = NutritionMetric::query()
            ->where('profile_id', $profile->id)
            ->where('type', 'steps')
            ->whereDate('date', '>=', $from14)
            ->orderBy('date')
            ->get()
            ->map(fn (NutritionMetric $m) => [
                'date' => $m->date->format('Y-m-d'),
                'value' => (int) $m->value,
                'target' => $target,
            ])
            ->values()
            ->all();

        $water = NutritionMetric::query()
            ->where('profile_id', $profile->id)
            ->where('type', 'water')
            ->whereDate('date', '>=', $from14)
            ->orderBy('date')
            ->get()
            ->map(fn (NutritionMetric $m) => [
                'date' => $m->date->format('Y-m-d'),
                'value' => (float) $m->value,
            ])
            ->values()
            ->all();

        $recentMeals = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'eaten')
            ->orderByDesc('eaten_at')
            ->limit(20)
            ->get()
            ->map(function (NutritionMeal $m) {
                $rating = $m->rating ?? [];

                return [
                    'date' => $m->date->format('Y-m-d'),
                    'type' => $m->type,
                    'label' => MealPlan::LABELS[$m->type] ?? $m->type,
                    'time' => $m->eaten_at?->format('H:i'),
                    'score' => $m->score,
                    'forbidden' => array_values($rating['forbidden'] ?? []),
                    'window_ok' => $rating['window_ok'] ?? null,
                    'interval_ok' => $rating['interval_ok'] ?? null,
                ];
            })
            ->values()
            ->all();

        return [
            'profile' => [
                'name' => $profile->displayName(),
                'phase' => $profile->phase,
                'day' => ProgramStatus::day($profile),
            ],
            'weights' => $weights,
            'scores' => $scores,
            'adherence' => $adherence,
            'steps' => $steps,
            'water' => $water,
            'recentMeals' => $recentMeals,
        ];
    }
}
