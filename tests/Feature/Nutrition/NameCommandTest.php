<?php

use App\Actions\Nutrition\HandleCommand;
use App\Actions\Nutrition\HandleNumbers;
use App\Actions\Nutrition\HandleQuestion;
use App\Models\NutritionMessage;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'nutrition.chat_id' => 123,
        'nutrition.bot_token' => 'test-token',
        'nutrition.anthropic_key' => 'test-key',
        'nutrition.models.vision' => 'claude-haiku-4-5',
        'nutrition.models.chat' => 'claude-sonnet-5',
    ]);

    $this->profile = nutritionProfile();

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Ответ']]]),
    ]);
});

it('sets the name immediately from an inline argument', function () {
    app(HandleCommand::class)->handle(['message' => ['text' => '/name Ваня']], $this->profile);

    expect($this->profile->fresh()->name)->toBe('Ваня')
        ->and($this->profile->fresh()->waiting('name'))->toBeNull();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'Готово, Ваня')
        && str_contains($r['text'], 'обращаться к тебе так'));
});

it('shows the current name and awaits input on bare /name', function () {
    $this->profile->update(['name' => 'Глеб']);

    app(HandleCommand::class)->handle(['message' => ['text' => '/name']], $this->profile);

    expect($this->profile->fresh()->waiting('name'))->toBeTrue();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'Глеб')
        && str_contains($r['text'], 'Как к тебе обращаться'));
});

it('captures the new name from the awaiting flow', function () {
    app(HandleCommand::class)->handle(['message' => ['text' => '/name']], $this->profile);
    expect($this->profile->fresh()->waiting('name'))->toBeTrue();

    app(HandleQuestion::class)->handle(['message' => ['text' => 'Петя']], $this->profile);

    expect($this->profile->fresh()->name)->toBe('Петя')
        ->and($this->profile->fresh()->waiting('name'))->toBeNull();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'Готово, Петя'));
});

it('rejects invalid names and keeps awaiting, leaving the name unchanged', function () {
    $this->profile->update(['name' => 'Старое']);

    $invalidInputs = [
        '123',                                                // только цифры
        '/start',                                             // команда
        '   ',                                                // пусто после trim
        str_repeat('я', 50),                                  // длиннее 32
    ];

    foreach ($invalidInputs as $bad) {
        app(HandleCommand::class)->handle(['message' => ['text' => '/name']], $this->profile);
        expect($this->profile->fresh()->waiting('name'))->toBeTrue();

        app(HandleQuestion::class)->handle(['message' => ['text' => $bad]], $this->profile);

        // Число проверяем через HandleNumbers — оно тоже вызывает SettingInput::intercept.
        expect($this->profile->fresh()->name)->toBe('Старое')
            ->and($this->profile->fresh()->waiting('name'))->toBeTrue();
    }

    // Чисто числовой ввод приходит роутером в HandleNumbers — тот же перехват.
    app(HandleNumbers::class)->handle(['message' => ['text' => '456']], $this->profile);
    expect($this->profile->fresh()->name)->toBe('Старое')
        ->and($this->profile->fresh()->waiting('name'))->toBeTrue();
});

it('does not intercept as a name when awaiting is stale (last outgoing is not a name request)', function () {
    // Ожидание имени осталось, но бот успел прислать другой запрос.
    $this->profile->update(['name' => 'Глеб']);
    $this->profile->setWaiting('name', true);
    NutritionMessage::query()->create([
        'profile_id' => $this->profile->id,
        'direction' => 'out',
        'kind' => 'metrics_request',
        'content' => 'Шаги и вода?',
    ]);

    app(HandleQuestion::class)->handle(['message' => ['text' => 'Петя']], $this->profile);

    // Устаревший awaiting сброшен, имя не тронуто, сообщение ушло дальше по обычной логике.
    expect($this->profile->fresh()->name)->toBe('Глеб')
        ->and($this->profile->fresh()->waiting('name'))->toBeNull();
});

it('resets awaiting_name when any command arrives', function () {
    app(HandleCommand::class)->handle(['message' => ['text' => '/name']], $this->profile);
    expect($this->profile->fresh()->waiting('name'))->toBeTrue();

    app(HandleCommand::class)->handle(['message' => ['text' => '/today']], $this->profile);

    expect($this->profile->fresh()->waiting('name'))->toBeNull();
});

it('addresses the client by the new name in the next reply', function () {
    app(HandleCommand::class)->handle(['message' => ['text' => '/name Пётр']], $this->profile);
    expect($this->profile->fresh()->name)->toBe('Пётр');

    // Обычный вопрос: ответ ИИ префиксуется обращением по новому имени (Address::ensure).
    app(HandleQuestion::class)->handle(['message' => ['text' => 'что съесть на ужин?']], $this->profile);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org')
        && str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'Пётр'));
});
