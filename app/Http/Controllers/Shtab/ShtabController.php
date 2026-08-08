<?php

namespace App\Http\Controllers\Shtab;

use App\Actions\Shtab\BuildShtabBoard;
use App\Http\Controllers\Controller;
use App\Models\ShtabEvent;
use Inertia\Inertia;
use Inertia\Response;

class ShtabController extends Controller
{
    public function index(BuildShtabBoard $board): Response
    {
        return Inertia::render('shtab/index', [
            'board' => $board->handle(),
            'events' => ShtabEvent::query()
                ->with(['person:id,name,initials,color', 'object:id,name,emoji', 'metric:id,name'])
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn (ShtabEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'person' => $event->person?->only(['id', 'name', 'initials', 'color']),
                    'object' => $event->object?->only(['id', 'name', 'emoji']),
                    'metric' => $event->metric?->only(['id', 'name']),
                    'payload' => $event->payload,
                    'comment' => $event->comment,
                    'created_at' => $event->created_at->toIso8601String(),
                ]),
        ]);
    }
}
