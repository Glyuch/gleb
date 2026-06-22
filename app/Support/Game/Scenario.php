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
