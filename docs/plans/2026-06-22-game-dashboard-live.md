# Live Game Results Dashboard + Admin Dashboards — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the static `gamedemoresults` report into a live admin dashboard computed from the DB, and reorganise the admin behind `/admin/dashboards/*` (game + site), all gated by the existing admin login.

**Architecture:** Approach A — server-rendered Blade + Chart.js (CDN). A `Scenario` value object (extracted from `GameController`) feeds two invokable actions (`BuildGameResultsReport`, `BuildSiteReport`) that produce plain arrays; thin admin controllers render Blade views. The game dashboard reuses the existing demo HTML almost verbatim, swapping its hardcoded `const D` for `@json($report)`.

**Tech Stack:** Laravel 13 / PHP 8.4, Pest v4, Blade, Chart.js 4.4.1 + chartjs-plugin-annotation (CDN), MySQL (prod) / SQLite :memory: (tests).

---

## Working environment (READ FIRST)

**All files live ONLY on the remote server.** There is no local checkout. For every file:

1. Write the content locally to `/tmp/gleb-build/<same relative path>` (use the Write tool).
2. `scp` it to the server: `scp -q /tmp/gleb-build/<path> gleb@gleb.finance:/home/gleb/gleb.finance/<path>` (create parent dirs first via `ssh ... 'mkdir -p ...'`).
3. Run all `php artisan`, `vendor/bin/pint`, `git`, and test commands over SSH: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && <cmd>'`.

SSH: `ssh -l gleb gleb.finance`. Project root: `/home/gleb/gleb.finance`. Branch: `master` only (never branch, never worktree). Commit each task. Run `vendor/bin/pint --dirty --format agent` before each commit that touches PHP.

**Recorded test baseline (pre-existing, before any change):** `php artisan test --compact --filter=Game` → **5 passed, 12 failed**. All 12 failures are `419` (CSRF) on POST routes — a pre-existing environment quirk, NOT caused by this work. The guard for the refactor (Task 1) is **"no new failures"** (still 5 passed / 12 failed), plus the new deterministic `ScenarioTest` passing. New tests in this plan are GET-only or non-HTTP and are unaffected by the 419 issue.

---

## File structure

**New:**
- `app/Support/Game/Scenario.php` — read-only view over active content: returns, rate, inflation, narrative, benchmarks, per-quarter optimum/best.
- `app/Actions/Game/BuildGameResultsReport.php` — produces the full `D` array (demo shape) + `leaderboard` + `generated_at`.
- `app/Actions/Admin/BuildSiteReport.php` — site-wide metrics array.
- `app/Http/Controllers/Admin/GameResultsDashboardController.php` — renders the game dashboard.
- `app/Http/Controllers/Admin/SiteDashboardController.php` — renders the site dashboard.
- `resources/views/admin/layout.blade.php` — shared light admin chrome (editors).
- `resources/views/admin/_dashnav.blade.php` — dark slim nav partial for dashboard pages.
- `resources/views/admin/dashboards/gameresults.blade.php` — live game dashboard (transformed from the demo).
- `resources/views/admin/dashboards/site.blade.php` — site dashboard.
- `tests/Unit/Game/ScenarioTest.php`
- `tests/Feature/Admin/DashboardAccessTest.php`
- `tests/Feature/Admin/GameResultsReportTest.php`
- `tests/Feature/Admin/SiteReportTest.php`

**Modified:**
- `routes/web.php` — restructure the admin group under `/admin`.
- `app/Http/Controllers/GameController.php` — delegate `instruments`/`benchmarks`/`simulate` to `Scenario`.
- `resources/views/admin/game/content.blade.php`, `survey.blade.php`, `returns.blade.php` — `@extends('admin.layout')`.

**Deleted:**
- `app/Http/Controllers/Admin/GameStatsController.php`
- `resources/views/admin/game/stats.blade.php`
- `resources/views/admin/game/layout.blade.php`

---

## Task 1: `Scenario` value object + delegate `GameController`

**Files:**
- Create: `app/Support/Game/Scenario.php`
- Create: `tests/Unit/Game/ScenarioTest.php`
- Modify: `app/Http/Controllers/GameController.php` (private `instruments`, `benchmarks`, `simulate`)

- [ ] **Step 1: Write the failing unit test**

Write to `/tmp/gleb-build/tests/Unit/Game/ScenarioTest.php`, then scp to the server:

```php
<?php

use App\Support\Game\Scenario;

beforeEach(function () {
    config(['game.contribution' => 30000]);
});

function twoQuarterScenario(): Scenario
{
    // bank steady +10%/q; stock +50% then -20%.
    return new Scenario([
        'choices' => [
            ['k' => 'bank', 't' => 'Вклад'],
            ['k' => 'stock', 't' => 'Акции'],
        ],
        'years' => [
            ['ret' => ['bank' => 0.10, 'stock' => 0.50], 'rate' => 10, 'infl' => 2,
                'ev' => ['title' => 'Q1', 'type' => 'up', 'text' => 'a'], 'ctx' => 'c1'],
            ['ret' => ['bank' => 0.10, 'stock' => -0.20], 'rate' => 9, 'infl' => 1.5,
                'ev' => ['title' => 'Q2', 'type' => 'down', 'text' => 'b'], 'ctx' => 'c2'],
        ],
    ]);
}

it('exposes instruments, labels, counts and series', function () {
    $s = twoQuarterScenario();
    expect($s->instruments())->toBe(['bank', 'stock']);
    expect($s->labels())->toBe(['bank' => 'Вклад', 'stock' => 'Акции']);
    expect($s->quarterCount())->toBe(2);
    expect($s->rate())->toBe([10.0, 9.0]);
    expect($s->inflation())->toBe([2.0, 1.5]);
    expect($s->returns())->toBe(['bank' => [0.10, 0.10], 'stock' => [0.50, -0.20]]);
});

it('computes forward factors with q+1 timing', function () {
    $s = twoQuarterScenario();
    // q0 grows on q1 only; q1 is the last quarter (empty product = 1).
    expect(round($s->forwardFactor('bank', 0), 4))->toBe(1.10);
    expect(round($s->forwardFactor('stock', 0), 4))->toBe(0.80);
    expect($s->forwardFactor('bank', 1))->toBe(1.0);
});

it('computes deterministic benchmarks', function () {
    // c=30000. q0: bankFactor 1.10, best 1.10 (bank beats stock 0.80). q1: both 1.0.
    // bank = 30000*1.10 + 30000*1.0 = 63000; max = same = 63000.
    expect(twoQuarterScenario()->benchmarks())->toBe(['bank' => 63000, 'max' => 63000]);
});

it('computes per-quarter optimum (forward) and best-by-return, ties to bank', function () {
    $s = twoQuarterScenario();
    expect($s->optimumPerQuarter())->toBe([0 => 'bank', 1 => 'bank']); // q1 tie -> bank
    expect($s->bestByQuarterReturn())->toBe([0 => 'stock', 1 => 'bank']); // q0 stock 0.5 > 0.1
});

it('reads per-quarter narrative with fallback', function () {
    $n = twoQuarterScenario()->narrative();
    expect($n[0])->toBe(['title' => 'Q1', 'type' => 'up', 'text' => 'a']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan test --compact tests/Unit/Game/ScenarioTest.php'`
Expected: FAIL — `Class "App\Support\Game\Scenario" not found`.

- [ ] **Step 3: Create the `Scenario` class**

Write to `/tmp/gleb-build/app/Support/Game/Scenario.php`, scp to server (`mkdir -p app/Support/Game` first):

