<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GameReturnsController extends Controller
{
    /** Instruments edited in the per-quarter returns matrix. */
    private const INSTR = ['bank', 'cash', 'bond', 'stock', 'mix'];

    /**
     * Structured editor for per-quarter asset returns (ret.*) plus rate/infl.
     */
    public function edit(): View
    {
        $data = GameContent::current()?->data ?? [];

        return view('admin.game.returns', [
            'years' => $data['years'] ?? [],
            'instr' => self::INSTR,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $content = GameContent::current();
        if ($content === null) {
            return back()->withErrors(['years' => 'Нет активного контента.']);
        }

        $data = $content->data;
        $count = count($data['years'] ?? []);

        $rules = ['years' => ['required', 'array', 'size:'.$count]];
        foreach (self::INSTR as $i) {
            $rules["years.*.{$i}"] = ['required', 'numeric', 'between:-100,1000'];
        }
        $rules['years.*.rate'] = ['required', 'numeric', 'between:0,1000'];
        $rules['years.*.infl'] = ['required', 'numeric', 'between:-100,1000'];
        $request->validate($rules);

        // Laravel's wildcard rules don't fire for a quarter index missing entirely from the POST,
        // so fail loudly if any quarter is absent rather than silently keeping stale returns.
        $input = $request->input('years');
        for ($idx = 0; $idx < $count; $idx++) {
            if (! isset($input[$idx]) || ! is_array($input[$idx])) {
                return back()->withInput()->withErrors(['years' => 'Переданы не все кварталы — изменения не сохранены.']);
            }
        }

        // Overwrite only ret.* / rate / infl; keep every other quarter field (ev, ctx, note, …).
        foreach ($data['years'] as $idx => $year) {
            $row = $input[$idx];
            foreach (self::INSTR as $i) {
                $data['years'][$idx]['ret'][$i] = round(((float) $row[$i]) / 100, 6);
            }
            $data['years'][$idx]['rate'] = (int) round((float) $row['rate']);
            $data['years'][$idx]['infl'] = (int) round((float) $row['infl']);
        }

        GameContent::publish($data);

        return back()->with('status', 'Доходности по ходам сохранены — опубликована новая версия.');
    }
}
