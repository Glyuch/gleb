<?php

use App\Jobs\ProcessNutritionUpdate;
use App\Models\NutritionInvite;
use App\Models\NutritionProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'nutrition.chat_id' => 123,
        'nutrition.bot_token' => '8640397639:TESTTOKEN',
        'nutrition.anthropic_key' => 'test-key',
        'nutrition.models.vision' => 'claude-haiku-4-5',
        'nutrition.models.chat' => 'claude-sonnet-5',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ок']]]),
    ]);
});

it('redeems a valid invite and creates an onboarding profile', function () {
    $owner = nutritionProfile(['telegram_user_id' => 777]);
    $invite = NutritionInvite::generate($owner);

    (new ProcessNutritionUpdate(['message' => [
        'from' => ['id' => 999, 'first_name' => 'Гость', 'username' => 'guest'],
        'chat' => ['id' => 999, 'type' => 'private'],
        'text' => $invite->code,
    ]]))->handle();

    $new = NutritionProfile::where('telegram_user_id', 999)->first();
    expect($new)->not->toBeNull()
        ->and($new->status)->toBe('onboarding');
    expect($invite->fresh()->used_by_profile_id)->toBe($new->id);
});

it('rejects an unknown invite code without creating a profile', function () {
    nutritionProfile(['telegram_user_id' => 777]);

    (new ProcessNutritionUpdate(['message' => [
        'from' => ['id' => 999, 'first_name' => 'Гость'],
        'chat' => ['id' => 999, 'type' => 'private'],
        'text' => 'ZZZZZZ',
    ]]))->handle();

    expect(NutritionProfile::where('telegram_user_id', 999)->exists())->toBeFalse();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'не подошёл'));
});

it('does not double-redeem an already used invite', function () {
    $owner = nutritionProfile(['telegram_user_id' => 777]);
    $other = nutritionProfile(['telegram_user_id' => 111, 'is_admin' => false]);
    $invite = NutritionInvite::generate($owner);
    $invite->update(['used_by_profile_id' => $other->id, 'used_at' => now()]);

    (new ProcessNutritionUpdate(['message' => [
        'from' => ['id' => 999, 'first_name' => 'Гость'],
        'chat' => ['id' => 999, 'type' => 'private'],
        'text' => $invite->code,
    ]]))->handle();

    expect(NutritionProfile::where('telegram_user_id', 999)->exists())->toBeFalse();
    expect($invite->fresh()->used_by_profile_id)->toBe($other->id);
});

it('rolls back the fresh profile when a racing worker claims the invite first (atomic claim)', function () {
    $owner = nutritionProfile(['telegram_user_id' => 777]);
    $racer = nutritionProfile(['telegram_user_id' => 111, 'is_admin' => false]);
    $invite = NutritionInvite::generate($owner);

    // Simulate a competing worker: the instant our redeem-created profile is inserted,
    // a raced UPDATE claims the same invite for the racer (used_by IS NULL → filled).
    // Our redeem's own atomic UPDATE then affects 0 rows and must roll the profile back.
    NutritionProfile::created(function (NutritionProfile $p) use ($invite, $racer) {
        if ((int) $p->telegram_user_id === 999) {
            DB::table('nutrition_invites')
                ->where('id', $invite->id)
                ->whereNull('used_by_profile_id')
                ->update(['used_by_profile_id' => $racer->id, 'used_at' => now()]);
        }
    });

    try {
        (new ProcessNutritionUpdate(['message' => [
            'from' => ['id' => 999, 'first_name' => 'Гость'],
            'chat' => ['id' => 999, 'type' => 'private'],
            'text' => $invite->code,
        ]]))->handle();
    } finally {
        NutritionProfile::flushEventListeners();
    }

    // Racer kept the invite; the losing worker's profile was rolled back.
    expect($invite->fresh()->used_by_profile_id)->toBe($racer->id);
    expect(NutritionProfile::where('telegram_user_id', 999)->exists())->toBeFalse();
});