```php
<?php

namespace App\Support\Game;

use App\Models\GameContent;

/**
 * Read-only view over an active game-content scenario: per-quarter returns,
 * macro context, and the deterministic DCA benchmarks/optima derived from them.
 * Single source of truth for scenario math, shared by the game engine and reports.
 */
class Scenario
{
    /** Canonical instrument order, used only as a fallback. */
    private const CANONICAL = ['bank', 'cash', 'bond', 'stock', 'mix'];

    /**
     * @param  array<string, mixed>  $data  The `game_contents.data` JSON.
     */
    public function __construct(private array $data) {}

    public static function current(): self
    {
        return new self(GameContent::current()?->data ?? ['years' => [], 'choices' => []]);
    }

    /**
     * Instrument keys for this scenario (from content choices, fallback to the canonical five).
     *
     * @return array<int, string>
     */
    public function instruments(): array
    {
        $keys = collect($this->data['choices'] ?? [])
            ->pluck('k')->filter()->unique()->values()->all();

        return $keys ?: self::CANONICAL;
    }

    /**
     * Human labels keyed by instrument key.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return collect($this->data['choices'] ?? [])
            ->filter(fn ($c) => isset($c['k']))
            ->mapWithKeys(fn ($c) => [$c['k'] => $c['t'] ?? $c['k']])
            ->all();
    }

    public function quarterCount(): int
    {
        return count($this->data['years'] ?? []);
    }

    /**
     * Quarterly return fraction for $key in quarter index $q (0-based).
     */
    public function return(string $key, int $q): float
    {
        return (float) ($this->data['years'][$q]['ret'][$key] ?? 0.0);
    }

    /**
     * Per-instrument return fractions across all quarters: [key => [q0..qN-1]].
     *
     * @return array<string, array<int, float>>
     */
    public function returns(): array
    {
        $out = [];
        foreach ($this->instruments() as $i) {
            $series = [];
            for ($q = 0; $q < $this->quarterCount(); $q++) {
                $series[] = $this->return($i, $q);
            }
            $out[$i] = $series;
        }

        return $out;
    }

    /**
     * Central-bank rate per quarter.
     *
     * @return array<int, float>
     */
    public function rate(): array
    {
        return array_map(fn ($y) => (float) ($y['rate'] ?? 0), $this->data['years'] ?? []);
    }

    /**
     * Inflation per quarter.
     *
     * @return array<int, float>
     */
    public function inflation(): array
    {
        return array_map(fn ($y) => (float) ($y['infl'] ?? 0), $this->data['years'] ?? []);
    }

    /**
     * Per-quarter macro narrative (title/type/text) with safe fallbacks.
     *
     * @return array<int, array{title: string, type: string, text: string}>
     */
    public function narrative(): array
    {
        return array_map(function ($y) {
            $ev = $y['ev'] ?? [];

            return [
                'title' => $ev['title'] ?? '',
                'type' => $ev['type'] ?? 'neutral',
                'text' => $ev['text'] ?? ($y['ctx'] ?? ''),
            ];
        }, $this->data['years'] ?? []);
    }

    /**
     * Forward growth factor of a contribution made in quarter $q into $key:
     * Π_{t=q+1..N-1} (1 + ret[t][key]). Matches the game's q+1 timing.
     */
    public function forwardFactor(string $key, int $q): float
    {
        $f = 1.0;
        for ($t = $q + 1; $t < $this->quarterCount(); $t++) {
            $f *= 1 + $this->return($key, $t);
        }

        return $f;
    }

    /**
     * Deterministic DCA benchmarks in whole roubles.
     *
     * @return array{bank: int, max: int}
     */
    public function benchmarks(): array
    {
        $c = (int) config('game.contribution', 30000);
        $bank = 0.0;
        $max = 0.0;

        for ($q = 0; $q < $this->quarterCount(); $q++) {
            $bankFactor = $this->forwardFactor('bank', $q);
            $best = $bankFactor;
            foreach ($this->instruments() as $i) {
                $best = max($best, $this->forwardFactor($i, $q));
            }
            $bank += $c * $bankFactor;
            $max += $c * $best;
        }

        return ['bank' => (int) round($bank), 'max' => (int) round($max)];
    }

    /**
     * Instrument that maximises the final value of a contribution made that quarter
     * (perfect-foresight optimum). Ties resolve to 'bank'. [q => key].
     *
     * @return array<int, string>
     */
    public function optimumPerQuarter(): array
    {
        $out = [];
        for ($q = 0; $q < $this->quarterCount(); $q++) {
            $bestKey = 'bank';
            $bestFactor = $this->forwardFactor('bank', $q);
            foreach ($this->instruments() as $i) {
                $f = $this->forwardFactor($i, $q);
                if ($f > $bestFactor) {
                    $bestFactor = $f;
                    $bestKey = $i;
                }
            }
            $out[$q] = $bestKey;
        }

        return $out;
    }

    /**
     * Instrument with the highest realised return in that quarter. [q => key].
     *
     * @return array<int, string>
     */
    public function bestByQuarterReturn(): array
    {
        $instruments = $this->instruments();
        $out = [];
        for ($q = 0; $q < $this->quarterCount(); $q++) {
            $bestKey = $instruments[0] ?? 'bank';
            $bestRet = -INF;
            foreach ($instruments as $i) {
                $r = $this->return($i, $q);
                if ($r > $bestRet) {
                    $bestRet = $r;
                    $bestKey = $i;
                }
            }
            $out[$q] = $bestKey;
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan test --compact tests/Unit/Game/ScenarioTest.php'`
Expected: PASS (5 passed).

- [ ] **Step 5: Refactor `GameController` to delegate to `Scenario`**

Apply three surgical edits. Read the current file first (`ssh ... 'sed -n "150,290p" app/Http/Controllers/GameController.php'`). Add the import `use App\Support\Game\Scenario;` near the other `use` lines, then replace the three private methods so the bodies become:

```php
    /**
     * Instruments available this scenario (delegates to Scenario).
     *
     * @param  array<string, mixed>  $content
     * @return array<int, string>
     */
    private function instruments(array $content): array
    {
        return (new Scenario($content))->instruments();
    }

    /**
     * Canonical DCA simulation (matches the client engine and spec §3.2).
     *
     * @param  array<string, mixed>  $content
     * @param  array<int, string>  $pick  quarter (1-based) => instrument key
     * @param  array<int, string>  $instruments
     * @return array{0:float,1:array<string,array{amount:int,share:float}>}
     */
    private function simulate(array $content, array $pick, array $instruments): array
    {
        $c = (int) config('game.contribution', 30000);
        $scenario = new Scenario($content);
        $n = $scenario->quarterCount();
        $bal = array_fill_keys($instruments, 0.0);

        for ($q = 0; $q < $n; $q++) {
            $k = $pick[$q + 1];
            $bal[$k] += $c * $scenario->forwardFactor($k, $q);
        }

        $you = array_sum($bal);
        $composition = [];
        foreach ($instruments as $i) {
            $composition[$i] = [
                'amount' => (int) round($bal[$i]),
                'share' => $you > 0 ? round($bal[$i] / $you, 4) : 0.0,
            ];
        }

        return [$you, $composition];
    }

    /**
     * Deterministic DCA benchmarks (delegates to Scenario).
     *
     * @param  array<string, mixed>  $content
     * @return array{0:int,1:int} [bank, max]
     */
    private function benchmarks(array $content): array
    {
        $b = (new Scenario($content))->benchmarks();

        return [$b['bank'], $b['max']];
    }
```

Keep every call site unchanged (`$this->instruments(...)`, `$this->simulate(...)`, `[$bank, $max] = $this->benchmarks(...)`).

- [ ] **Step 6: Verify prod numbers are unchanged (manual, on server)**

