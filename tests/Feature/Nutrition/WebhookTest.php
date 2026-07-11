<?php

use App\Actions\Nutrition\HandleCommand;
use App\Jobs\ProcessNutritionUpdate;
use App\Models\NutritionMessage;
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

it('replies with the personal-bot notice for a foreign sender and stores nothing inbound', function () {
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

    expect(NutritionMessage::where('direction', 'in')->count())->toBe(0);
});

it('routes a slash command from the owner to HandleCommand and logs it inbound', function () {
    $update = ['message' => ['from' => ['id' => 123], 'message_id' => 42, 'text' => '/today']];

    $this->mock(HandleCommand::class)
        ->shouldReceive('handle')
        ->once()
        ->with($update);

    (new ProcessNutritionUpdate($update))->handle();

    $msg = NutritionMessage::where('direction', 'in')->first();
    expect($msg)->not->toBeNull()
        ->and($msg->kind)->toBe('command')
        ->and($msg->content)->toBe('/today');
});
