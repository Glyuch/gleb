<?php

namespace App\Http\Controllers\Shtab;

use App\Http\Controllers\Controller;
use App\Models\ShtabEvent;
use App\Models\ShtabObject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
}
