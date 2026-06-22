<?php

use App\Actions\Game\BuildGameResultsReport;
use App\Models\GameContent;
use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

it('builds a 30-day daily activity series with today as the last bucket', function () {
    seedScenario();
    $u = User::factory()->create();
    GameResult::create(['user_id' => $u->id, 'score_you' => 1, 'score_bank' => 1, 'score_max' => 1, 'ratio' => 1, 'choices' => [], 'survey_answers' => []]);
    GameEvent::create(['user_id' => $u->id, 'event' => 'start']);

    $d = app(BuildGameResultsReport::class)();
    expect($d['daily']['labels'])->toHaveCount(30);
    expect($d['daily']['completed'])->toHaveCount(30);
    expect($d['daily']['started'])->toHaveCount(30);
    expect(end($d['daily']['completed']))->toBe(1); // today is the last bucket
    expect($d['daily']['today_completed'])->toBe(1);
    expect($d['daily']['today_started'])->toBe(1);
});

it('reports zero activity for today when there are no games', function () {
    seedScenario();
    $d = app(BuildGameResultsReport::class)();
    expect($d['daily']['today_completed'])->toBe(0);
    expect($d['daily']['today_started'])->toBe(0);
    expect(array_sum($d['daily']['completed']))->toBe(0);
});

it('exposes the active scenario id and total moves', function () {
    seedScenario();
    $active = GameContent::current();
    $u = User::factory()->create();
    GameResult::create(['user_id' => $u->id, 'game_content_id' => $active->id, 'score_you' => 460000, 'score_bank' => 443521, 'score_max' => 487970, 'ratio' => 94, 'choices' => bondChoices(), 'survey_answers' => []]);

    $d = app(BuildGameResultsReport::class)();
    expect($d['scenario_id'])->toBe($active->id);
    expect($d['total_moves'])->toBe(12);                  // one player, 12 bond choices
    expect($d['total_moves'])->toBe(array_sum($d['total_choice']));
});

it('scopes results and events to the active scenario version', function () {
    seedScenario();
    $active = GameContent::current();
    $other = GameContent::create(['is_active' => false, 'data' => ['years' => [], 'choices' => [], 'survey' => []]]);

    $u1 = User::factory()->create(); // tagged to the active version
    $u2 = User::factory()->create(); // legacy untagged (NULL) -> counts as active version
    $u3 = User::factory()->create(); // tagged to an older inactive version -> excluded

    GameResult::create(['user_id' => $u1->id, 'game_content_id' => $active->id, 'score_you' => 460000, 'score_bank' => 443521, 'score_max' => 487970, 'ratio' => 94, 'choices' => bondChoices(), 'survey_answers' => []]);
    GameResult::create(['user_id' => $u2->id, 'game_content_id' => null, 'score_you' => 450000, 'score_bank' => 443521, 'score_max' => 487970, 'ratio' => 92, 'choices' => bondChoices(), 'survey_answers' => []]);
    GameResult::create(['user_id' => $u3->id, 'game_content_id' => $other->id, 'score_you' => 470000, 'score_bank' => 443521, 'score_max' => 487970, 'ratio' => 96, 'choices' => bondChoices(), 'survey_answers' => []]);

    GameEvent::create(['user_id' => $u1->id, 'game_content_id' => $active->id, 'event' => 'open']);
    GameEvent::create(['user_id' => $u3->id, 'game_content_id' => $other->id, 'event' => 'open']);

    $d = app(BuildGameResultsReport::class)();

    expect($d['N'])->toBe(2);                                   // active + legacy null
    expect($d['total_results'])->toBe(2);                       // other-version result excluded
    expect($d['funnel'][0])->toBe(['Открыли игру', 1]);         // only the active-version open event
    expect(collect($d['leaderboard'])->pluck('best_score'))->not->toContain(470000);
});
