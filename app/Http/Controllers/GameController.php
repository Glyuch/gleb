<?php

namespace App\Http\Controllers;

use App\Models\GameContent;
use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;
use App\Support\Game\Scenario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GameController extends Controller
{
    /** Events the client is allowed to log for the funnel. */
    private const CLIENT_EVENTS = ['open', 'start', 'choice', 'finish', 'open_fund', 'restart'];

    public function show(): View|RedirectResponse
    {
        if (! Auth::check()) {
            return view('auth.game-landing');
        }

        $content = GameContent::current();
        abort_if($content === null, 503, 'Игра ещё не настроена.');

        return view('game', [
            'content' => $content->data,
            'user' => ['id' => Auth::id(), 'name' => Auth::user()->name],
            'promo' => config('game.promo_code'),
            'shopUrl' => config('game.shop_url'),
        ]);
    }

    /**
     * Branded registration gate for the game (RU). Sends the user back to /game after Fortify registers them.
     */
    public function showRegister(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('game');
        }

        session(['url.intended' => url('/game')]);

        return view('auth.game-register');
    }

    /**
     * Branded login gate for the game (RU).
     */
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('game');
        }

        session(['url.intended' => url('/game')]);

        return view('auth.game-login');
    }

    public function store(Request $request): JsonResponse
    {
        $content = GameContent::current();
        abort_if($content === null, 503);

        $instruments = $this->instruments($content->data);
        $n = count($content->data['years']);

        $validated = $request->validate([
            'choices' => ['required', 'array', 'size:'.$n],
            'choices.*.quarter' => ['required', 'integer', 'min:1', 'max:'.$n],
            'choices.*.k' => ['required', 'string', Rule::in($instruments)],
            'survey' => ['nullable', 'array'],
            // Client-computed portfolio — accepted only as a sanity check, never persisted.
            'score_you' => ['nullable', 'integer', 'min:0'],
        ]);

        // Each quarter 1..n must appear exactly once.
        $pick = [];
        foreach ($validated['choices'] as $c) {
            $pick[(int) $c['quarter']] = $c['k'];
        }
        if (array_keys($pick) === [] || ! $this->coversAllQuarters($pick, $n)) {
            throw ValidationException::withMessages([
                'choices' => "Каждый квартал 1..{$n} должен встречаться ровно один раз.",
            ]);
        }

        // Server is the source of truth: recompute everything deterministically from choices.
        [$you, $composition] = $this->simulate($content->data, $pick, $instruments);
        [$bank, $max] = $this->benchmarks($content->data);

        $scoreYou = (int) round($you);
        $ratio = $max > 0 ? (int) round(min($you / $max, 1) * 100) : 0;

        if (isset($validated['score_you']) && (int) $validated['score_you'] > 0 && abs((int) $validated['score_you'] - $scoreYou) > 1) {
            Log::warning('game: client/server score mismatch', [
                'user_id' => Auth::id(),
                'game_content_id' => $content->id,
                'client' => (int) $validated['score_you'],
                'server' => $scoreYou,
            ]);
        }

        $result = GameResult::create([
            'user_id' => Auth::id(),
            'game_content_id' => $content->id,
            'score_you' => $scoreYou,
            'score_bank' => $bank,
            'score_max' => $max,
            'ratio' => $ratio,
            'choices' => $validated['choices'],
            'survey_answers' => $this->sanitizeSurvey($content->data, $validated['survey'] ?? []) ?: null,
            'composition' => $composition,
            'promo_code' => config('game.promo_code'),
        ]);

        GameEvent::create([
            'user_id' => Auth::id(),
            'game_content_id' => $content->id,
            'event' => 'result',
            'payload' => ['score' => $scoreYou, 'ratio' => $ratio],
        ]);

        return response()->json([
            'promo' => config('game.promo_code'),
            'shopUrl' => config('game.shop_url'),
            'leaderboard' => $this->leaderboardData(),
            'rank' => $this->rankFor((int) Auth::id()),
        ]);
    }

    public function leaderboard(): JsonResponse
    {
        return response()->json([
            'leaderboard' => $this->leaderboardData(),
            'rank' => $this->rankFor((int) Auth::id()),
        ]);
    }

    public function event(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event' => ['required', 'string', Rule::in(self::CLIENT_EVENTS)],
            'payload' => ['nullable', 'array'],
        ]);

        GameEvent::create([
            'user_id' => Auth::id(),
            'game_content_id' => GameContent::query()->active()->value('id'),
            'event' => $validated['event'],
            'payload' => $validated['payload'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Instruments available this scenario, derived from the content's choices (fallback to the canonical five).
     *
     * @param  array<string, mixed>  $content
     * @return array<int, string>
     */
    private function instruments(array $content): array
    {
        return (new Scenario($content))->instruments();
    }

    /**
     * True when $pick has exactly one choice for every quarter 1..n.
     *
     * @param  array<int, string>  $pick
     */
    private function coversAllQuarters(array $pick, int $n): bool
    {
        if (count($pick) !== $n) {
            return false;
        }
        for ($q = 1; $q <= $n; $q++) {
            if (! isset($pick[$q])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical DCA simulation (matches the client engine and the spec §3.2):
     * each quarter, grow existing balances, then add the contribution to the chosen
     * instrument (so a contribution first grows in the NEXT quarter).
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
            // Every instrument (incl. the rolling deposit) floats: contribution grows over q+1..n-1.
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
     * Deterministic DCA benchmarks (independent of player choices), computed server-side.
     * Same contributions/timing as the player: a contribution in quarter q grows over t=q+1..n-1.
     *
     * @param  array<string, mixed>  $content
     * @return array{0:int,1:int} [bank, max]
     */
    private function benchmarks(array $content): array
    {
        $b = (new Scenario($content))->benchmarks();

        return [$b['bank'], $b['max']];
    }

    /**
     * Keep only known question ids whose answer is one of that question's options.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $answers
     * @return array<string, string>
     */
    private function sanitizeSurvey(array $content, array $answers): array
    {
        $allowed = collect($content['survey'] ?? [])->mapWithKeys(
            fn ($q) => [$q['id'] => $q['options']]
        );

        $clean = [];
        foreach ($answers as $qid => $value) {
            if (is_string($value) && $allowed->has($qid) && in_array($value, $allowed[$qid], true)) {
                $clean[$qid] = $value;
            }
        }

        return $clean;
    }

    /**
     * Best score per user, ordered desc. Collection of {user_id, best}.
     */
    private function bests(): Collection
    {
        return GameResult::query()
            ->selectRaw('user_id, MAX(score_you) as best')
            ->groupBy('user_id')
            ->orderByDesc('best')
            ->orderBy('user_id')
            ->get();
    }

    /**
     * Top-20 leaderboard rows: [{rank, name, score}].
     *
     * @return array<int, array{rank:int,name:string,score:int}>
     */
    private function leaderboardData(): array
    {
        $top = $this->bests()->take(20)->values();
        $names = User::query()->whereIn('id', $top->pluck('user_id'))->pluck('name', 'id');

        return $top->map(fn ($row, $i) => [
            'rank' => $i + 1,
            'name' => $names[$row->user_id] ?? '—',
            'score' => (int) $row->best,
        ])->all();
    }

    private function rankFor(int $userId): ?int
    {
        $idx = $this->bests()->search(fn ($row) => (int) $row->user_id === $userId);

        return $idx === false ? null : $idx + 1;
    }
}
