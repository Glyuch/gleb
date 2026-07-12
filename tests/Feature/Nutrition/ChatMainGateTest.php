<?php

use App\Jobs\ProcessNutritionUpdate;
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

it('records the chatmain offer when the owner adds the bot to a group', function () {
    $p = nutritionProfile(['telegram_user_id' => 777]);

    (new ProcessNutritionUpdate(['message' => [
        'from' => ['id' => 777],
        'chat' => ['id' => -100777, 'type' => 'supergroup'],
        'new_chat_members' => [['id' => 8640397639, 'is_bot' => true]],
    ]]))->handle();

    expect($p->fresh()->waiting('chatmain_offer'))->toBe(-100777);
});

it('accepts chatmain:yes only from the offered chat', function () {
    $p = nutritionProfile(['telegram_user_id' => 777, 'main_chat_id' => 123]);
    $p->setWaiting('chatmain_offer', -100500);

    (new ProcessNutritionUpdate(['callback_query' => [
        'id' => 'cb1',
        'from' => ['id' => 777],
        'data' => 'chatmain:yes',
        'message' => ['chat' => ['id' => -100500, 'type' => 'supergroup'], 'message_id' => 7],
    ]]))->handle();

    $p->refresh();
    expect($p->main_chat_id)->toBe(-100500)
        ->and($p->waiting('chatmain_offer'))->toBeNull();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/answerCallbackQuery'));
});

it('ignores chatmain:yes when no offer is pending (gate)', function () {
    $p = nutritionProfile(['telegram_user_id' => 777, 'main_chat_id' => 123]);
    // No chatmain_offer set.

    (new ProcessNutritionUpdate(['callback_query' => [
        'id' => 'cb1',
        'from' => ['id' => 777],
        'data' => 'chatmain:yes',
        'message' => ['chat' => ['id' => -100500, 'type' => 'supergroup'], 'message_id' => 7],
    ]]))->handle();

    // main_chat_id must NOT change; the callback is still acknowledged.
    expect($p->fresh()->main_chat_id)->toBe(123);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/answerCallbackQuery'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'] ?? '', 'плановые сообщения'));
});

it('ignores chatmain:yes from a different chat than offered (gate)', function () {
    $p = nutritionProfile(['telegram_user_id' => 777, 'main_chat_id' => 123]);
    $p->setWaiting('chatmain_offer', -100500);

    (new ProcessNutritionUpdate(['callback_query' => [
        'id' => 'cb1',
        'from' => ['id' => 777],
        'data' => 'chatmain:yes',
        'message' => ['chat' => ['id' => -100999, 'type' => 'supergroup'], 'message_id' => 7],
    ]]))->handle();

    expect($p->fresh()->main_chat_id)->toBe(123);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/answerCallbackQuery'));
});
