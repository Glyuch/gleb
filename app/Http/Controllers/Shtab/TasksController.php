<?php

namespace App\Http\Controllers\Shtab;

use App\Http\Controllers\Controller;
use App\Models\ShtabEvent;
use App\Models\ShtabTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TasksController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'object_id' => ['required', 'integer', Rule::exists('shtab_objects', 'id')->whereNull('archived_at')],
            'title' => ['required', 'string', 'max:500'],
            'assignee_person_id' => ['nullable', 'integer', Rule::exists('shtab_people', 'id')->whereNull('archived_at')],
            'is_key' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($data): void {
            if ($data['is_key'] ?? false) {
                $this->unsetOtherKeys((int) $data['object_id']);
            }

            $task = ShtabTask::query()->create($data);

            if ($task->assignee_person_id !== null) {
                ShtabEvent::record('task_assigned', [
                    'person_id' => $task->assignee_person_id,
                    'object_id' => $task->object_id,
                    'payload' => ['title' => $task->title],
                ]);
            }
        });

        return redirect()->back();
    }

    public function update(Request $request, ShtabTask $task): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'is_done' => ['sometimes', 'boolean'],
            'assignee_person_id' => ['sometimes', 'nullable', 'integer', Rule::exists('shtab_people', 'id')->whereNull('archived_at')],
            'is_key' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($task, $data): void {
            if (array_key_exists('title', $data)) {
                $task->title = $data['title'];
            }

            if (array_key_exists('is_done', $data)) {
                $this->applyDoneChange($task, (bool) $data['is_done']);
            }

            if (array_key_exists('assignee_person_id', $data)) {
                $this->applyAssigneeChange($task, $data['assignee_person_id'] === null ? null : (int) $data['assignee_person_id']);
            }

            if (array_key_exists('is_key', $data)) {
                if ((bool) $data['is_key'] && ! $task->is_key) {
                    $this->unsetOtherKeys($task->object_id, $task->id);
                }

                $task->is_key = (bool) $data['is_key'];
            }

            $task->save();
        });

        return redirect()->back();
    }

    public function destroy(ShtabTask $task): RedirectResponse
    {
        $task->delete();

        return redirect()->back();
    }

    private function applyDoneChange(ShtabTask $task, bool $isDone): void
    {
        if ($isDone === $task->is_done) {
            return;
        }

        $task->is_done = $isDone;
        $task->done_at = $isDone ? now() : null;

        if ($isDone) {
            ShtabEvent::record('task_done', [
                'object_id' => $task->object_id,
                'person_id' => $task->assignee_person_id,
                'payload' => ['title' => $task->title],
            ]);
        }
    }

    private function applyAssigneeChange(ShtabTask $task, ?int $assigneeId): void
    {
        if ($assigneeId !== null && $assigneeId !== $task->assignee_person_id) {
            ShtabEvent::record('task_assigned', [
                'person_id' => $assigneeId,
                'object_id' => $task->object_id,
                'payload' => ['title' => $task->title],
            ]);
        }

        $task->assignee_person_id = $assigneeId;
    }

    private function unsetOtherKeys(int $objectId, ?int $exceptId = null): void
    {
        ShtabTask::query()
            ->where('object_id', $objectId)
            ->where('is_key', true)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_key' => false]);
    }
}
