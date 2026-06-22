<?php

use App\Actions\Admin\BuildSiteReport;
use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
