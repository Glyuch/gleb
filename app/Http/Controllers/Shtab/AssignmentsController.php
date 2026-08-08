<?php

namespace App\Http\Controllers\Shtab;

use App\Http\Controllers\Controller;
use App\Models\ShtabAssignment;
use App\Models\ShtabEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'person_id' => ['required', 'integer', 'exists:shtab_people,id'],
            'object_id' => ['required', 'integer', 'exists:shtab_objects,id'],
            'role_label' => ['required', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $duplicate = ShtabAssignment::query()->active()
            ->where('person_id', $data['person_id'])
            ->where('object_id', $data['object_id'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'person_id' => 'Этот человек уже назначен на эту территорию.',
            ]);
        }

        DB::transaction(function () use ($data): void {
            ShtabAssignment::query()->create([
                ...$data,
                'started_at' => now()->toDateString(),
            ]);

            ShtabEvent::record('assignment_started', [
                'person_id' => $data['person_id'],
                'object_id' => $data['object_id'],
                'payload' => ['role_label' => $data['role_label']],
                'comment' => $data['comment'] ?? null,
            ]);
        });

        return redirect()->back();
    }

    public function end(Request $request, ShtabAssignment $assignment): RedirectResponse
    {
        $data = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->endAssignment($assignment, $data['comment'] ?? null);

        return redirect()->back();
    }

    public function move(Request $request, ShtabAssignment $assignment): RedirectResponse
    {
        $data = $request->validate([
            'object_id' => ['required', 'integer', 'exists:shtab_objects,id'],
            'role_label' => ['required', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($assignment, $data): void {
            $this->endAssignment($assignment, $data['comment'] ?? null);

            ShtabAssignment::query()->create([
                'person_id' => $assignment->person_id,
                'object_id' => $data['object_id'],
                'role_label' => $data['role_label'],
                'comment' => $data['comment'] ?? null,
                'started_at' => now()->toDateString(),
            ]);

            ShtabEvent::record('assignment_started', [
                'person_id' => $assignment->person_id,
                'object_id' => $data['object_id'],
                'payload' => ['role_label' => $data['role_label']],
                'comment' => $data['comment'] ?? null,
            ]);
        });

        return redirect()->back();
    }

    private function endAssignment(ShtabAssignment $assignment, ?string $comment): void
    {
        if ($assignment->ended_at !== null) {
            throw ValidationException::withMessages([
                'assignment' => 'Назначение уже завершено.',
            ]);
        }

        DB::transaction(function () use ($assignment, $comment): void {
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
        });
    }
}
