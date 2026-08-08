<?php

namespace App\Support\Shtab;

use App\Models\ShtabAssignment;
use App\Models\ShtabEvent;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use App\Models\ShtabTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ApplyOperations
{
    /**
     * Исполняет выбранные операции ИИ-разбора той же бизнес-логикой, что и ручные
     * контроллеры (те же гарды, те же события Хроники, комментарий с префиксом
     * «ИИ-разбор: …»). Каждая операция — в своей транзакции: падение одной не валит
     * пачку. После цикла пишет событие ai_digest с итогами.
     *
     * @param  array<int, array<string, mixed>>  $operations
     * @param  array<int, array<string, mixed>>  $unparsed
     * @return array{applied: array<int, int>, failed: array<int, array{index: int, summary: string, reason: string}>}
     */
    public function apply(array $operations, array $unparsed = [], ?string $text = null): array
    {
        $applied = [];
        $failed = [];

        foreach (array_values($operations) as $index => $operation) {
            try {
                DB::transaction(function () use ($operation): void {
                    $this->applyOne($operation);
                });

                $applied[] = $index;
            } catch (Throwable $e) {
                $summary = $operation['summary'] ?? null;

                $failed[] = [
                    'index' => $index,
                    'summary' => is_string($summary) ? $summary : '',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        ShtabEvent::record('ai_digest', [
            'payload' => [
                'applied' => count($applied),
                'failed' => count($failed),
                'unparsed' => $unparsed,
            ],
            'comment' => $text !== null && $text !== '' ? mb_substr($text, 0, 1000) : null,
        ]);

        return ['applied' => $applied, 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function applyOne(array $operation): void
    {
        $comment = $this->comment($operation);

        match ($operation['type'] ?? null) {
            'assign' => $this->assign($operation, $comment),
            'end_assignment' => $this->endAssignment($operation, $comment),
            'move_assignment' => $this->moveAssignment($operation, $comment),
            'metric_status' => $this->metricStatus($operation, $comment),
            'focus_level' => $this->focusLevel($operation, $comment),
            'update_description' => $this->updateDescription($operation),
            'task_add' => $this->taskAdd($operation, $comment),
            'task_done' => $this->taskDone($operation, $comment),
            'task_assign' => $this->taskAssign($operation, $comment),
            'task_key' => $this->taskKey($operation),
            default => throw new RuntimeException('Неизвестный тип операции.'),
        };
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function assign(array $operation, ?string $comment): void
    {
        $person = $this->person($this->intField($operation, 'person_id', 'персонаж (person_id)'));
        $object = $this->object($this->intField($operation, 'object_id', 'территория (object_id)'));
        $roleLabel = $this->stringField($operation, 'role_label', 'роль (role_label)');

        $this->startAssignment($person->id, $object->id, $roleLabel, $comment);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function endAssignment(array $operation, ?string $comment): void
    {
        $assignment = $this->assignment($this->intField($operation, 'assignment_id', 'назначение (assignment_id)'));

        $this->endAssignmentRow($assignment, $comment);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function moveAssignment(array $operation, ?string $comment): void
    {
        $assignment = $this->assignment($this->intField($operation, 'assignment_id', 'назначение (assignment_id)'));
        $object = $this->object($this->intField($operation, 'object_id', 'территория (object_id)'));
        $roleLabel = $this->stringField($operation, 'role_label', 'роль (role_label)');

        // Как в ручном move: дубль на целевой территории проверяется ДО снятия.
        $duplicate = ShtabAssignment::query()->active()
            ->where('person_id', $assignment->person_id)
            ->where('object_id', $object->id)
            ->exists();

        if ($duplicate) {
            throw new RuntimeException('Этот человек уже назначен на эту территорию.');
        }

        $this->endAssignmentRow($assignment, $comment);
        $this->startAssignment($assignment->person_id, $object->id, $roleLabel, $comment);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function metricStatus(array $operation, ?string $comment): void
    {
        $metric = $this->metric($this->intField($operation, 'metric_id', 'метрика (metric_id)'));
        $status = $this->stringField($operation, 'status', 'статус (status)');

        if (! in_array($status, ['green', 'yellow', 'red'], true)) {
            throw new RuntimeException('Неизвестный статус метрики: '.$status.'.');
        }

        $valueText = $operation['value_text'] ?? null;
        $newValueText = is_string($valueText) && $valueText !== '' ? $valueText : $metric->value_text;

        if ($metric->status === $status && $newValueText === $metric->value_text) {
            return; // Но-оп, как в ручном контроллере: без события.
        }

        $from = $metric->status;
        $metric->update(['status' => $status, 'value_text' => $newValueText]);

        ShtabEvent::record('metric_status_changed', [
            'metric_id' => $metric->id,
            'object_id' => $metric->object_id,
            'payload' => ['from' => $from, 'to' => $status, 'value_text' => $newValueText],
            'comment' => $comment,
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function focusLevel(array $operation, ?string $comment): void
    {
        $object = $this->object($this->intField($operation, 'object_id', 'территория (object_id)'));
        $level = $this->intField($operation, 'focus_level', 'уровень огня (focus_level)');

        if (! in_array($level, [0, 1, 2], true)) {
            throw new RuntimeException('Уровень огня должен быть 0, 1 или 2.');
        }

        if ($object->focus_level === $level) {
            return; // Но-оп, как в ручном контроллере: без события.
        }

        $from = $object->focus_level;
        $object->update(['focus_level' => $level]);

        ShtabEvent::record('focus_level_changed', [
            'object_id' => $object->id,
            'payload' => ['from' => $from, 'to' => $level],
            'comment' => $comment,
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function updateDescription(array $operation): void
    {
        $object = $this->object($this->intField($operation, 'object_id', 'территория (object_id)'));
        $append = $this->stringField($operation, 'description_append', 'текст дополнения (description_append)');

        $current = $object->description;
        $combined = trim(($current !== null && $current !== '' ? $current."\n" : '').$append);

        if (mb_strlen($combined) > 2000) {
            throw new RuntimeException('Описание территории превысит лимит в 2000 символов.');
        }

        $object->update(['description' => $combined]);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function taskAdd(array $operation, ?string $comment): void
    {
        $object = $this->object($this->intField($operation, 'object_id', 'территория (object_id)'));
        $title = $this->stringField($operation, 'title', 'название задачи (title)');

        if (mb_strlen($title) > 500) {
            throw new RuntimeException('Название задачи длиннее 500 символов.');
        }

        $assignee = isset($operation['person_id'])
            ? $this->person($this->intField($operation, 'person_id', 'персонаж (person_id)'))
            : null;

        $task = ShtabTask::query()->create([
            'object_id' => $object->id,
            'title' => $title,
            'assignee_person_id' => $assignee?->id,
        ]);

        if ($assignee !== null) {
            ShtabEvent::record('task_assigned', [
                'person_id' => $assignee->id,
                'object_id' => $task->object_id,
                'payload' => ['title' => $task->title],
                'comment' => $comment,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function taskDone(array $operation, ?string $comment): void
    {
        $task = $this->task($this->intField($operation, 'task_id', 'задача (task_id)'));

        if ($task->is_done) {
            return; // Но-оп, как в ручном контроллере: без события.
        }

        $task->is_done = true;
        $task->done_at = now()->toImmutable();
        $task->save();

        ShtabEvent::record('task_done', [
            'object_id' => $task->object_id,
            'person_id' => $task->assignee_person_id,
            'payload' => ['title' => $task->title],
            'comment' => $comment,
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function taskAssign(array $operation, ?string $comment): void
    {
        $task = $this->task($this->intField($operation, 'task_id', 'задача (task_id)'));
        $person = $this->person($this->intField($operation, 'person_id', 'персонаж (person_id)'));

        if ($person->id !== $task->assignee_person_id) {
            ShtabEvent::record('task_assigned', [
                'person_id' => $person->id,
                'object_id' => $task->object_id,
                'payload' => ['title' => $task->title],
                'comment' => $comment,
            ]);
        }

        $task->assignee_person_id = $person->id;
        $task->save();
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function taskKey(array $operation): void
    {
        $task = $this->task($this->intField($operation, 'task_id', 'задача (task_id)'));

        if ($task->is_key) {
            return;
        }

        ShtabTask::query()
            ->where('object_id', $task->object_id)
            ->where('is_key', true)
            ->whereKeyNot($task->id)
            ->update(['is_key' => false]);

        $task->is_key = true;
        $task->save();
    }

    private function startAssignment(int $personId, int $objectId, string $roleLabel, ?string $comment): void
    {
        $duplicate = ShtabAssignment::query()->active()
            ->where('person_id', $personId)
            ->where('object_id', $objectId)
            ->exists();

        if ($duplicate) {
            throw new RuntimeException('Этот человек уже назначен на эту территорию.');
        }

        ShtabAssignment::query()->create([
            'person_id' => $personId,
            'object_id' => $objectId,
            'role_label' => $roleLabel,
            'comment' => $comment,
            'started_at' => now()->toDateString(),
        ]);

        ShtabEvent::record('assignment_started', [
            'person_id' => $personId,
            'object_id' => $objectId,
            'payload' => ['role_label' => $roleLabel],
            'comment' => $comment,
        ]);
    }

    private function endAssignmentRow(ShtabAssignment $assignment, ?string $comment): void
    {
        if ($assignment->ended_at !== null) {
            throw new RuntimeException('Назначение уже завершено.');
        }

        $today = CarbonImmutable::today();
        $assignment->update(['ended_at' => $today->toDateString()]);

        ShtabEvent::record('assignment_ended', [
            'person_id' => $assignment->person_id,
            'object_id' => $assignment->object_id,
            'payload' => [
                'role_label' => $assignment->role_label,
                'days' => (int) $assignment->started_at->diffInDays($today),
            ],
            'comment' => $comment,
        ]);
    }

    private function person(int $id): ShtabPerson
    {
        $person = ShtabPerson::query()->active()->find($id);

        if (! $person instanceof ShtabPerson) {
            throw new RuntimeException("Персонаж #{$id} не найден или в архиве.");
        }

        return $person;
    }

    private function object(int $id): ShtabObject
    {
        $object = ShtabObject::query()->active()->find($id);

        if (! $object instanceof ShtabObject) {
            throw new RuntimeException("Территория #{$id} не найдена или в архиве.");
        }

        return $object;
    }

    private function assignment(int $id): ShtabAssignment
    {
        $assignment = ShtabAssignment::query()->find($id);

        if (! $assignment instanceof ShtabAssignment) {
            throw new RuntimeException("Назначение #{$id} не найдено.");
        }

        return $assignment;
    }

    private function metric(int $id): ShtabMetric
    {
        $metric = ShtabMetric::query()->find($id);

        if (! $metric instanceof ShtabMetric) {
            throw new RuntimeException("Метрика #{$id} не найдена.");
        }

        return $metric;
    }

    private function task(int $id): ShtabTask
    {
        $task = ShtabTask::query()->find($id);

        if (! $task instanceof ShtabTask) {
            throw new RuntimeException("Задача #{$id} не найдена.");
        }

        return $task;
    }

    /**
     * Комментарий события Хроники: префикс «ИИ-разбор: » + comment модели или summary.
     *
     * @param  array<string, mixed>  $operation
     */
    private function comment(array $operation): ?string
    {
        foreach (['comment', 'summary'] as $key) {
            $value = $operation[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return mb_substr('ИИ-разбор: '.trim($value), 0, 1000);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function intField(array $operation, string $key, string $label): int
    {
        $value = $operation[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException('В операции не хватает поля: '.$label.'.');
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function stringField(array $operation, string $key, string $label): string
    {
        $value = $operation[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('В операции не хватает поля: '.$label.'.');
        }

        return trim($value);
    }
}
