<?php

use App\Jobs\ProcessNutritionUpdate;
use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'nutrition.user_id' => 777,
        'nutrition.chat_id' => 123,
        'nutrition.bot_token' => '8640397639:TESTTOKEN',
        'nutrition.anthropic_key' => 'test-key',
        'nutrition.models.vision' => 'claude-haiku-4-5',
        'nutrition.models.chat' => 'claude-sonnet-5',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/x.jpg']]),
        'api.telegram.org/file/*' => Http::response('BINARYIMAGE'),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Идеально! 🙌🏼']]]),
    ]);
});

it('ignores a foreign sender in a group without any outgoing message or inbound record', function () {
    (new ProcessNutritionUpdate(['message' => [
        'from' => ['id' => 999],
        'chat' => ['id' => -100500, 'type' => 'supergroup'],
        'text' => 'привет',
    ]]))->handle();

    Http::assertNothingSent();
    expect(NutritionMessage::count())->toBe(0);
});

it('politely refuses a foreign sender in a private chat', function () {
    (new ProcessNutritionUpdate(['message' => [
        'from' => ['id' => 999],
        'chat' => ['id' => 999, 'type' => 'private'],
        'text' => 'привет',
    ]]))->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] == 999
            && str_contains($request['text'], 'персональный бот');
    });
    expect(NutritionMessage::count())->toBe(0);
});

it('handles /today from the owner in a group and replies into the source chat', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));

    (new ProcessNutritionUpdate(['message' => [
        'from' => ['id' => 777],
        'chat' => ['id' => -100500, 'type' => 'supergroup'],
        'message_id' => 5,
        'text' => '/today',
    ]]))->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] == -100500
            && str_contains($request['text'], 'Обед');
    });

    $msg = NutritionMessage::query()->where('direction', 'in')->first();
    expect($msg)->not->toBeNull()
        ->and($msg->kind)->toBe('command')
        ->and($msg->meta['chat_id'])->toBe(-100500);
});

it('welcomes the group and logs when the bot is added via new_chat_members', function () {
    Log::spy();

    (new ProcessNutritionUpdate(['message' => [
        'chat' => ['id' => -100777, 'type' => 'supergroup'],
        'new_chat_members' => [
            ['id' => 111, 'is_bot' => false],
            ['id' => 8640397639, 'is_bot' => true, 'username' => 'gleb_nutri_bot'],
        ],
    ]]))->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] == -100777
            && str_contains($request['text'], 'нутрициолог');
    });
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message) => $message === 'nutrition: added to chat')
        ->once();
});

it('ignores a new_chat_members event that does not include the bot', function () {
    (new ProcessNutritionUpdate(['message' => [
        'chat' => ['id' => -100777, 'type' => 'supergroup'],
        'from' => ['id' => 777],
        'new_chat_members' => [['id' => 555, 'is_bot' => false]],
    ]]))->handle();

    Http::assertNothingSent();
    expect(NutritionMessage::count())->toBe(0);
});

it('handles a callback from the owner in a group and replies into the group', function () {
    $this->travelTo(CarbonImmutable::create(2026, 7, 13, 11, 30, 0, 'Europe/Moscow'));

    (new ProcessNutritionUpdate(['callback_query' => [
        'id' => 'cb1',
        'from' => ['id' => 777],
        'data' => 'ate:lunch',
        'message' => ['chat' => ['id' => -100500, 'type' => 'supergroup'], 'message_id' => 7],
    ]]))->handle();

    expect(NutritionMeal::query()->where('type', 'lunch')->value('status'))->toBe('eaten');
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] == -100500
            && str_contains($request['text'], 'Обед');
    });
    Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery'));
});
