<?php

use App\Models\NutritionInvite;
use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use App\Models\NutritionProfile;
use App\Models\NutritionSetting;
use App\Models\NutritionTopic;
use App\Models\NutritionTopicSend;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('creates all v2 tables and columns', function () {
    expect(Schema::hasTable('nutrition_profiles'))->toBeTrue()
        ->and(Schema::hasTable('nutrition_invites'))->toBeTrue()
        ->and(Schema::hasTable('nutrition_topic_sends'))->toBeTrue();

    expect(Schema::hasColumns('nutrition_profiles', [
        'telegram_user_id', 'name', 'username', 'main_chat_id', 'is_admin', 'status',
        'phase', 'timezone', 'program_started_on', 'ai_profile', 'settings', 'awaiting', 'last_seen_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('nutrition_invites', [
        'code', 'created_by_profile_id', 'used_by_profile_id', 'used_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('nutrition_topic_sends', [
        'profile_id', 'topic_id', 'scheduled_on', 'sent_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('nutrition_meals', ['profile_id', 'score', 'rating']))->toBeTrue()
        ->and(Schema::hasColumn('nutrition_metrics', 'profile_id'))->toBeTrue()
        ->and(Schema::hasColumn('nutrition_messages', 'profile_id'))->toBeTrue();
});

it('enforces the per-profile meals unique[profile_id,date,type] index', function () {
    $a = nutritionProfile(['telegram_user_id' => 100]);
    $b = nutritionProfile(['telegram_user_id' => 200, 'is_admin' => false]);

    NutritionMeal::create(['profile_id' => $a->id, 'date' => '2026-07-11', 'type' => 'lunch']);

    // Тот же профиль/дата/тип — дубль запрещён.
    expect(fn () => NutritionMeal::create(['profile_id' => $a->id, 'date' => '2026-07-11', 'type' => 'lunch']))
        ->toThrow(QueryException::class);

    // Другой профиль — та же дата/тип разрешены (изоляция).
    $other = NutritionMeal::create(['profile_id' => $b->id, 'date' => '2026-07-11', 'type' => 'lunch']);
    expect($other->exists)->toBeTrue();
});

it('generates unique readable invite codes tied to the creator', function () {
    $by = NutritionProfile::create(['telegram_user_id' => 100, 'name' => 'A']);

    $codes = [];
    for ($i = 0; $i < 60; $i++) {
        $inv = NutritionInvite::generate($by);
        expect($inv->code)->toMatch('/^[A-HJ-NP-Z2-9]{6}$/')
            ->and($inv->created_by_profile_id)->toBe($by->id)
            ->and($inv->used_by_profile_id)->toBeNull();
        $codes[] = $inv->code;
    }

    expect(array_unique($codes))->toHaveCount(count($codes));
});

it('falls back to default settings and persists setting/waiting mutations', function () {
    $p = NutritionProfile::create(['telegram_user_id' => 111, 'name' => 'X']);

    expect($p->setting('steps_target'))->toBe(7000)
        ->and($p->setting('wake_time'))->toBe('07:00')
        ->and($p->setting('default_windows'))->toHaveKey('breakfast')
        ->and($p->setting('missing', 'fallback'))->toBe('fallback');

    $p->setSetting('steps_target', 9000);
    expect($p->fresh()->setting('steps_target'))->toBe(9000);

    expect($p->waiting('setting'))->toBeNull();
    $p->setWaiting('setting', 'wake_time');
    expect($p->fresh()->waiting('setting'))->toBe('wake_time');
    $p->clearWaiting('setting');
    expect($p->fresh()->waiting('setting'))->toBeNull();
});

it('defaults timezone to Europe/Moscow and exposes tz()/now() helpers', function () {
    // Профиль без явного timezone → дефолт Europe/Moscow (DDL-дефолт + бэкфилл).
    $p = NutritionProfile::create(['telegram_user_id' => 321, 'name' => 'Z']);
    expect($p->fresh()->timezone)->toBe('Europe/Moscow')
        ->and($p->tz())->toBe('Europe/Moscow');

    // Местное время профиля считается в его поясе.
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 12, 0, 0, 'UTC'));
    $moscow = nutritionProfile(['telegram_user_id' => 401]);            // Europe/Moscow (+3)
    $yekat = nutritionProfile(['telegram_user_id' => 402, 'is_admin' => false, 'timezone' => 'Asia/Yekaterinburg']); // +5

    expect($moscow->now()->format('H:i'))->toBe('15:00')
        ->and($yekat->now()->format('H:i'))->toBe('17:00')
        ->and($yekat->now()->getTimestamp())->toBe($moscow->now()->getTimestamp());

    // Пустой пояс откатывается на Europe/Moscow.
    $blank = nutritionProfile(['telegram_user_id' => 403, 'is_admin' => false, 'timezone' => '']);
    expect($blank->tz())->toBe('Europe/Moscow');
});

it('resolves the admin profile', function () {
    expect(NutritionProfile::admin())->toBeNull();

    $admin = NutritionProfile::create(['telegram_user_id' => 1, 'name' => 'Admin', 'is_admin' => true]);
    NutritionProfile::create(['telegram_user_id' => 2, 'name' => 'Plain']);

    expect(NutritionProfile::admin()->id)->toBe($admin->id);
});

it('backfills the Gleb profile from legacy data idempotently', function () {
    config(['nutrition.user_id' => 49465703, 'nutrition.chat_id' => -5338515969]);

    NutritionSetting::create(['key' => 'phase', 'value' => 'program']);
    NutritionSetting::create(['key' => 'program_started_on', 'value' => '2026-07-11']);
    NutritionSetting::create(['key' => 'steps_target', 'value' => 8000]);
    NutritionSetting::create(['key' => 'wake_time', 'value' => '06:30']);
    NutritionSetting::create(['key' => 'awaiting_setting', 'value' => 'wake_time']);
    NutritionSetting::create(['key' => 'awaiting_meal_time', 'value' => 'lunch']);

    $meal = NutritionMeal::create(['date' => '2026-07-11', 'type' => 'lunch']);
    $metric = NutritionMetric::create(['date' => '2026-07-11', 'type' => 'weight', 'value' => 80]);
    $msg = NutritionMessage::create(['direction' => 'in', 'content' => 'hi']);
    NutritionTopic::create(['title' => 'T', 'position' => 1]);
    NutritionTopic::create(['title' => 'T2', 'position' => 2]);

    NutritionProfile::backfillFromLegacy();

    $p = NutritionProfile::where('telegram_user_id', 49465703)->first();
    expect($p)->not->toBeNull()
        ->and($p->name)->toBe('Глеб')
        ->and($p->is_admin)->toBeTrue()
        ->and($p->status)->toBe('active')
        ->and($p->main_chat_id)->toBe(-5338515969)
        ->and($p->phase)->toBe('program')
        ->and($p->program_started_on->toDateString())->toBe('2026-07-11')
        ->and($p->setting('steps_target'))->toBe(8000)
        ->and($p->setting('wake_time'))->toBe('06:30')
        ->and($p->waiting('setting'))->toBe('wake_time')
        ->and($p->waiting('meal_time'))->toBe('lunch');

    expect($meal->fresh()->profile_id)->toBe($p->id)
        ->and($metric->fresh()->profile_id)->toBe($p->id)
        ->and($msg->fresh()->profile_id)->toBe($p->id);

    // Раскладка тем теперь per-profile через StartProgram, backfill их не создаёт.
    expect(NutritionTopicSend::where('profile_id', $p->id)->count())->toBe(0);

    NutritionProfile::backfillFromLegacy();
    expect(NutritionProfile::where('telegram_user_id', 49465703)->count())->toBe(1)
        ->and(NutritionTopicSend::count())->toBe(0);
});

it('does nothing when the legacy user id is not configured', function () {
    config(['nutrition.user_id' => '']);

    NutritionProfile::backfillFromLegacy();

    expect(NutritionProfile::count())->toBe(0);
});