Run:
```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan tinker --execute='\''
$s = App\Support\Game\Scenario::current();
$b = $s->benchmarks();
echo "bank=".$b["bank"]." max=".$b["max"]."\n";
$r = App\Models\GameResult::latest("id")->first();
echo "stored bank=".$r->score_bank." max=".$r->score_max."\n";
'\'''
```
Expected: `Scenario` bank/max equal the stored `score_bank`/`score_max` of existing results (the active scenario's deterministic benchmarks did not change).

- [ ] **Step 7: Confirm no new test failures**

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && vendor/bin/pint --dirty --format agent && php artisan test --compact --filter=Game'`
Expected: still **5 passed, 12 failed** (same pre-existing 419 failures — no NEW failures), plus `ScenarioTest` green when run on its own.

- [ ] **Step 8: Commit**

```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && git add app/Support/Game/Scenario.php tests/Unit/Game/ScenarioTest.php app/Http/Controllers/GameController.php && git commit -q -m "game: extract Scenario value object from GameController

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"'
```

---

## Task 2: `BuildGameResultsReport` action

**Files:**
- Create: `app/Actions/Game/BuildGameResultsReport.php`
- Create: `tests/Feature/Admin/GameResultsReportTest.php`

- [ ] **Step 1: Write the failing test**

Write to `/tmp/gleb-build/tests/Feature/Admin/GameResultsReportTest.php`, scp to server:

```php
<?php

use App\Actions\Game\BuildGameResultsReport;
use App\Models\GameContent;
use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

function seedScenario(): void
{
    GameContent::create(['is_active' => true, 'data' => [
        'choices' => [
            ['k' => 'bank', 't' => 'Вклад'], ['k' => 'cash', 't' => 'Деньги'],
            ['k' => 'bond', 't' => 'Облигации'], ['k' => 'stock', 't' => 'Акции'],
            ['k' => 'mix', 't' => 'Смешанный'],
        ],
        'years' => array_map(fn ($q) => [
            'ret' => ['bank' => 0.03, 'cash' => 0.04, 'bond' => 0.05, 'stock' => 0.02, 'mix' => 0.04],
            'rate' => 15, 'infl' => 2,
            'ev' => ['title' => "Q$q", 'type' => 'neutral', 'text' => "t$q"],
        ], range(1, 12)),
        'survey' => [
            ['id' => 'experience', 'question' => 'Опыт?', 'options' => ['Да, инвестировал(а)', 'Немного, пробовал(а)', 'Нет, никогда']],
            ['id' => 'helped', 'question' => 'Помогла?', 'options' => ['Да', 'Скорее да', 'Скорее нет', 'Нет']],
            ['id' => 'plan_invest', 'question' => 'Вложите?', 'options' => ['Да', 'Возможно', 'Нет']],
            ['id' => 'ready_funds', 'question' => 'Готовы?', 'options' => ['Да', 'Скорее да', 'Нет']],
            ['id' => 'priority', 'question' => 'Приоритет?', 'options' => ['Надёжность', 'Доходность', 'Ликвидность', 'Простота']],
        ],
    ]]);
}

function bondChoices(): array
{
    return array_map(fn ($q) => ['k' => 'bond', 'quarter' => $q], range(1, 12));
}

it('reports unique players by last attempt and totals', function () {
    seedScenario();
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();

    // u1 plays twice; only the last attempt counts toward N/survey.
    GameResult::create(['user_id' => $u1->id, 'score_you' => 400000, 'score_bank' => 443521, 'score_max' => 487970, 'ratio' => 82, 'choices' => bondChoices(), 'survey_answers' => ['helped' => 'Нет', 'experience' => 'Нет, никогда', 'plan_invest' => 'Нет', 'ready_funds' => 'Нет', 'priority' => 'Надёжность']]);
    GameResult::create(['user_id' => $u1->id, 'score_you' => 460000, 'score_bank' => 443521, 'score_max' => 487970, 'ratio' => 94, 'choices' => bondChoices(), 'survey_answers' => ['helped' => 'Да', 'experience' => 'Да, инвестировал(а)', 'plan_invest' => 'Да', 'ready_funds' => 'Да', 'priority' => 'Доходность']]);
    GameResult::create(['user_id' => $u2->id, 'score_you' => 450000, 'score_bank' => 443521, 'score_max' => 487970, 'ratio' => 92, 'choices' => bondChoices(), 'survey_answers' => ['helped' => 'Скорее да', 'experience' => 'Немного, пробовал(а)', 'plan_invest' => 'Возможно', 'ready_funds' => 'Скорее да', 'priority' => 'Надёжность']]);

    GameEvent::create(['user_id' => $u1->id, 'event' => 'open']);
    GameEvent::create(['user_id' => $u2->id, 'event' => 'open']);
    GameEvent::create(['user_id' => $u1->id, 'event' => 'open_fund']);

    $d = app(BuildGameResultsReport::class)();

    expect($d['N'])->toBe(2);              // two unique players
    expect($d['total_results'])->toBe(3);  // three games
    expect($d['registered'])->toBe(2);
    expect($d['funnel'][0])->toBe(['Открыли игру', 2]);
    expect($d['funnel'][4])->toBe(['Перешли на Финуслуги', 1]);
    expect($d['beat_bank'])->toBe(2);      // 460000 and 450000 both > 443521
});

it('aggregates survey by last attempt only', function () {
    seedScenario();
    $u = User::factory()->create();
    GameResult::create(['user_id' => $u->id, 'score_you' => 400000, 'score_bank' => 443521, 'score_max' => 487970, 'ratio' => 82, 'choices' => bondChoices(), 'survey_answers' => ['helped' => 'Нет']]);
    GameResult::create(['user_id' => $u->id, 'score_you' => 460000, 'score_bank' => 443521, 'score_max' => 487970, 'ratio' => 94, 'choices' => bondChoices(), 'survey_answers' => ['helped' => 'Да']]);

    $d = app(BuildGameResultsReport::class)();
    $helped = collect($d['survey_stats'])->firstWhere('id', 'helped');
    expect($helped['counts']['Да'])->toBe(1);
    expect($helped['counts']['Нет'])->toBe(0); // first attempt ignored
});

it('computes per-player fwdbest against the optimum', function () {
    seedScenario(); // bond has the highest return every quarter => optimum is bond throughout
    $u = User::factory()->create();
    GameResult::create(['user_id' => $u->id, 'score_you' => 460000, 'score_bank' => 443521, 'score_max' => 487970, 'ratio' => 94, 'choices' => bondChoices(), 'survey_answers' => ['priority' => 'Надёжность']]);

    $d = app(BuildGameResultsReport::class)();
    // Optimum is bond for Q1–Q11; Q12 has no future growth so all instruments tie -> 'bank'.
    // An all-bond player therefore matches 11 of 12 quarters.
    expect($d['players'][0]['fwdbest'])->toBe(11);
    expect($d['players'][0]['stock'])->toBe(0.0);
    expect($d['qcards'][8]['fwd'])->toBe('bond');
    expect($d['fwd_best'][11])->toBe('bank');
});

it('does not divide by zero with no data', function () {
    seedScenario();
    $d = app(BuildGameResultsReport::class)();
    expect($d['N'])->toBe(0);
    expect($d['leaderboard'])->toBe([]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan test --compact tests/Feature/Admin/GameResultsReportTest.php'`
Expected: FAIL — `Class "App\Actions\Game\BuildGameResultsReport" not found`.

- [ ] **Step 3: Create the action**

Write to `/tmp/gleb-build/app/Actions/Game/BuildGameResultsReport.php`, scp (`mkdir -p app/Actions/Game`):

```php
<?php

namespace App\Actions\Game;

use App\Models\GameContent;
use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;
use App\Support\Game\Scenario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the live analytics payload for the game-results dashboard.
 * Shape mirrors the static demo's `const D` object (so its Chart.js renders
 * unchanged) plus a `leaderboard` and `generated_at` extension.
 */
class BuildGameResultsReport
{
    /** @var array<string, string> */
    private const INSTR_COLOR = [
        'bank' => '#64748b', 'cash' => '#0ea5e9', 'bond' => '#22c55e',
        'stock' => '#ef4444', 'mix' => '#a855f7',
    ];

    private const SAFE = ['bank', 'cash'];

    private const FUND = ['bond', 'stock', 'mix'];

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $content = GameContent::current();
        $scenario = new Scenario($content?->data ?? ['years' => [], 'choices' => [], 'survey' => []]);
        $data = $content?->data ?? [];
        $instr = $scenario->instruments();
        $n = max($scenario->quarterCount(), 1);
        $optimum = $scenario->optimumPerQuarter();
        $bench = $scenario->benchmarks();

        $allResults = GameResult::query()->with('user:id,name,email')->get();
        $last = $allResults->groupBy('user_id')
            ->map(fn (Collection $g) => $g->sortByDesc('id')->first())
            ->values();
        $N = $last->count();

        $rows = $this->playerRows($last, $optimum, $bench['bank']);
        $scores = $rows->pluck('score')->all();
        $ratios = $rows->pluck('ratio')->all();
        $byq = $this->byQuarter($last, $instr, $n);
        [$replays, $improved, $worsened, $same] = $this->replays($allResults);
        $survey = $data['survey'] ?? [];

        $scoreMean = $scores ? (int) round(array_sum($scores) / count($scores)) : 0;
        $scoreMed = (int) round($this->median($scores));
        $ratioMean = $ratios ? round(array_sum($ratios) / count($ratios), 1) : 0;
        $ratioMed = round($this->median($ratios), 1);

        $surveyStats = $this->surveyStats($survey, $last);
        $helped = collect($surveyStats)->firstWhere('id', 'helped') ?? ['counts' => [], 'answered' => 0];
        $ready = collect($surveyStats)->firstWhere('id', 'ready_funds') ?? ['counts' => [], 'answered' => 0];

        return [
            'generated_at' => Carbon::now()->format('d.m.Y'),
            'N' => $N,
            'total_results' => $allResults->count(),
            'registered' => User::count(),
            'BANK' => $bench['bank'],
            'MAXB' => $bench['max'],
            'funnel' => $this->funnel($N),
            'beat_bank' => $rows->where('beat', true)->count(),
            'score_mean' => $scoreMean,
            'score_med' => $scoreMed,
            'ratio_mean' => $ratioMean,
            'ratio_med' => $ratioMed,
            ...$this->scoreHistogram($scores, $scoreMean, $scoreMed, $bench['bank']),
            ...$this->ratioHistogram($ratios, $ratioMean, $ratioMed),
            'instr' => $instr,
            'instr_label' => $scenario->labels(),
            'instr_color' => collect($instr)->mapWithKeys(fn ($k) => [$k => self::INSTR_COLOR[$k] ?? '#64748b'])->all(),
            'total_choice' => $this->totalChoice($last, $instr),
            'byq_pct' => $byq,
            'safe_q' => $this->sumShares($byq, self::SAFE, $n),
            'fund_q' => $this->sumShares($byq, self::FUND, $n),
            'quarters' => array_map(fn ($q) => 'Q'.$q, range(1, $n)),
            'ret_q' => $this->retPct($scenario),
            'rate_q' => $scenario->rate(),
            'infl_q' => $scenario->inflation(),
            'qcards' => $this->qcards($scenario, $byq, $optimum, $n),
            'stock_share_q' => $byq['stock'] ?? array_fill(0, $n, 0),
            'bond_share_q' => $byq['bond'] ?? array_fill(0, $n, 0),
            'players' => $rows->map(fn ($r) => collect($r)->except('beat', 'survey')->all())->values()->all(),
            'pat' => $this->patterns($rows),
            'cors' => $this->correlations($rows),
            'stock_buckets' => $this->stockBuckets($rows),
            'fwd_best' => array_values($optimum),
            'cur_best' => array_values($scenario->bestByQuarterReturn()),
            'replays' => $replays,
            'improved' => $improved,
            'worsened' => $worsened,
            'same' => $same,
            'survey_stats' => $surveyStats,
            'helped_pos' => $this->positive($helped['counts'], ['Да', 'Скорее да']),
            'ready_pos' => $this->positive($ready['counts'], ['Да', 'Скорее да']),
            'helped_ans' => $helped['answered'],
            'ready_ans' => $ready['answered'],
            'exp_helped' => $this->crossGroup($rows, fn ($r) => $this->expGroup($r), 'helped', ['Да', 'Скорее да']),
            'exp_ready' => $this->crossGroup($rows, fn ($r) => $this->expGroup($r), 'ready_funds', ['Да', 'Скорее да']),
            'plan_pos' => $this->crossGroup($rows, fn ($r) => $r['beat'] ? 'Обыграли вклад' : 'Не обыграли', 'plan_invest', ['Да', 'Возможно']),
            'readyR_pos' => $this->crossGroup($rows, fn ($r) => $r['beat'] ? 'Обыграли вклад' : 'Не обыграли', 'ready_funds', ['Да', 'Скорее да']),
            'prio_rows' => $this->priorityRows($rows),
            'leaderboard' => $this->leaderboard($allResults, $bench['bank']),
        ];
    }

    private function uniqueUsers(string $event): int
    {
        return GameEvent::query()->where('event', $event)->distinct()->count('user_id');
    }

    /**
     * @return array<int, array{0:string,1:int}>
     */
    private function funnel(int $completed): array
    {
        return [
            ['Открыли игру', $this->uniqueUsers('open')],
            ['Начали играть', $this->uniqueUsers('start')],
            ['Дошли до финала', $this->uniqueUsers('finish')],
            ['Завершили + опрос', $completed],
            ['Перешли на Финуслуги', $this->uniqueUsers('open_fund')],
        ];
    }

    /**
     * One enriched row per unique player (last attempt).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function playerRows(Collection $last, array $optimum, int $bank): Collection
    {
        return $last->map(function ($r) use ($optimum, $bank) {
            $choices = collect($r->choices ?? [])->sortBy('quarter')->values();
            $keys = $choices->pluck('k')->filter()->values()->all();
            $count = max(count($keys), 1);
            $fund = count(array_filter($keys, fn ($k) => in_array($k, self::FUND, true)));
            $stock = count(array_filter($keys, fn ($k) => $k === 'stock'));
            $safe = count(array_filter($keys, fn ($k) => in_array($k, self::SAFE, true)));
            $switches = 0;
            for ($i = 1; $i < count($keys); $i++) {
                if ($keys[$i] !== $keys[$i - 1]) {
                    $switches++;
                }
            }
            $fwdbest = 0;
            foreach ($choices as $idx => $c) {
                $q = ((int) ($c['quarter'] ?? $idx + 1)) - 1;
                if (isset($optimum[$q]) && ($c['k'] ?? null) === $optimum[$q]) {
                    $fwdbest++;
                }
            }

            return [
                'uid' => (int) $r->user_id,
                'ratio' => (int) $r->ratio,
                'score' => (int) $r->score_you,
                'fund' => round($fund / $count, 4),
                'stock' => round($stock / $count, 4),
                'safe' => round($safe / $count, 4),
                'switches' => $switches,
                'distinct' => count(array_unique($keys)),
                'fwdbest' => $fwdbest,
                'beat' => (int) $r->score_you > $bank,
                'survey' => (array) ($r->survey_answers ?? []),
            ];
        });
    }

    /**
     * @return array<string, int>
     */
    private function totalChoice(Collection $last, array $instr): array
    {
        $counts = array_fill_keys($instr, 0);
        foreach ($last as $r) {
            foreach ($r->choices ?? [] as $c) {
                $k = $c['k'] ?? null;
                if ($k !== null && array_key_exists($k, $counts)) {
                    $counts[$k]++;
                }
            }
        }

        return $counts;
    }

    /**
     * Per-quarter percent share of each instrument among that quarter's choices.
     *
     * @return array<string, array<int, float>>
     */
    private function byQuarter(Collection $last, array $instr, int $n): array
    {
        $counts = [];
        $totals = array_fill(0, $n, 0);
        foreach ($last as $r) {
            foreach ($r->choices ?? [] as $idx => $c) {
                $q = ((int) ($c['quarter'] ?? $idx + 1)) - 1;
                $k = $c['k'] ?? null;
                if ($q < 0 || $q >= $n || $k === null) {
                    continue;
                }
                $counts[$q][$k] = ($counts[$q][$k] ?? 0) + 1;
                $totals[$q]++;
            }
        }
        $byq = [];
        foreach ($instr as $k) {
            $series = [];
            for ($q = 0; $q < $n; $q++) {
                $t = $totals[$q] ?: 1;
                $series[] = round(($counts[$q][$k] ?? 0) / $t * 100, 1);
            }
            $byq[$k] = $series;
        }

        return $byq;
    }

    /**
     * @param  array<string, array<int, float>>  $byq
     * @return array<int, float>
     */
    private function sumShares(array $byq, array $keys, int $n): array
    {
        $out = [];
        for ($q = 0; $q < $n; $q++) {
            $sum = 0.0;
            foreach ($keys as $k) {
                $sum += $byq[$k][$q] ?? 0;
            }
            $out[] = round($sum, 1);
        }

        return $out;
    }

    /**
     * @return array<string, array<int, float>>
     */
    private function retPct(Scenario $s): array
    {
        $out = [];
        foreach ($s->returns() as $k => $series) {
            $out[$k] = array_map(fn ($v) => round($v * 100, 1), $series);
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function qcards(Scenario $s, array $byq, array $optimum, int $n): array
    {
        $narr = $s->narrative();
        $rate = $s->rate();
        $infl = $s->inflation();
        $best = $s->bestByQuarterReturn();
        $cards = [];
        for ($q = 0; $q < $n; $q++) {
            $ret = [];
            $shares = [];
            $top = $s->instruments()[0] ?? 'bank';
            $topShare = -1;
            foreach ($s->instruments() as $k) {
                $ret[$k] = round($s->return($k, $q) * 100, 1);
                $share = (int) round($byq[$k][$q] ?? 0);
                $shares[$k] = $share;
                if ($share > $topShare) {
                    $topShare = $share;
                    $top = $k;
                }
            }
            $cards[] = [
                'q' => $q + 1,
                'title' => $narr[$q]['title'] ?? '',
                'type' => $narr[$q]['type'] ?? 'neutral',
                'text' => $narr[$q]['text'] ?? '',
                'rate' => $rate[$q] ?? 0,
                'infl' => $infl[$q] ?? 0,
                'ret' => $ret,
                'top' => $top,
                'fwd' => $optimum[$q] ?? 'bank',
                'cur' => $best[$q] ?? 'bank',
                'shares' => $shares,
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>
     */
    private function patterns(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['avg_switches' => 0, 'avg_distinct' => 0, 'always_bank' => 0, 'all_stock' => 0, 'avg_fund' => 0, 'avg_safe' => 0, 'avg_fwdbest' => 0];
        }

        return [
            'avg_switches' => round($rows->avg('switches'), 1),
            'avg_distinct' => round($rows->avg('distinct'), 1),
            'always_bank' => $rows->where('safe', 1.0)->count(),
            'all_stock' => $rows->where('stock', 1.0)->count(),
            'avg_fund' => round($rows->avg('fund') * 100, 1),
            'avg_safe' => round($rows->avg('safe') * 100, 1),
            'avg_fwdbest' => (int) round($rows->avg('fwdbest')),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function correlations(Collection $rows): array
    {
        $ratio = $rows->pluck('ratio')->all();

        return [
            'fund' => $this->corr($rows->pluck('fund')->all(), $ratio),
            'stock' => $this->corr($rows->pluck('stock')->all(), $ratio),
            'fwdbest' => $this->corr($rows->pluck('fwdbest')->all(), $ratio),
            'switches' => $this->corr($rows->pluck('switches')->all(), $ratio),
        ];
    }

    /**
     * @return array<int, array{0:string,1:int,2:float}>
     */
    private function stockBuckets(Collection $rows): array
    {
        $defs = [
            ['Без акций', fn ($r) => $r['stock'] == 0],
            ['До 25% акций', fn ($r) => $r['stock'] > 0 && $r['stock'] <= 0.25],
            ['Более 25% акций', fn ($r) => $r['stock'] > 0.25],
        ];
        $out = [];
        foreach ($defs as [$label, $pred]) {
            $g = $rows->filter($pred);
            $out[] = [$label, $g->count(), $g->isEmpty() ? 0 : round($g->avg('ratio'), 1)];
        }

        return $out;
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:int,2:int,3:int}
     */
    private function replays(Collection $allResults): array
    {
        $rows = [];
        $improved = $worsened = $same = 0;
        foreach ($allResults->groupBy('user_id') as $uid => $g) {
            if ($g->count() < 2) {
                continue;
            }
            $sorted = $g->sortBy('id')->values();
            $first = (int) $sorted->first()->score_you;
            $lastScore = (int) $sorted->last()->score_you;
            $rows[] = ['uid' => (int) $uid, 'n' => $g->count(), 'first' => $first, 'last' => $lastScore];
            $lastScore > $first ? $improved++ : ($lastScore < $first ? $worsened++ : $same++);
        }

        return [$rows, $improved, $worsened, $same];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function surveyStats(array $survey, Collection $last): array
    {
        $answers = $last->pluck('survey_answers');

        return collect($survey)->map(function ($q) use ($answers) {
            $counts = array_fill_keys($q['options'], 0);
            foreach ($answers as $a) {
                $v = $a[$q['id']] ?? null;
                if ($v !== null && array_key_exists($v, $counts)) {
                    $counts[$v]++;
                }
            }

            return ['id' => $q['id'], 'question' => $q['question'], 'options' => $q['options'], 'counts' => $counts, 'answered' => array_sum($counts)];
        })->all();
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function positive(array $counts, array $positive): int
    {
        $sum = 0;
        foreach ($positive as $p) {
            $sum += $counts[$p] ?? 0;
        }

        return $sum;
    }

    private function expGroup(array $row): ?string
    {
        $e = $row['survey']['experience'] ?? null;
        if ($e === null) {
            return null;
        }

        return $e === 'Нет, никогда' ? 'Новички' : 'Опытные';
    }

    /**
     * Group rows by a label, returning [label => [positiveCount, total]].
     *
     * @return array<string, array{0:int,1:int}>
     */
    private function crossGroup(Collection $rows, callable $groupOf, string $answerKey, array $positive): array
    {
        $out = [];
        foreach ($rows as $r) {
            $g = $groupOf($r);
            if ($g === null) {
                continue;
            }
            $out[$g] ??= [0, 0];
            $out[$g][1]++;
            if (in_array($r['survey'][$answerKey] ?? null, $positive, true)) {
                $out[$g][0]++;
            }
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function priorityRows(Collection $rows): array
    {
        return collect($rows)
            ->filter(fn ($r) => ($r['survey']['priority'] ?? null) !== null)
            ->groupBy(fn ($r) => $r['survey']['priority'])
            ->map(fn ($g, $prio) => [
                'prio' => $prio,
                'n' => $g->count(),
                'stock' => round($g->avg('stock') * 100, 1),
                'fund' => round($g->avg('fund') * 100, 1),
            ])
            ->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function leaderboard(Collection $allResults, int $bank): array
    {
        return $allResults->groupBy('user_id')
            ->map(function (Collection $g) use ($bank) {
                $best = $g->sortByDesc('score_you')->first();

                return [
                    'name' => $best->user?->name,
                    'email' => $best->user?->email,
                    'best_score' => (int) $best->score_you,
                    'ratio' => (int) $best->ratio,
                    'beat_bank' => (int) $best->score_you > $bank,
                    'plays' => $g->count(),
                ];
            })
            ->sortByDesc('best_score')->values()
            ->map(fn ($row, $i) => ['rank' => $i + 1] + $row)
            ->all();
    }

    /**
     * @return array{score_labels:array<int,string>,score_hist:array<int,int>,score_lines:array<string,float>}
     */
    private function scoreHistogram(array $scores, int $mean, int $median, int $bank): array
    {
        if (! $scores) {
            return ['score_labels' => [], 'score_hist' => [], 'score_lines' => ['mean' => 0, 'med' => 0, 'bank' => 0]];
        }
        $min = min($scores);
        $max = max($scores);
        $bins = 8;
        $width = ($max - $min) / $bins ?: 1;
        $hist = array_fill(0, $bins, 0);
        foreach ($scores as $s) {
            $hist[(int) min($bins - 1, floor(($s - $min) / $width))]++;
        }
        $labels = [];
        for ($i = 0; $i < $bins; $i++) {
            $labels[] = round(($min + $i * $width) / 1000).'–'.round(($min + ($i + 1) * $width) / 1000).'k';
        }
        $pos = fn ($v) => round(max(0, min($bins, ($v - $min) / $width)), 3);

        return ['score_labels' => $labels, 'score_hist' => array_values($hist), 'score_lines' => ['mean' => $pos($mean), 'med' => $pos($median), 'bank' => $pos($bank)]];
    }

    /**
     * @return array{ratio_labels:array<int,string>,ratio_hist:array<int,int>,ratio_lines:array<string,float>}
     */
    private function ratioHistogram(array $ratios, float $mean, float $median): array
    {
        if (! $ratios) {
            return ['ratio_labels' => [], 'ratio_hist' => [], 'ratio_lines' => ['mean' => 0, 'med' => 0]];
        }
        $lo = (int) (floor(min($ratios) / 5) * 5);
        $hi = (int) (ceil(max($ratios) / 5) * 5);
        if ($hi <= $lo) {
            $hi = $lo + 5;
        }
        $bins = intdiv($hi - $lo, 5);
        $hist = array_fill(0, $bins, 0);
        foreach ($ratios as $r) {
            $hist[(int) min($bins - 1, floor(($r - $lo) / 5))]++;
        }
        $labels = [];
        for ($i = 0; $i < $bins; $i++) {
            $labels[] = ($lo + $i * 5).'–'.($lo + $i * 5 + 5);
        }
        $pos = fn ($v) => round(max(0, min($bins, ($v - $lo) / 5)), 3);

        return ['ratio_labels' => $labels, 'ratio_hist' => array_values($hist), 'ratio_lines' => ['mean' => $pos($mean), 'med' => $pos($median)]];
    }

    private function median(array $v): float
    {
        if (! $v) {
            return 0.0;
        }
        sort($v);
        $n = count($v);
        $m = intdiv($n, 2);

        return $n % 2 ? (float) $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
    }

    private function corr(array $xs, array $ys): float
    {
        $n = count($xs);
        if ($n < 2) {
            return 0.0;
        }
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $num = $dx = $dy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $a = $xs[$i] - $mx;
            $b = $ys[$i] - $my;
            $num += $a * $b;
            $dx += $a * $a;
            $dy += $b * $b;
        }
        if ($dx == 0.0 || $dy == 0.0) {
            return 0.0;
        }

        return round($num / sqrt($dx * $dy), 2);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan test --compact tests/Feature/Admin/GameResultsReportTest.php'`
Expected: PASS (4 passed).

- [ ] **Step 5: Format and commit**

```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && vendor/bin/pint --dirty --format agent && git add app/Actions/Game/BuildGameResultsReport.php tests/Feature/Admin/GameResultsReportTest.php && git commit -q -m "game: BuildGameResultsReport live analytics action

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"'
```

---

## Task 3: `BuildSiteReport` action

**Files:**
- Create: `app/Actions/Admin/BuildSiteReport.php`
- Create: `tests/Feature/Admin/SiteReportTest.php`

- [ ] **Step 1: Write the failing test**

Write to `/tmp/gleb-build/tests/Feature/Admin/SiteReportTest.php`, scp:

```php
<?php

use App\Actions\Admin\BuildSiteReport;
use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('counts users, verified, admins and the game project', function () {
    User::factory()->count(3)->create();          // verified non-admins (factory default)
    User::factory()->unverified()->create();       // unverified
    $admin = User::factory()->create();            // verified
    $admin->forceFill(['is_admin' => true])->save();

    GameResult::create(['user_id' => $admin->id, 'score_you' => 1, 'score_bank' => 1, 'score_max' => 1, 'ratio' => 1, 'choices' => [], 'survey_answers' => []]);
    GameEvent::create(['user_id' => $admin->id, 'event' => 'open']);

    $r = app(BuildSiteReport::class)();

    expect($r['users_total'])->toBe(5);
    expect($r['users_verified'])->toBe(4); // 3 + admin
    expect($r['users_admin'])->toBe(1);
    expect($r['reg_labels'])->toHaveCount(30);
    expect($r['reg_counts'])->toHaveCount(30);
    expect($r['projects'][0]['key'])->toBe('game');
    expect($r['projects'][0]['players'])->toBe(1);
    expect($r['projects'][0]['games'])->toBe(1);
    expect($r['projects'][0]['events'])->toBe(1);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan test --compact tests/Feature/Admin/SiteReportTest.php'`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the action**

Write to `/tmp/gleb-build/app/Actions/Admin/BuildSiteReport.php`, scp (`mkdir -p app/Actions/Admin`):

```php
<?php

namespace App\Actions\Admin;

use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Site-wide admin metrics: users, registrations over time, active sessions,
 * and a per-project breakdown (the game is project #1). Read-only.
 */
class BuildSiteReport
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $now = Carbon::now();
        $since = $now->copy()->subDays(29)->startOfDay();

        $byDay = User::query()
            ->where('created_at', '>=', $since)
            ->get(['created_at'])
            ->groupBy(fn ($u) => $u->created_at->format('Y-m-d'))
            ->map->count();

        $regLabels = [];
        $regCounts = [];
        for ($d = 0; $d < 30; $d++) {
            $day = $since->copy()->addDays($d);
            $regLabels[] = $day->format('d.m');
            $regCounts[] = (int) ($byDay[$day->format('Y-m-d')] ?? 0);
        }

        return [
            'users_total' => User::count(),
            'users_verified' => User::whereNotNull('email_verified_at')->count(),
            'users_admin' => User::where('is_admin', true)->count(),
            'reg_labels' => $regLabels,
            'reg_counts' => $regCounts,
            'reg_today' => User::where('created_at', '>=', $now->copy()->startOfDay())->count(),
            'reg_7d' => User::where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'reg_30d' => User::where('created_at', '>=', $now->copy()->subDays(30))->count(),
            'sessions_active' => $this->sessions($now->copy()->subMinutes(15)->timestamp),
            'sessions_24h' => $this->sessions($now->copy()->subDay()->timestamp),
            'projects' => [
                [
                    'key' => 'game',
                    'title' => 'ФондыКвест',
                    'href' => Route::has('admin.dashboards.gameresults') ? route('admin.dashboards.gameresults') : '#',
                    'players' => GameResult::query()->distinct()->count('user_id'),
                    'games' => GameResult::count(),
                    'events' => GameEvent::count(),
                ],
            ],
        ];
    }

    private function sessions(int $sinceTs): int
    {
        if (! DB::getSchemaBuilder()->hasTable('sessions')) {
            return 0;
        }

        return DB::table('sessions')->where('last_activity', '>=', $sinceTs)->count();
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan test --compact tests/Feature/Admin/SiteReportTest.php'`
Expected: PASS (1 passed).

- [ ] **Step 5: Format and commit**

```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && vendor/bin/pint --dirty --format agent && git add app/Actions/Admin/BuildSiteReport.php tests/Feature/Admin/SiteReportTest.php && git commit -q -m "admin: BuildSiteReport site-wide metrics action

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"'
```

---

## Task 4: Routes, controllers, shared layout, access tests

**Files:**
- Create: `app/Http/Controllers/Admin/GameResultsDashboardController.php`
- Create: `app/Http/Controllers/Admin/SiteDashboardController.php`
- Create: `resources/views/admin/layout.blade.php`
- Create: `resources/views/admin/_dashnav.blade.php`
- Create: `tests/Feature/Admin/DashboardAccessTest.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/game/{content,survey,returns}.blade.php`
- Delete: `app/Http/Controllers/Admin/GameStatsController.php`, `resources/views/admin/game/stats.blade.php`, `resources/views/admin/game/layout.blade.php`

- [ ] **Step 1: Write the failing access test**

Write to `/tmp/gleb-build/tests/Feature/Admin/DashboardAccessTest.php`, scp:

```php
<?php

use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    \App\Models\GameContent::create(['is_active' => true, 'data' => ['years' => [], 'choices' => [], 'survey' => []]]);
});

it('redirects guests to login', function () {
    $this->get('/admin/dashboards/gameresults')->assertRedirect('/login');
});

function admin(): User
{
    // is_admin is not mass-assignable; match the existing test convention (AdminGameContentTest).
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('forbids non-admins', function () {
    $this->actingAs(User::factory()->create()) // verified, non-admin by default
        ->get('/admin/dashboards/gameresults')->assertForbidden();
});

it('serves both dashboards to admins', function () {
    $this->actingAs(admin())->get('/admin/dashboards/gameresults')->assertOk();
    $this->actingAs(admin())->get('/admin/dashboards/site')->assertOk();
});

it('redirects the old stats path and /admin root', function () {
    $admin = admin();
    $this->actingAs($admin)->get('/admin')->assertRedirect('/admin/dashboards/gameresults');
    $this->actingAs($admin)->get('/admin/game/stats')->assertRedirect('/admin/dashboards/gameresults');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan test --compact tests/Feature/Admin/DashboardAccessTest.php'`
Expected: FAIL (routes/controllers/views absent).

- [ ] **Step 3: Create the two dashboard controllers**

Write `/tmp/gleb-build/app/Http/Controllers/Admin/GameResultsDashboardController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Game\BuildGameResultsReport;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class GameResultsDashboardController extends Controller
{
    public function index(BuildGameResultsReport $report): View
    {
        return view('admin.dashboards.gameresults', ['report' => $report()]);
    }
}
```

Write `/tmp/gleb-build/app/Http/Controllers/Admin/SiteDashboardController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildSiteReport;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class SiteDashboardController extends Controller
{
    public function index(BuildSiteReport $report): View
    {
        return view('admin.dashboards.site', ['report' => $report()]);
    }
}
```

- [ ] **Step 4: Rewrite `routes/web.php`**

Write `/tmp/gleb-build/routes/web.php` (full file), scp to overwrite:

```php
<?php

use App\Http\Controllers\Admin\GameContentController;
use App\Http\Controllers\Admin\GameResultsDashboardController;
use App\Http\Controllers\Admin\GameReturnsController;
use App\Http\Controllers\Admin\GameSurveyController;
use App\Http\Controllers\Admin\SiteDashboardController;
use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

// Game landing handles its own guest gate (branded RU register/login) inside the controller.
Route::get('/game', [GameController::class, 'show'])->name('game');
Route::get('/game/register', [GameController::class, 'showRegister'])->name('game.register');
Route::get('/game/login', [GameController::class, 'showLogin'])->name('game.login');

Route::middleware(['auth'])->group(function () {
    Route::post('/game/result', [GameController::class, 'store'])->name('game.result');
    Route::get('/game/leaderboard', [GameController::class, 'leaderboard'])->name('game.leaderboard');
    Route::post('/game/event', [GameController::class, 'event'])->name('game.event');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/dashboards/gameresults');

    Route::prefix('dashboards')->name('dashboards.')->group(function () {
        Route::get('/gameresults', [GameResultsDashboardController::class, 'index'])->name('gameresults');
        Route::get('/site', [SiteDashboardController::class, 'index'])->name('site');
    });

    Route::prefix('game')->name('game.')->group(function () {
        Route::get('/', [GameContentController::class, 'edit'])->name('content');
        Route::put('/', [GameContentController::class, 'update'])->name('content.update');
        Route::get('/survey', [GameSurveyController::class, 'edit'])->name('survey');
        Route::put('/survey', [GameSurveyController::class, 'update'])->name('survey.update');
        Route::get('/returns', [GameReturnsController::class, 'edit'])->name('returns');
        Route::put('/returns', [GameReturnsController::class, 'update'])->name('returns.update');
        Route::redirect('/stats', '/admin/dashboards/gameresults');
    });
});

require __DIR__.'/settings.php';
```

- [ ] **Step 5: Create the shared admin layout (deterministic transform)**

The new `admin/layout.blade.php` must keep ALL of the old layout's CSS (the editor views depend on classes like `.btn`, `.matrix`, `.q-block`, `.flash`), so build it by copying the old `admin/game/layout.blade.php` and rewriting only the title + nav. This runs **before** Step 10 deletes the old file. Write `/tmp/gleb-build/build_admin_layout.py`, scp it, run it on the server:

```python
import re, pathlib
src = pathlib.Path("resources/views/admin/game/layout.blade.php").read_text(encoding="utf-8")

# Rebrand the <title> (old: "Админка · ФондыКвест").
src = src.replace("Админка · ФондыКвест", "Админка · gleb.finance")

# Add a separator style just before </style>.
src = src.replace("</style>",
    ".tabs .sep{display:inline-block;width:1px;height:20px;background:#e2e2e8;margin:0 4px;vertical-align:middle}\n</style>",
    1)

# Replace the whole <h1>…</nav> block with the two-group nav.
new_block = '''<h1>gleb.finance · Админка</h1>
  <nav class="tabs">
    <a href="{{ route('admin.dashboards.gameresults') }}" class="{{ request()->routeIs('admin.dashboards.gameresults') ? 'active' : '' }}">Игра · результаты</a>
    <a href="{{ route('admin.dashboards.site') }}" class="{{ request()->routeIs('admin.dashboards.site') ? 'active' : '' }}">Сайт</a>
    <span class="sep"></span>
    <a href="{{ route('admin.game.content') }}" class="{{ request()->routeIs('admin.game.content') ? 'active' : '' }}">Контент</a>
    <a href="{{ route('admin.game.survey') }}" class="{{ request()->routeIs('admin.game.survey') ? 'active' : '' }}">Опрос</a>
    <a href="{{ route('admin.game.returns') }}" class="{{ request()->routeIs('admin.game.returns') ? 'active' : '' }}">Доходности</a>
    <a href="{{ url('/game') }}" class="right" target="_blank">Открыть игру →</a>
  </nav>'''
src = re.sub(r"<h1>.*?</nav>", new_block, src, count=1, flags=re.S)

pathlib.Path("resources/views/admin/layout.blade.php").write_text(src, encoding="utf-8")
print("admin layout written")
```

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && python3 build_admin_layout.py && rm build_admin_layout.py && grep -c "admin.dashboards.gameresults" resources/views/admin/layout.blade.php'`
Expected: prints `admin layout written` then `1`.

- [ ] **Step 6: Create the dark dashboard nav partial**

Write `/tmp/gleb-build/resources/views/admin/_dashnav.blade.php`:

```blade
<div style="max-width:1180px;margin:0 auto;padding:14px 20px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:13.5px">
  <a href="{{ route('admin.dashboards.gameresults') }}" style="color:{{ request()->routeIs('admin.dashboards.gameresults') ? '#22c55e' : '#9aa7c7' }};text-decoration:none;font-weight:700">Игра · результаты</a>
  <a href="{{ route('admin.dashboards.site') }}" style="color:{{ request()->routeIs('admin.dashboards.site') ? '#22c55e' : '#9aa7c7' }};text-decoration:none;font-weight:700">Сайт</a>
  <span style="width:1px;height:16px;background:#283150"></span>
  <a href="{{ route('admin.game.content') }}" style="color:#9aa7c7;text-decoration:none">Контент игры</a>
  <a href="{{ url('/game') }}" target="_blank" style="color:#0ea5e9;text-decoration:none;margin-left:auto">Открыть игру →</a>
</div>
```

- [ ] **Step 7: Point the editor views at the new layout**

For each of `resources/views/admin/game/content.blade.php`, `survey.blade.php`, `returns.blade.php`, replace `@extends('admin.game.layout')` with `@extends('admin.layout')`:

```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && sed -i "s/@extends(.admin.game.layout.)/@extends(\"admin.layout\")/" resources/views/admin/game/content.blade.php resources/views/admin/game/survey.blade.php resources/views/admin/game/returns.blade.php && grep -n "@extends" resources/views/admin/game/*.blade.php'
```
Expected: each prints `@extends("admin.layout")`.

- [ ] **Step 8: Add a minimal placeholder site view so access test can pass**

(The full site view lands in Task 6; create a minimal valid one now so routes resolve.) Write `/tmp/gleb-build/resources/views/admin/dashboards/site.blade.php`:

```blade
<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Сайт · дашборд</title></head>
<body style="background:#0b1020;color:#e8ecf6;font-family:-apple-system,sans-serif">
@include('admin._dashnav')
<div style="max-width:1180px;margin:0 auto;padding:20px">Сайт-дашборд (заполняется в Task 6). Пользователей: {{ $report['users_total'] }}.</div>
</body></html>
```

- [ ] **Step 9: Add a minimal placeholder game dashboard view**

(Full transform lands in Task 5.) Write `/tmp/gleb-build/resources/views/admin/dashboards/gameresults.blade.php`:

```blade
<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Игра · результаты</title></head>
<body style="background:#0b1020;color:#e8ecf6;font-family:-apple-system,sans-serif">
@include('admin._dashnav')
<div style="max-width:1180px;margin:0 auto;padding:20px">Игровой дашборд (заполняется в Task 5). Игроков: {{ $report['N'] }}.</div>
</body></html>
```

- [ ] **Step 10: Delete the retired files**

```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && git rm -q app/Http/Controllers/Admin/GameStatsController.php resources/views/admin/game/stats.blade.php resources/views/admin/game/layout.blade.php'
```

- [ ] **Step 11: scp the new files and run the access test**

scp all new files to their server paths (`mkdir -p resources/views/admin/dashboards`), then:
Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan route:clear && php artisan test --compact tests/Feature/Admin/DashboardAccessTest.php'`
Expected: PASS (4 passed). Also run `--filter="AdminGameContent|AdminGameSurvey"` to confirm the editors still render under the new layout (no new failures vs baseline).

- [ ] **Step 12: Format and commit**

```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && vendor/bin/pint --dirty --format agent && git add -A && git commit -q -m "admin: dashboards routes, shared layout, access control

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"'
```

---

## Task 5: Live game dashboard view (transform the demo)

**Files:**
- Modify (overwrite placeholder): `resources/views/admin/dashboards/gameresults.blade.php`

The view is the demo at `public/reports/gamedemoresults/index.html` with three changes: escape `@media` for Blade, swap `const D` for `@json($report)`, and append a leaderboard block.

- [ ] **Step 1: Build the view on the server with a deterministic transform**

Write the transform script `/tmp/gleb-build/build_gameresults_view.py` and scp it to the server, then run it there (it reads the demo and writes the Blade view):

```python
import re, pathlib
src = pathlib.Path("public/reports/gamedemoresults/index.html").read_text(encoding="utf-8")

# 1) Escape Blade-significant @media (CSS) so Blade emits a literal @media.
src = src.replace("@media", "@@media")

# 2) Replace the hardcoded data blob line `const D={...};` with the live payload.
src = re.sub(r"const D=\{.*?\};", "const D = @json($report);", src, count=1, flags=re.S)

# 3) Live header date: swap the hardcoded date for the server-provided one.
src = src.replace("Данные за 21.06.2026", "Данные на ${D.generated_at}")

# 4) Insert the leaderboard section right before the footer.
lb_html = (
    '<h2>Игроки</h2>\n'
    '<div class="note">Все игроки, по лучшему результату каждого. Виден только администратору.</div>\n'
    '<div class="card" style="overflow-x:auto"><div id="leaderboard"></div></div>\n\n'
)
src = src.replace('<footer id="foot"></footer>', lb_html + '<footer id="foot"></footer>', 1)

# 5) Render the leaderboard table from D.leaderboard, just before the closing </script>.
lb_js = r"""
// ---- leaderboard (admin-only) ----
(function(){
  const rows=D.leaderboard||[];
  if(!rows.length){document.getElementById('leaderboard').innerHTML='<div class="cap">Пока нет игроков.</div>';return;}
  let h='<table><tr><th>#</th><th>Игрок</th><th class="num">Лучший счёт</th><th class="num">Ratio</th><th>Вклад</th><th class="num">Игр</th></tr>';
  rows.forEach(r=>{h+=`<tr><td>${r.rank}</td><td>${r.name||'—'}<span style="display:block;color:#9aa7c7;font-size:11px">${r.email||''}</span></td>`+
    `<td class="num">${fmt(r.best_score)} ₽</td><td class="num">${r.ratio}%</td>`+
    `<td>${r.beat_bank?'<span class="pos">да</span>':'<span class="neg">нет</span>'}</td><td class="num">${r.plays}</td></tr>`;});
  document.getElementById('leaderboard').innerHTML=h+'</table>';
})();
"""
idx = src.rfind("</script>")
src = src[:idx] + lb_js + "\n" + src[idx:]

# 6) Prepend the dark admin nav partial inside <body>.
src = src.replace("<body>", "<body>\n@include('admin._dashnav')", 1)

pathlib.Path("resources/views/admin/dashboards/gameresults.blade.php").write_text(src, encoding="utf-8")
print("written", len(src), "bytes")
```

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && python3 build_gameresults_view.py && rm build_gameresults_view.py'`
Expected: prints `written <N> bytes`.

- [ ] **Step 2: Smoke-test the rendered page (no exceptions, data present)**

```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan tinker --execute='\''
$u = App\Models\User::where("is_admin",1)->first();
$html = app(App\Http\Controllers\Admin\GameResultsDashboardController::class)->index(app(App\Actions\Game\BuildGameResultsReport::class))->render();
echo "len=".strlen($html)."\n";
echo (str_contains($html,"const D = {") ? "HAS_JSON\n" : "NO_JSON\n");
echo (str_contains($html,"@@media") ? "BAD_MEDIA\n" : "OK_MEDIA\n");
echo (str_contains($html,"leaderboard") ? "HAS_LB\n" : "NO_LB\n");
'\'''
```
Expected: `len=` large, `HAS_JSON`, `OK_MEDIA` (the `@@` was consumed by Blade → literal `@media` in output, so `@@media` must NOT appear), `HAS_LB`. If `BAD_MEDIA` appears, the Blade `@@` escaping didn't compile — investigate before continuing.

- [ ] **Step 3: Re-run report + access tests (regression)**

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan test --compact tests/Feature/Admin/DashboardAccessTest.php tests/Feature/Admin/GameResultsReportTest.php'`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && git add resources/views/admin/dashboards/gameresults.blade.php && git commit -q -m "admin: live game-results dashboard view (Chart.js + leaderboard)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"'
```

---

## Task 6: Site dashboard view

**Files:**
- Modify (overwrite placeholder): `resources/views/admin/dashboards/site.blade.php`

- [ ] **Step 1: Write the full site view**

Write `/tmp/gleb-build/resources/views/admin/dashboards/site.blade.php`, scp to overwrite. Note: `@json` is used for chart data; there is one CSS `@media` rule which MUST be written as `@@media` so Blade emits a literal `@media`.

```blade
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>gleb.finance — дашборд сайта</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
  :root{--bg:#0b1020;--card:#141a2e;--ink:#e8ecf6;--muted:#9aa7c7;--line:#283150;--accent:#22c55e;--accent2:#0ea5e9}
  *{box-sizing:border-box}
  body{margin:0;background:linear-gradient(160deg,#0b1020,#0e1530);color:var(--ink);
    font:15px/1.55 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}
  .wrap{max-width:1180px;margin:0 auto;padding:20px 20px 90px}
  h1{margin:14px 0 2px;font-size:28px;letter-spacing:-.02em}
  .sub{color:var(--muted);font-size:14px;margin-bottom:20px}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin:16px 0}
  .kpi{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:15px 17px}
  .kpi .v{font-size:27px;font-weight:700}.kpi .v.g{color:var(--accent)}.kpi .v.b{color:var(--accent2)}
  .kpi .l{color:var(--muted);font-size:12.5px;margin-top:3px}
  h2{margin:34px 0 10px;font-size:20px;border-left:3px solid var(--accent);padding-left:11px}
  .grid{display:grid;gap:18px}.g2{grid-template-columns:1fr 1fr}
  @@media(max-width:820px){.g2{grid-template-columns:1fr}}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px}
  .card h3{margin:0 0 10px;font-size:15.5px}
  .chartbox{position:relative;height:300px}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  th,td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line)}
  th{color:var(--muted);font-weight:600}.num{text-align:right;font-variant-numeric:tabular-nums}
  a.proj{color:var(--accent2);text-decoration:none;font-weight:700}
