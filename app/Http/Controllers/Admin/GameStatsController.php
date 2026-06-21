<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameContent;
use App\Models\GameEvent;
use App\Models\GameResult;
use Illuminate\View\View;

class GameStatsController extends Controller
{
    /**
     * Short column labels for the per-user table, keyed by survey question id.
     *
     * @var array<string, string>
     */
    private const SURVEY_SHORT_LABELS = [
        'experience' => 'Опыт',
        'helped' => 'Помогла',
        'plan_invest' => 'Планы вложить',
        'ready_funds' => 'Готовность к фондам',
        'priority' => 'Приоритет',
    ];

    public function index(): View
    {
        $content = GameContent::current()?->data ?? ['choices' => [], 'survey' => []];

        $completedUsers = GameResult::query()->distinct()->count('user_id');

        $funnel = [
            ['label' => 'Открыли игру', 'count' => $this->uniqueUsers('open')],
            ['label' => 'Начали играть', 'count' => $this->uniqueUsers('start')],
            ['label' => 'Дошли до финала', 'count' => $this->uniqueUsers('finish')],
            ['label' => 'Завершили (с опросом)', 'count' => $completedUsers],
            ['label' => 'Перешли на Финуслуги', 'count' => $this->uniqueUsers('open_fund')],
        ];

        // Choice distribution from completed games (game_results.choices) and all attempts (events).
        $choiceLabels = collect($content['choices'])->pluck('t', 'k');
        $choiceFromResults = $this->countChoicesFromResults();
        $choiceFromEvents = $this->countChoicesFromEvents();

        // Survey answer distribution per question.
        $answers = GameResult::query()->whereNotNull('survey_answers')->pluck('survey_answers');
        $surveyStats = collect($content['survey'])->map(function ($q) use ($answers) {
            $counts = array_fill_keys($q['options'], 0);
            foreach ($answers as $a) {
                $v = $a[$q['id']] ?? null;
                if ($v !== null && array_key_exists($v, $counts)) {
                    $counts[$v]++;
                }
            }

            return ['question' => $q['question'], 'counts' => $counts, 'total' => array_sum($counts)];
        })->all();

        $surveyColumns = $this->surveyColumns($content['survey'] ?? []);

        return view('admin.game.stats', [
            'funnel' => $funnel,
            'choiceLabels' => $choiceLabels,
            'choiceFromResults' => $choiceFromResults,
            'choiceFromEvents' => $choiceFromEvents,
            'years' => $content['years'] ?? [],
            'perQuarter' => $this->choicesByQuarter(),
            'surveyStats' => $surveyStats,
            'leaderboard' => $this->buildLeaderboard(),
            'surveyColumns' => $surveyColumns,
            'playsTotal' => GameResult::count(),
            'avgScore' => (int) round(GameResult::avg('score_you') ?? 0),
            'fundClicksTotal' => GameEvent::where('event', 'open_fund')->count(),
            'fundClicksUnique' => $this->uniqueUsers('open_fund'),
        ]);
    }

    private function uniqueUsers(string $event): int
    {
        return GameEvent::query()->where('event', $event)->distinct()->count('user_id');
    }

    /**
     * @return array<string, int>
     */
    private function countChoicesFromResults(): array
    {
        $counts = [];
        foreach (GameResult::query()->whereNotNull('choices')->pluck('choices') as $choices) {
            foreach ((array) $choices as $c) {
                $k = $c['k'] ?? null;
                if ($k) {
                    $counts[$k] = ($counts[$k] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function countChoicesFromEvents(): array
    {
        $counts = [];
        foreach (GameEvent::query()->where('event', 'choice')->pluck('payload') as $payload) {
            $k = $payload['k'] ?? null;
            if ($k) {
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Per-quarter choice distribution across ALL moves (every `choice` event, incl. abandoned games):
     * quarter number => [instrument key => count].
     *
     * @return array<int, array<string, int>>
     */
    private function choicesByQuarter(): array
    {
        $byQuarter = [];
        foreach (GameEvent::query()->where('event', 'choice')->pluck('payload') as $payload) {
            $quarter = $payload['quarter'] ?? null;
            $k = $payload['k'] ?? null;
            if ($quarter && $k) {
                $byQuarter[$quarter][$k] = ($byQuarter[$quarter][$k] ?? 0) + 1;
            }
        }

        return $byQuarter;
    }

    /**
     * Survey questions reduced to table columns: id, short header and full question (for tooltip).
     *
     * @param  array<int, array{id: string, question: string, options: array<int, string>}>  $survey
     * @return array<int, array{id: string, label: string, question: string}>
     */
    private function surveyColumns(array $survey): array
    {
        return collect($survey)->map(fn ($q) => [
            'id' => $q['id'],
            'label' => self::SURVEY_SHORT_LABELS[$q['id']] ?? $q['id'],
            'question' => $q['question'] ?? '',
        ])->all();
    }

    /**
     * One row per user, ranked by their best portfolio (max score_you, desc).
     * Each row carries the play count, start count and the survey answers from the latest attempt.
     *
     * @return array<int, array{user_id: int, name: ?string, email: ?string, best_score: int, ratio: int, beat_bank: bool, plays: int, starts: int, survey: array<string, string>}>
     */
    private function buildLeaderboard(): array
    {
        $startsByUser = GameEvent::query()
            ->where('event', 'start')
            ->selectRaw('user_id, count(*) as aggregate')
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id');

        return GameResult::query()
            ->with('user:id,name,email')
            ->get()
            ->groupBy('user_id')
            ->map(function ($group) use ($startsByUser) {
                $best = $group->sortByDesc('score_you')->first();
                $latest = $group->sortByDesc('id')->first();
                $user = $best->user;

                return [
                    'user_id' => (int) $best->user_id,
                    'name' => $user?->name,
                    'email' => $user?->email,
                    'best_score' => (int) $best->score_you,
                    'ratio' => (int) $best->ratio,
                    'beat_bank' => $best->score_you > $best->score_bank,
                    'plays' => $group->count(),
                    'starts' => (int) ($startsByUser[$best->user_id] ?? 0),
                    'survey' => (array) ($latest->survey_answers ?? []),
                ];
            })
            ->sortByDesc('best_score')
            ->values()
            ->all();
    }
}
