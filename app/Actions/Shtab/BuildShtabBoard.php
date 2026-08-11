<?php

namespace App\Actions\Shtab;

use App\Models\ShtabAssignment;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use App\Models\ShtabTask;
use Carbon\CarbonImmutable;

class BuildShtabBoard
{
    /**
     * Собирает весь стейт доски одним массивом: люди и территории с активными
     * назначениями плюс выведенные флаги (резерв, перегруз, дыры покрытия).
     *
     * @return array{people: array<int, mixed>, objects: array<int, mixed>, business_metrics: array<int, mixed>, roles: array<int, mixed>, capacity_percent: int}
     */
    public function handle(): array
    {
        $today = CarbonImmutable::today();
        $threshold = (int) config('shtab.overload_threshold');
        $capacity = max(1, (int) config('shtab.capacity_percent'));
        /** @var array<string, array{label: string, short: string, default_load: int}> $roles */
        $roles = config('shtab.roles');

        $people = ShtabPerson::query()->active()
            ->with(['activeAssignments.object:id,name,emoji,focus_level'])
            ->orderBy('sort')->orderBy('name')
            ->get();

        $objects = ShtabObject::query()->active()
            ->with(['metrics', 'activeAssignments.person:id,name,initials,class,color', 'tasks.assignee:id,name,initials,color'])
            ->orderByDesc('focus_level')->orderBy('sort')->orderBy('name')
            ->get();

        $keyTasksByPerson = ShtabTask::query()->open()
            ->where('is_key', true)
            ->whereNotNull('assignee_person_id')
            ->with('object:id,name,emoji')
            ->orderBy('id')
            ->get()
            ->groupBy('assignee_person_id');

        return [
            'people' => $people->map(function (ShtabPerson $person) use ($today, $threshold, $capacity, $keyTasksByPerson): array {
                $hotCount = $person->activeAssignments
                    ->filter(fn (ShtabAssignment $a): bool => $a->object->focus_level >= 1)
                    ->count();
                $load = (int) $person->activeAssignments->sum(fn (ShtabAssignment $a): int => $a->load_percent);
                $ownerCount = $person->activeAssignments->where('role_type', 'owner')->count();

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
                        'object_focus_level' => (int) $a->object->focus_level,
                        'role_label' => $a->role_label,
                        'role_type' => $a->role_type,
                        'load_percent' => $a->load_percent,
                        'comment' => $a->comment,
                        'started_at' => $a->started_at->toDateString(),
                        'days' => (int) $a->started_at->diffInDays($today),
                    ])->values()->all(),
                    'focus_count' => $person->activeAssignments->count(),
                    'hot_count' => $hotCount,
                    'owner_count' => $ownerCount,
                    'load_percent' => $load,
                    'load_status' => $this->loadStatus($load, $capacity),
                    'is_overloaded' => $hotCount > $threshold || $load > $capacity,
                    'in_reserve' => $person->activeAssignments->isEmpty(),
                    'key_tasks' => $keyTasksByPerson->get($person->id, collect())
                        ->map(fn (ShtabTask $task): array => [
                            'id' => $task->id,
                            'object_name' => $task->object?->name,
                            'object_emoji' => $task->object?->emoji,
                            'title' => $task->title,
                        ])->values()->all(),
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
                    'description' => $object->description,
                    'emoji' => $object->emoji,
                    'focus_level' => $object->focus_level,
                    'color' => $object->color,
                    'metrics' => $object->metrics->map(fn (ShtabMetric $m): array => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'status' => $m->status,
                        'value_text' => $m->value_text,
                    ])->values()->all(),
                    'assignments' => $object->activeAssignments
                        ->sortBy(fn (ShtabAssignment $a): int => $this->roleOrder($a->role_type))
                        ->map(fn (ShtabAssignment $a): array => [
                            'id' => $a->id,
                            'person_id' => $a->person_id,
                            'person_name' => $a->person?->name,
                            'person_initials' => $a->person?->initials,
                            'person_color' => $a->person?->color,
                            'role_label' => $a->role_label,
                            'role_type' => $a->role_type,
                            'load_percent' => $a->load_percent,
                            'started_at' => $a->started_at->toDateString(),
                            'days' => (int) $a->started_at->diffInDays($today),
                        ])->values()->all(),
                    'owner_name' => $object->activeAssignments->firstWhere('role_type', 'owner')?->person?->name,
                    'load_total' => (int) $object->activeAssignments->sum(fn (ShtabAssignment $a): int => $a->load_percent),
                    'has_owner' => $object->activeAssignments->contains(fn (ShtabAssignment $a): bool => $a->role_type === 'owner'),
                    'tasks' => $object->tasks
                        ->sortBy([['is_done', 'asc'], ['is_key', 'desc'], ['sort', 'asc'], ['id', 'asc']])
                        ->map(fn (ShtabTask $task): array => [
                            'id' => $task->id,
                            'title' => $task->title,
                            'is_done' => $task->is_done,
                            'is_key' => $task->is_key,
                            'assignee' => $task->assignee?->only(['id', 'name', 'initials', 'color']),
                        ])->values()->all(),
                    'open_tasks' => $object->tasks->where('is_done', false)->count(),
                    'total_tasks' => $object->tasks->count(),
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
            'roles' => array_map(fn (string $key, array $role): array => [
                'key' => $key,
                'label' => $role['label'],
                'short' => $role['short'],
                'default_load' => (int) $role['default_load'],
            ], array_keys($roles), array_values($roles)),
            'capacity_percent' => $capacity,
        ];
    }

    /**
     * Порядок ролей на карточке территории — как в конфиге: владелец первым.
     */
    private function roleOrder(string $roleType): int
    {
        $index = array_search($roleType, ShtabAssignment::roleTypes(), true);

        return $index === false ? 99 : (int) $index;
    }

    /**
     * free — есть куда грузить, ok — рабочая загрузка, full — под завязку, over — перегруз.
     */
    private function loadStatus(int $load, int $capacity): string
    {
        return match (true) {
            $load > $capacity => 'over',
            $load >= $capacity * 0.9 => 'full',
            $load >= $capacity * 0.4 => 'ok',
            default => 'free',
        };
    }
}
