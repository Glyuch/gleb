<?php

namespace App\Http\Controllers\Shtab;

use App\Http\Controllers\Controller;
use App\Models\ShtabEvent;
use App\Models\ShtabObject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ObjectsController extends Controller
{
    public function focus(Request $request, ShtabObject $object): RedirectResponse
    {
        $data = $request->validate([
            'focus_level' => ['required', 'integer', Rule::in([0, 1, 2])],
        ]);

        DB::transaction(function () use ($object, $data): void {
            $from = $object->focus_level;
            $object->update(['focus_level' => $data['focus_level']]);

            ShtabEvent::record('focus_level_changed', [
                'object_id' => $object->id,
                'payload' => ['from' => $from, 'to' => $data['focus_level']],
            ]);
        });

        return redirect()->back();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data): void {
            $object = ShtabObject::query()->create($data);
            ShtabEvent::record('object_created', ['object_id' => $object->id]);
        });

        return redirect()->back();
    }

    public function update(Request $request, ShtabObject $object): RedirectResponse
    {
        $object->update($this->validated($request));

        return redirect()->back();
    }

    public function archive(ShtabObject $object): RedirectResponse
    {
        if ($object->activeAssignments()->exists()) {
            throw ValidationException::withMessages([
                'object' => 'Сначала сними людей с этой территории.',
            ]);
        }

        DB::transaction(function () use ($object): void {
            $object->update(['archived_at' => now()]);
            ShtabEvent::record('object_archived', ['object_id' => $object->id]);
        });

        return redirect()->back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(['product', 'project', 'enabler'])],
            'parent_id' => ['nullable', 'integer', 'exists:shtab_objects,id'],
            'name' => ['required', 'string', 'max:100'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'focus_level' => ['required', 'integer', Rule::in([0, 1, 2])],
            'color' => ['required', 'string', 'max:7'],
        ]);
    }
}
