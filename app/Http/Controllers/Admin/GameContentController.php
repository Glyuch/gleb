<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GameContentController extends Controller
{
    /**
     * Content tab edits the scenario + screen texts (everything except the survey,
     * which has its own dedicated tab).
     */
    public function edit(): View
    {
        $data = GameContent::current()?->data ?? [];
        unset($data['survey']);

        return view('admin.game.content', [
            'json' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate(['data' => ['required', 'string']]);

        $decoded = json_decode($request->input('data'), true);
        if (! is_array($decoded)) {
            return back()->withInput()->withErrors(['data' => 'Невалидный JSON: '.json_last_error_msg()]);
        }

        if ($problems = $this->structureProblems($decoded)) {
            return back()->withInput()->withErrors(['data' => implode("\n", $problems)]);
        }

        // Survey is managed on its own tab — preserve the current one.
        $active = GameContent::current();
        $decoded['survey'] = $active->data['survey'] ?? [];

        GameContent::publish($decoded);

        return back()->with('status', 'Контент сохранён.');
    }

    /**
     * Returns a list of human-readable structure problems (empty = valid).
     *
     * @param  array<string, mixed>  $d
     * @return array<int, string>
     */
    private function structureProblems(array $d): array
    {
        $problems = [];

        foreach (['start', 'intros', 'choices', 'years'] as $key) {
            if (! array_key_exists($key, $d)) {
                $problems[] = "Отсутствует ключ «{$key}».";
            }
        }
        if ($problems) {
            return $problems;
        }

        if (! is_array($d['years']) || count($d['years']) < 1) {
            $problems[] = 'Поле «years» должно быть непустым массивом кварталов.';
        } else {
            foreach ($d['years'] as $i => $y) {
                $n = $i + 1;
                foreach (['rate', 'infl', 'ctx', 'ev', 'ret', 'note'] as $f) {
                    if (! isset($y[$f])) {
                        $problems[] = "Квартал {$n}: отсутствует «{$f}».";
                    }
                }
                foreach (['bank', 'cash', 'bond', 'stock', 'mix'] as $inst) {
                    if (! isset($y['ret'][$inst]) || ! is_numeric($y['ret'][$inst])) {
                        $problems[] = "Квартал {$n}: ret.{$inst} должно быть числом.";
                    }
                }
            }
        }

        if (! is_array($d['choices']) || count($d['choices']) < 1) {
            $problems[] = 'Поле «choices» должно быть непустым массивом.';
        } else {
            foreach ($d['choices'] as $i => $c) {
                foreach (['k', 'ic', 't', 'risk', 'hintBase'] as $f) {
                    if (! isset($c[$f]) || $c[$f] === '') {
                        $problems[] = 'Вариант '.($i + 1).": отсутствует «{$f}».";
                    }
                }
            }
        }

        if (! is_array($d['intros']) || count($d['intros']) < 1) {
            $problems[] = 'Поле «intros» должно быть непустым массивом.';
        }

        return $problems;
    }
}
