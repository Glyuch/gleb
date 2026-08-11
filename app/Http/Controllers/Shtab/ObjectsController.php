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
    /**
     * Лёгкий API для быстрого переключения фокуса; текущий UI меняет focus_level через update().
     */
    public function focus(Request $request, ShtabObject $object): RedirectResponse
    {
        $data = $request->validate([
            'focus_level' => ['required', 'integer', Rule::in([0, 1, 2])],
        ]);

        if ($object->focus_level === (int) $data['focus_level']) {
            return redirect()->back();
        }

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

    /**
     * Ручной порядок территорий на Карте: клиент присылает id в том порядке,
     * в котором карточки лежат после перетаскивания.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:shtab_objects,id'],
        ]);

        DB::transaction(function () use ($data): void {
            foreach ($data['ids'] as $index => $id) {
                ShtabObject::query()->whereKey($id)->update(['sort' => $index + 1]);
            }
        });

        return redirect()->back();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data): void {
            $data['sort'] = (int) ShtabObject::query()->max('sort') + 1;
            $object = ShtabObject::query()->create($data);
            ShtabEvent::record('object_created', ['object_id' => $object->id]);
        });

        return redirect()->back();
    }

    public function update(Request $request, ShtabObject $object): RedirectResponse
    {
        $data = $this->validated($request, $object);
        $from = $object->focus_level;

        DB::transaction(function () use ($object, $data, $from): void {
            $object->update($data);

            if ((int) $data['focus_level'] !== $from) {
                ShtabEvent::record('focus_level_changed', [
                    'object_id' => $object->id,
                    'payload' => ['from' => $from, 'to' => (int) $data['focus_level']],
                ]);
            }
        });

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
    private function validated(Request $request, ?ShtabObject $object = null): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['product', 'project', 'enabler'])],
            'parent_id' => ['nullable', 'integer', 'exists:shtab_objects,id'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'focus_level' => ['required', 'integer', Rule::in([0, 1, 2])],
            'color' => ['required', 'string', 'max:7'],
        ]);

        // Продукт — всегда корень. Проект и энейблер могут висеть на продукте
        // или на энейблере (например, проекты внутри «Отчётности бизнес-линии»).
        if ($data['type'] === 'product') {
            $data['parent_id'] = null;

            return $data;
        }

        if ($data['parent_id'] !== null) {
            $parent = ShtabObject::query()->find($data['parent_id']);

            if (! $parent instanceof ShtabObject || ! in_array($parent->type, ['product', 'enabler'], true)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Родителем может быть только продукт или энейблер.',
                ]);
            }

            if ($object !== null && $this->wouldLoop($object, $parent)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Нельзя вложить территорию саму в себя.',
                ]);
            }
        }

        return $data;
    }

    private function wouldLoop(ShtabObject $object, ShtabObject $parent): bool
    {
        $cursor = $parent;

        for ($depth = 0; $cursor !== null && $depth < 10; $depth++) {
            if ($cursor->id === $object->id) {
                return true;
            }

            $cursor = $cursor->parent;
        }

        return false;
    }
}
