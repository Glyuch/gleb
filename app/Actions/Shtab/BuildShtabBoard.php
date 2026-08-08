<?php

namespace App\Actions\Shtab;

use App\Models\ShtabAssignment;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use Carbon\CarbonImmutable;

class BuildShtabBoard
{
    /**
     * Собирает весь стейт доски одним массивом: люди и территории с активными
     * назначениями плюс выведенные флаги (резерв, перегруз, дыры покрытия).
     *
     * @return array{people: array<int, mixed>, objects: array<int, mixed>, business_metrics: array<int, mixed>}
     */
    public function handle(): array
    {
        $today = CarbonImmutable::today();
        $threshold = (int) config('shtab.overload_threshold');

        $people = ShtabPerson::query()->active()
            ->with(['activeAssignments.object:id,name,emoji,focus_level'])
            ->orderBy('sort')->orderBy('name')
            ->get();

        $objects = ShtabObject::query()->active()
            ->with(['metrics', 'activeAssignments.person:id,name,initials,class,color'])
            ->orderByDesc('focus_level')->orderBy('sort')->orderBy('name')
            ->get();

        return [
            'people' => $people->map(function (ShtabPerson $person) use ($today, $threshold): array {
                $hotCount = $person->activeAssignments
                    ->filter(fn (ShtabAssignment $a): bool => $a->object->focus_level >= 1)
                    ->count();

                return [
                    'id' => $person->id,
                    'name' => $person->name,
                    'initials' => $person->initials,
                    'class' => $person->class,
                    'color' => $person->color,
                    'is_direct' => $person->is_direct,
                    'manager_id' => $person->manager_id,
                    'is_me' => $person->is_me,
                    'assignments' => $person->activeAssignments->map(fn (ShtabAssignment $a): array => [
                        'id' => $a->id,
                        'object_id' => $a->object_id,
                        'object_name' => $a->object?->name,
                        'object_emoji' => $a->object?->emoji,
                        'role_label' => $a->role_label,
                        'comment' => $a->comment,
                        'started_at' => $a->started_at->toDateString(),
                        'days' => (int) $a->started_at->diffInDays($today),
                    ])->values()->all(),
                    'focus_count' => $person->activeAssignments->count(),
                    'hot_count' => $hotCount,
                    'is_overloaded' => $hotCount > $threshold,
                    'in_reserve' => $person->activeAssignments->isEmpty(),
                ];
            })->values()->all(),
            'objects' => $objects->map(function (ShtabObject $object) use ($today): array {
                $uncovered = $object->activeAssignments->isEmpty();
                $uncoveredDays = null;

                if ($uncovered) {
                    $lastEnd = ShtabAssignment::query()
                        ->where('object_id', $object->id)
                        ->whereNotNull('ended_at')
                        ->max('ended_at');
                    $from = ($lastEnd ? CarbonImmutable::parse($lastEnd) : $object->created_at->toImmutable())->startOfDay();
                    $uncoveredDays = (int) $from->diffInDays($today);
                }

                return [
                    'id' => $object->id,
                    'type' => $object->type,
                    'parent_id' => $object->parent_id,
                    'name' => $object->name,
                    'emoji' => $object->emoji,
                    'focus_level' => $object->focus_level,
                    'color' => $object->color,
                    'metrics' => $object->metrics->map(fn (ShtabMetric $m): array => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'status' => $m->status,
                        'value_text' => $m->value_text,
                    ])->values()->all(),
                    'assignments' => $object->activeAssignments->map(fn (ShtabAssignment $a): array => [
                        'id' => $a->id,
                        'person_id' => $a->person_id,
                        'person_name' => $a->person?->name,
                        'person_initials' => $a->person?->initials,
                        'person_color' => $a->person?->color,
                        'role_label' => $a->role_label,
                        'started_at' => $a->started_at->toDateString(),
                        'days' => (int) $a->started_at->diffInDays($today),
                    ])->values()->all(),
                    'is_uncovered' => $uncovered,
                    'uncovered_days' => $uncoveredDays,
                ];
            })->values()->all(),
            'business_metrics' => ShtabMetric::query()
                ->whereNull('object_id')->orderBy('sort')->get()
                ->map(fn (ShtabMetric $m): array => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'status' => $m->status,
                    'value_text' => $m->value_text,
                ])->values()->all(),
        ];
    }
}
