<?php

namespace App\Http\Controllers;

use App\Models\GameContent;
use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GameController extends Controller
{
    /** Events the client is allowed to log for the funnel. */
    private const CLIENT_EVENTS = ['open', 'start', 'choice', 'finish', 'open_fund', 'restart'];

    public function show(): View|RedirectResponse
    {
        if (! Auth::check()) {
            return redirect()->route('game.register');
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

        [$bank, $max] = $this->benchmarks($content->data);

        $validated = $request->validate([
            'score_you' => ['required', 'integer', 'min:0', 'max:'.($max + 1)],
            'choices' => ['nullable', 'array'],
            'survey' => ['nullable', 'array'],
        ]);

        $scoreYou = (int) $validated['score_you'];
        $ratio = $max > 0 ? (int) round(min($scoreYou / $max, 1) * 100) : 0;

        $result = GameResult::create([
            'user_id' => Auth::id(),
            'score_you' => $scoreYou,
            'score_bank' => $bank,
            'score_max' => $max,
            'ratio' => $ratio,
            'choices' => $validated['choices'] ?? null,
            'survey_answers' => $this->sanitizeSurvey($content->data, $validated['survey'] ?? []) ?: null,
            'promo_code' => config('game.promo_code'),
        ]);

        GameEvent::create([
            'user_id' => Auth::id(),
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
            'event' => $validated['event'],
            'payload' => $validated['payload'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Deterministic benchmarks (independent of player choices), computed server-side.
     *
     * @param  array<string, mixed>  $content
     * @return array{0:int,1:int} [bank, max]
     */
    private function benchmarks(array $content): array
    {
        $start = (int) config('game.start_amount', 300000);
        $bank = $start;
        $max = $start;

        foreach ($content['years'] as $y) {
            $r = $y['ret'];
            $bank *= 1 + $r['bank'];
            $best = max($r['bank'], $r['cash'], $r['bond'], $r['stock'], ($r['bond'] + $r['stock']) / 2);
            $max *= 1 + $best;
        }

        return [(int) round($bank), (int) round($max)];
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