</style>
</head>
<body>
@include('admin._dashnav')
<div class="wrap">
  <h1>gleb.finance — сайт</h1>
  <div class="sub">Сводка по сайту в реальном времени.</div>

  <div class="kpis">
    <div class="kpi"><div class="v g">{{ $report['users_total'] }}</div><div class="l">пользователей</div></div>
    <div class="kpi"><div class="v">{{ $report['users_verified'] }}</div><div class="l">подтвердили email</div></div>
    <div class="kpi"><div class="v">{{ $report['users_admin'] }}</div><div class="l">админов</div></div>
    <div class="kpi"><div class="v b">{{ $report['sessions_active'] }}</div><div class="l">активны (15 мин)</div></div>
    <div class="kpi"><div class="v">{{ $report['reg_7d'] }}</div><div class="l">регистраций за 7 дней</div></div>
    <div class="kpi"><div class="v">{{ $report['reg_30d'] }}</div><div class="l">за 30 дней</div></div>
  </div>

  <h2>Регистрации</h2>
  <div class="grid g2">
    <div class="card"><h3>Новые пользователи по дням (30 дней)</h3>
      <div class="chartbox"><canvas id="reg"></canvas></div></div>
    <div class="card"><h3>Подтверждение email</h3>
      <div class="chartbox"><canvas id="verified"></canvas></div></div>
  </div>

  <h2>Проекты</h2>
  <div class="card"><table>
    <tr><th>Проект</th><th class="num">Игроков</th><th class="num">Сессий/игр</th><th class="num">Событий</th></tr>
    @foreach ($report['projects'] as $p)
    <tr>
      <td><a class="proj" href="{{ $p['href'] }}">{{ $p['title'] }}</a></td>
      <td class="num">{{ $p['players'] }}</td>
      <td class="num">{{ $p['games'] }}</td>
      <td class="num">{{ $p['events'] }}</td>
    </tr>
    @endforeach
  </table></div>
