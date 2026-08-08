<?php

namespace App\Http\Controllers\Shtab;

use App\Http\Controllers\Controller;
use App\Models\ShtabEvent;
use App\Models\ShtabPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PeopleController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data): void {
            $person = ShtabPerson::query()->create($data);
            ShtabEvent::record('person_created', ['person_id' => $person->id]);
        });

        return redirect()->back();
    }

    public function update(Request $request, ShtabPerson $person): RedirectResponse
    {
        $person->update($this->validated($request));

        return redirect()->back();
    }

    public function archive(ShtabPerson $person): RedirectResponse
    {
        if ($person->activeAssignments()->exists()) {
            throw ValidationException::withMessages([
                'person' => 'Сначала сними человека со всех территорий.',
            ]);
        }

        DB::transaction(function () use ($person): void {
            $person->update(['archived_at' => now()]);
            ShtabEvent::record('person_archived', ['person_id' => $person->id]);
        });

        return redirect()->back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'initials' => ['required', 'string', 'max:8'],
            'class' => ['required', 'string', 'max:50'],
            'color' => ['required', 'string', 'max:7'],
            'is_direct' => ['boolean'],
            'manager_id' => ['nullable', 'integer', 'exists:shtab_people,id'],
            'is_me' => ['boolean'],
        ]);
    }
}
