<?php

use App\Support\Nutrition\ProfileContext;

it('resolves a profile by message from.id and touches last_seen_at', function () {
    $profile = nutritionProfile(['telegram_user_id' => 777, 'last_seen_at' => null]);

    $resolved = ProfileContext::resolve(['message' => ['from' => ['id' => 777], 'text' => 'hi']]);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($profile->id)
        ->and($resolved->last_seen_at)->not->toBeNull();
});

it('resolves a profile by callback_query from.id', function () {
    $profile = nutritionProfile(['telegram_user_id' => 888]);

    $resolved = ProfileContext::resolve(['callback_query' => ['from' => ['id' => 888], 'data' => 'ate:lunch']]);

    expect($resolved?->id)->toBe($profile->id);
});

it('returns null for an unknown sender', function () {
    nutritionProfile(['telegram_user_id' => 1]);

    expect(ProfileContext::resolve(['message' => ['from' => ['id' => 999], 'text' => 'hi']]))->toBeNull();
});

it('returns null when there is no from.id', function () {
    expect(ProfileContext::resolve(['message' => ['text' => 'hi']]))->toBeNull();
});
