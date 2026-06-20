<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GameSurveyController extends Controller
{
    public function edit(): View
    {
        return view('admin.game.survey', [
            'survey' => GameContent::current()?->data['survey'] ?? [],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question' => ['required', 'string'],
            'questions.*.options' => ['required', 'array', 'min:2'],
            'questions.*.options.*' => ['nullable', 'string'],
        ], [], [
            'questions.*.question' => 'текст вопроса',
        ]);

        $survey = [];
        foreach ($request->input('questions', []) as $q) {
            $options = collect($q['options'] ?? [])
                ->map(fn ($o) => trim((string) $o))
                ->filter()
                ->values()
                ->all();

            if (count($options) < 2) {
                return back()->withInput()->withErrors([
                    'questions' => 'У каждого вопроса должно быть минимум 2 непустых варианта ответа.',
                ]);
            }

            $survey[] = [
                'id' => ! empty($q['id']) ? Str::slug($q['id'], '_') : 'q_'.Str::lower(Str::random(6)),
                'question' => trim((string) $q['question']),
                'options' => $options,
            ];
        }

        $data = GameContent::current()->data;
        $data['survey'] = $survey;
        GameContent::publish($data);

        return back()->with('status', 'Опрос сохранён.');
    }
}
