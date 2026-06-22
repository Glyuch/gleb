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
            'generated_full' => Carbon::now()->format('d.m.Y, H:i'),
            'daily' => $this->daily(30),
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
     * Per-day completed games (results) and started games (start events) for the last $days,
     * always including today (0 when there is no activity yet) so freshness is visible.
     *
     * @return array{labels: array<int,string>, completed: array<int,int>, started: array<int,int>, today_completed: int, today_started: int}
     */
    private function daily(int $days): array
    {
        $today = Carbon::today();
        $start = $today->copy()->subDays($days - 1);

        $completed = GameResult::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(fn ($r) => $r->created_at->format('Y-m-d'))
            ->map->count();

        $started = GameEvent::query()
            ->where('event', 'start')
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(fn ($e) => $e->created_at->format('Y-m-d'))
            ->map->count();

        $labels = [];
        $comp = [];
        $strt = [];
        for ($d = 0; $d < $days; $d++) {
            $day = $start->copy()->addDays($d);
            $key = $day->format('Y-m-d');
            $labels[] = $day->format('d.m');
            $comp[] = (int) ($completed[$key] ?? 0);
            $strt[] = (int) ($started[$key] ?? 0);
        }

        $todayKey = $today->format('Y-m-d');

        return [
            'labels' => $labels,
            'completed' => $comp,
            'started' => $strt,
            'today_completed' => (int) ($completed[$todayKey] ?? 0),
            'today_started' => (int) ($started[$todayKey] ?? 0),
        ];
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

    /**
     * @param  array<string, mixed>  $row
     */
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
     * @param  array<int, int>  $scores
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
     * @param  array<int, int>  $ratios
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

    /**
     * @param  array<int, int|float>  $v
     */
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

    /**
     * @param  array<int, int|float>  $xs
     * @param  array<int, int|float>  $ys
     */
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