</div>
<script>
const R = @json($report);
Chart.defaults.color='#9aa7c7';Chart.defaults.borderColor='#283150';
new Chart(document.getElementById('reg'),{type:'bar',
  data:{labels:R.reg_labels,datasets:[{data:R.reg_counts,backgroundColor:'#0ea5e9',borderRadius:5}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true,ticks:{precision:0},grid:{color:'#283150'}},x:{grid:{display:false}}}}});
new Chart(document.getElementById('verified'),{type:'doughnut',
  data:{labels:['Подтвердили','Не подтвердили'],
    datasets:[{data:[R.users_verified,Math.max(R.users_total-R.users_verified,0)],backgroundColor:['#22c55e','#283150'],borderWidth:0}]},
  options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'bottom'}}}});
</script>
</body>
</html>
```

- [ ] **Step 2: Smoke-test the site view renders**

```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan tinker --execute='\''
$html = app(App\Http\Controllers\Admin\SiteDashboardController::class)->index(app(App\Actions\Admin\BuildSiteReport::class))->render();
echo "len=".strlen($html)."\n";
echo (str_contains($html,"@@media") ? "BAD_MEDIA\n" : "OK_MEDIA\n");
echo (str_contains($html,"const R = {") ? "HAS_JSON\n" : "NO_JSON\n");
'\'''
```
Expected: `OK_MEDIA`, `HAS_JSON`.

- [ ] **Step 3: Run the access test again**

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan test --compact tests/Feature/Admin/DashboardAccessTest.php'`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && git add resources/views/admin/dashboards/site.blade.php && git commit -q -m "admin: site dashboard view (users, registrations, projects)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"'
```

---

## Task 7: Full verification

- [ ] **Step 1: Run the full new admin suite + Scenario unit + game regression**

Run:
```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan test --compact tests/Unit/Game/ScenarioTest.php tests/Feature/Admin && echo "---GAME REGRESSION---" && php artisan test --compact --filter=Game'
```
Expected: all `tests/Unit/Game` + `tests/Feature/Admin` green; the `--filter=Game` run shows **no new failures vs the recorded baseline** (the 12 pre-existing 419 POST failures may remain; there must be no additional failures and `ScenarioTest` passes).

- [ ] **Step 2: Confirm the live pages return 200 over HTTP as the admin**

Use the Boost `get-absolute-url` convention or curl with an authenticated session. Minimum check — assert the controllers render without exception for the real DB (already done in Tasks 5–6 smoke tests). Optionally verify routes exist:
```bash
ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && php artisan route:list --path=admin'
```
Expected: lists `admin.dashboards.gameresults`, `admin.dashboards.site`, the `admin.game.*` editors, and the two redirects.

- [ ] **Step 3: Final pint + status check (tree clean)**

Run: `ssh -l gleb gleb.finance 'cd /home/gleb/gleb.finance && vendor/bin/pint --dirty --format agent && git status --short && git log --oneline -7'`
Expected: working tree clean (only `public/reports/` may remain untracked — pre-existing, leave it), commits from Tasks 1–6 present.

- [ ] **Step 4: Manual confirmation by the user**

Ask the user to open `https://gleb.finance/admin/dashboards/gameresults` and `https://gleb.finance/admin/dashboards/site` (logged in as admin) and confirm the charts render with live data. No asset build is required (the admin pages use inline styles + Chart.js via CDN, not Vite).

---

## Notes / out of scope

- `npm run build` is **not** required: these Blade pages don't import through Vite.
- The static `public/reports/gamedemoresults/` is left untouched (anonymous aggregates).
- No report caching for now (data volume is tiny). If needed later, wrap the action calls in a short `Cache::remember` as a separate change.
- Pre-existing: the game POST feature tests return 419 (CSRF) in the test environment. Out of scope here; flagged separately to the user.
