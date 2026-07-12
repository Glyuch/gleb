<?php

use App\Actions\Nutrition\HandleCommand;
use App\Jobs\ProcessNutritionUpdate;
use App\Models\NutritionInvite;
use App\Models\NutritionMessage;
use App\Models\NutritionProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'nutrition.webhook_secret' => 's3cret',
        'nutrition.chat_id' => 123,
        'nutrition.bot_token' => 'test-token',
    ]);
});

it('rejects the webhook without the secret header', function () {
    Queue::fake();

    $this->postJson('/nutrition-bot/webhook', ['message' => ['text' => 'hi']])
        ->assertStatus(403);

    Queue::assertNothingPushed();
});

it('accepts the webhook with the secret header and dispatches the job', function () {
    Queue::fake();

    $this->postJson(
        '/nutrition-bot/webhook',
        ['message' => ['from' => ['id' => 123], 'text' => 'hi']],
        ['X-Telegram-Bot-Api-Secret-Token' => 's3cret'],
    )
        ->assertOk()
        ->assertExactJson(['ok' => true]);

    Queue::assertPushed(ProcessNutritionUpdate::class);
});

it('sends the invite prompt to a foreign sender and stores nothing inbound', function () {
    nutritionProfile(['telegram_user_id' => 777]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    (new ProcessNutritionUpdate(['message' => ['from' => ['id' => 999], 'text' => 'hi']]))->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] == 999
            && str_contains($request['text'], 'персональный бот');
    });

    expect(NutritionMessage::count())->toBe(0);
});

it('redeems a valid invite code into an onboarding profile and marks it used', function () {
    $admin = nutritionProfile(['telegram_user_id' => 777]);
    $invite = NutritionInvite::generate($admin);

    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    (new ProcessNutritionUpdate(['message' => [
        'from' => ['id' => 555, 'first_name' => 'Аня', 'username' => 'anya'],
        'chat' => ['id' => 555, 'type' => 'private'],
        'text' => $invite->code,
    ]]))->handle();

    $newProfile = NutritionProfile::query()->where('telegram_user_id', 555)->first();
    expect($newProfile)->not->toBeNull()
        ->and($newProfile->status)->toBe('onboarding')
        ->and($newProfile->name)->toBe('Аня')
        ->and($newProfile->waiting('onboarding_step'))->toBe(1);

    expect($invite->fresh()->used_by_profile_id)->toBe($newProfile->id)
        ->and($invite->fresh()->used_at)->not->toBeNull();

    // Погашение кода не только подтверждает, но и стартует анкету (первый вопрос).
    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
        && str_contains($request['text'], 'Код принят'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
        && str_contains($request['text'], 'Как к тебе обращаться'));
});

it('politely refuses an unknown invite code and creates no profile', function () {
    nutritionProfile(['telegram_user_id' => 777]);

    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    (new ProcessNutritionUpdate(['message' => [
        'from' => ['id' => 556],
        'chat' => ['id' => 556, 'type' => 'private'],
        'text' => 'ABC234',
    ]]))->handle();

    expect(NutritionProfile::query()->where('telegram_user_id', 556)->exists())->toBeFalse();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
        && str_contains($request['text'], 'не подошёл'));
});

it('tells a paused profile it is on hold and does not route', function () {
    nutritionProfile(['telegram_user_id' => 558, 'status' => 'paused', 'is_admin' => false]);

    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    (new ProcessNutritionUpdate(['message' => [
        'from' => ['id' => 558],
        'chat' => ['id' => 558, 'type' => 'private'],
        'text' => '/today',
    ]]))->handle();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
        && str_contains($request['text'], 'на паузе'));
    expect(NutritionMessage::count())->toBe(0);
});

it('stays silent for a paused profile in a group chat', function () {
    nutritionProfile(['telegram_user_id' => 559, 'status' => 'paused', 'is_admin' => false]);

    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    (new ProcessNutritionUpdate(['message' => [
        'from' => ['id' => 559],
        'chat' => ['id' => -100559, 'type' => 'supergroup'],
        'text' => '/today',
    ]]))->handle();

    Http::assertNothingSent();
    expect(NutritionMessage::count())->toBe(0);
});

it('routes a slash command from the owner to HandleCommand and logs it inbound', function () {
    $profile = nutritionProfile(['telegram_user_id' => 123]);

    $update = ['message' => ['from' => ['id' => 123], 'message_id' => 42, 'text' => '/today']];

    $this->mock(HandleCommand::class)
        ->shouldReceive('handle')
        ->once()
        ->with($update, Mockery::type(NutritionProfile::class));

    (new ProcessNutritionUpdate($update))->handle();

    $msg = NutritionMessage::where('direction', 'in')->first();
    expect($msg)->not->toBeNull()
        ->and($msg->kind)->toBe('command')
        ->and($msg->content)->toBe('/today')
        ->and($msg->profile_id)->toBe($profile->id);
});
