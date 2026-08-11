<?php

namespace App\Http\Controllers\Shtab;

use App\Http\Controllers\Controller;
use App\Models\ShtabAssignment;
use App\Models\ShtabEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssignmentsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'person_id' => ['required', 'integer', Rule::exists('shtab_people', 'id')->whereNull('archived_at')],
            'object_id' => ['required', 'integer', Rule::exists('shtab_objects', 'id')->whereNull('archived_at')],
            'role_label' => ['nullable', 'string', 'max:100'],
            'role_type' => ['required', Rule::in(ShtabAssignment::roleTypes())],
            'load_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $data = $this->withRoleDefaults($data);

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
                'payload' => [
                    'role_label' => $data['role_label'],
                    'role_type' => $data['role_type'],
                    'load_percent' => $data['load_percent'],
                ],
                'comment' => $data['comment'] ?? null,
            ]);
        });

        return redirect()->back();
    }

    /**
     * Меняет роль и вовлечённость, не снимая человека с территории.
     */
    public function update(Request $request, ShtabAssignment $assignment): RedirectResponse
    {
        if ($assignment->ended_at !== null) {
            throw ValidationException::withMessages([
                'assignment' => 'Назначение уже завершено.',
            ]);
        }

        $data = $request->validate([
            'role_label' => ['nullable', 'string', 'max:100'],
            'role_type' => ['required', Rule::in(ShtabAssignment::roleTypes())],
            'load_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $data = $this->withRoleDefaults($data);
        $before = ['role_type' => $assignment->role_type, 'load_percent' => $assignment->load_percent];

        if ($before['role_type'] === $data['role_type'] && $before['load_percent'] === $data['load_percent'] && $assignment->role_label === $data['role_label']) {
            return redirect()->back();
        }

        DB::transaction(function () use ($assignment, $data, $before): void {
            $assignment->update([
                'role_label' => $data['role_label'],
                'role_type' => $data['role_type'],
                'load_percent' => $data['load_percent'],
            ]);

            ShtabEvent::record('assignment_role_changed', [
                'person_id' => $assignment->person_id,
                'object_id' => $assignment->object_id,
                'payload' => [
                    'from' => $before,
                    'to' => ['role_type' => $data['role_type'], 'load_percent' => $data['load_percent']],
                    'role_label' => $data['role_label'],
                ],
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
            'object_id' => ['required', 'integer', Rule::exists('shtab_objects', 'id')->whereNull('archived_at')],
            'role_label' => ['nullable', 'string', 'max:100'],
            'role_type' => ['required', Rule::in(ShtabAssignment::roleTypes())],
            'load_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $data = $this->withRoleDefaults($data);

        $duplicate = ShtabAssignment::query()->active()
            ->where('person_id', $assignment->person_id)
            ->where('object_id', $data['object_id'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'object_id' => 'Этот человек уже назначен на эту территорию.',
            ]);
        }

        DB::transaction(function () use ($assignment, $data): void {
            $this->endAssignment($assignment, $data['comment'] ?? null);

            ShtabAssignment::query()->create([
                'person_id' => $assignment->person_id,
                'object_id' => $data['object_id'],
                'role_label' => $data['role_label'],
                'role_type' => $data['role_type'],
                'load_percent' => $data['load_percent'],
                'comment' => $data['comment'] ?? null,
                'started_at' => now()->toDateString(),
            ]);

            ShtabEvent::record('assignment_started', [
                'person_id' => $assignment->person_id,
                'object_id' => $data['object_id'],
                'payload' => [
                    'role_label' => $data['role_label'],
                    'role_type' => $data['role_type'],
                    'load_percent' => $data['load_percent'],
                ],
                'comment' => $data['comment'] ?? null,
            ]);
        });

        return redirect()->back();
    }

    /**
     * Роль-подпись и вовлечённость необязательны: без них берём значения по умолчанию
     * для выбранного типа участия из config('shtab.roles').
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withRoleDefaults(array $data): array
    {
        $roleType = (string) $data['role_type'];
        $label = isset($data['role_label']) ? trim((string) $data['role_label']) : '';

        $data['role_label'] = $label !== '' ? $label : ShtabAssignment::roleLabelFor($roleType);
        $data['load_percent'] = isset($data['load_percent'])
            ? (int) $data['load_percent']
            : ShtabAssignment::defaultLoad($roleType);

        return $data;
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
