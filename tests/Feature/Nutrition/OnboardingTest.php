<?php

use App\Actions\Nutrition\HandleCommand;
use App\Actions\Nutrition\Onboarding;
use App\Jobs\ProcessNutritionUpdate;
use App\Models\NutritionInvite;
use App\Models\NutritionProfile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'nutrition.chat_id' => 123,
        'nutrition.bot_token' => 'test-token',
        'nutrition.anthropic_key' => 'test-key',
        'nutrition.models.vision' => 'claude-haiku-4-5',
        'nutrition.models.chat' => 'claude-sonnet-5',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/bot*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/x.jpg']]),
        'api.telegram.org/file/*' => Http::response('BINARYIMAGE'),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        // Сжатие анкеты: эхо-фейк возвращает переданные ответы клиента, чтобы
        // ai_profile отражал ввод именно этого пользователя (проверка изоляции).
        'api.anthropic.com/*' => function ($request) {
            $text = $request->data()['messages'][0]['content'][0]['text'] ?? '';

            return Http::response(['content' => [['type' => 'text', 'text' => "СВОДКА\n".$text]]]);
        },
    ]);
});

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function onbUpdate(int $fromId, string $text, array $extra = []): array
{
    return ['message' => array_merge([
        'from' => ['id' => $fromId, 'first_name' => 'Аня'],
        'chat' => ['id' => $fromId, 'type' => 'private'],
        'text' => $text,
    ], $extra)];
}

function onbProfile(array $attrs = []): NutritionProfile
{
    return nutritionProfile(array_merge([
        'telegram_user_id' => 555,
        'name' => 'Аня',
        'is_admin' => false,
        'main_chat_id' => 555,
        'status' => 'onboarding',
    ], $attrs));
}

it('generates an invite for an admin and refuses a non-admin', function () {
    $admin = nutritionProfile(['telegram_user_id' => 1, 'is_admin' => true]);
    app(HandleCommand::class)->handle(['message' => ['text' => '/invite']], $admin);

    expect(NutritionInvite::query()->where('created_by_profile_id', $admin->id)->count())->toBe(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'Код:'));

    $guest = nutritionProfile(['telegram_user_id' => 2, 'is_admin' => false]);
    app(HandleCommand::class)->handle(['message' => ['text' => '/invite']], $guest);

    expect(NutritionInvite::query()->where('created_by_profile_id', $guest->id)->count())->toBe(0);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'только владелец'));
});

it('walks the full 7-step questionnaire into an active profile with an ai_profile and start button', function () {
    $profile = onbProfile();
    app(Onboarding::class)->start($profile, 555);

    $answers = [
        'Аня, хочу снизить вес',
        'Берлин',
        '70 кг, 165 см, 30 лет',
        '08:00 и 22:00',
        'бег 3 раза в неделю',
        'не ем рыбу',
        'иногда повышается давление',
    ];
    foreach ($answers as $text) {
        (new ProcessNutritionUpdate(onbUpdate(555, $text)))->handle();
    }

    $fresh = $profile->fresh();
    expect($fresh->status)->toBe('active')
        ->and($fresh->timezone)->toBe('Europe/Berlin')
        ->and($fresh->ai_profile)->not->toBeNull()
        ->and($fresh->ai_profile)->toContain('снизить вес')
        ->and($fresh->ai_profile)->toContain('Онбординг пройден')
        ->and($fresh->waiting('onboarding_step'))->toBeNull();

    Http::assertSent(function ($r) {
        return str_contains($r->url(), '/sendMessage')
            && isset($r['reply_markup'])
            && str_contains($r['reply_markup'], 'program:start');
    });
});

it('applies wake/sleep from step 4 into settings and shifts the breakfast window', function () {
    $profile = onbProfile();
    app(Onboarding::class)->start($profile, 555);

    (new ProcessNutritionUpdate(onbUpdate(555, 'Аня, энергия')))->handle();      // step 1 -> 2
    (new ProcessNutritionUpdate(onbUpdate(555, 'Москва')))->handle();            // step 2 (пояс) -> 3
    (new ProcessNutritionUpdate(onbUpdate(555, '70, 165, 30')))->handle();       // step 3 -> 4
    (new ProcessNutritionUpdate(onbUpdate(555, 'встаю 08:00, ложусь 22:00')))->handle(); // step 4

    $fresh = $profile->fresh();
    expect($fresh->setting('wake_time'))->toBe('08:00')
        ->and($fresh->setting('sleep_time'))->toBe('22:00')
        ->and($fresh->setting('default_windows')['breakfast'])->toBe(['start' => '08:30', 'end' => '09:30'])
        // Остальные окна остаются дефолтными.
        ->and($fresh->setting('default_windows')['lunch'])->toBe(['start' => '11:00', 'end' => '12:30']);
});

it('defaults the bedtime and notifies the user when step-4 bedtime is a daytime value', function () {
    $profile = onbProfile();
    app(Onboarding::class)->start($profile, 555);

    (new ProcessNutritionUpdate(onbUpdate(555, 'Аня, энергия')))->handle();      // step 1 -> 2
    (new ProcessNutritionUpdate(onbUpdate(555, 'Москва')))->handle();            // step 2 -> 3
    (new ProcessNutritionUpdate(onbUpdate(555, '70, 165, 30')))->handle();       // step 3 -> 4
    // Отбой 14:00 — дневной (абсурдный): Bedtime → reask, дефолт 23:00 сохраняется.
    (new ProcessNutritionUpdate(onbUpdate(555, 'встаю 08:00, ложусь 14:00')))->handle(); // step 4 -> 5

    $fresh = $profile->fresh();
    expect($fresh->setting('wake_time'))->toBe('08:00')
        ->and($fresh->setting('sleep_time'))->toBe('23:00')
        // Шаг завершился, анкета перешла на 5-й вопрос.
        ->and($fresh->waiting('onboarding_step'))->toBe(5);

    // Пользователю ушла пометка про дефолтный отбой.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'по умолчанию')
        && str_contains($r['text'], '/settings'));
});

it('sets the timezone at step 2 so later wake/sleep apply under it', function () {
    $profile = onbProfile();
    app(Onboarding::class)->start($profile, 555);

    (new ProcessNutritionUpdate(onbUpdate(555, 'Аня, энергия')))->handle();   // step 1 -> 2
    (new ProcessNutritionUpdate(onbUpdate(555, 'Берлин')))->handle();         // step 2 (пояс) -> 3

    expect($profile->fresh()->timezone)->toBe('Europe/Berlin');

    (new ProcessNutritionUpdate(onbUpdate(555, '70, 165, 30')))->handle();    // step 3 -> 4
    (new ProcessNutritionUpdate(onbUpdate(555, '08:00 22:00')))->handle();    // step 4 (режим дня)

    $fresh = $profile->fresh();
    expect($fresh->timezone)->toBe('Europe/Berlin')
        ->and($fresh->setting('wake_time'))->toBe('08:00')
        ->and($fresh->setting('sleep_time'))->toBe('22:00');
});

it('keeps the default Moscow timezone when the step-2 input is unrecognized', function () {
    $profile = onbProfile();
    app(Onboarding::class)->start($profile, 555);

    (new ProcessNutritionUpdate(onbUpdate(555, 'Аня, цель')))->handle();      // step 1 -> 2
    (new ProcessNutritionUpdate(onbUpdate(555, 'где-то там')))->handle();     // step 2 — мусор

    // Пояс остаётся дефолтным, анкета не застряла (перешла на шаг 3).
    expect($profile->fresh()->timezone)->toBe('Europe/Moscow')
        ->and($profile->fresh()->waiting('onboarding_step'))->toBe(3);
});

it('finishes on the skip button at the last step', function () {
    $profile = onbProfile();
    app(Onboarding::class)->start($profile, 555);

    foreach (['цель', 'Москва', 'параметры', '07:00 23:00', 'активность', 'ограничения'] as $text) {
        (new ProcessNutritionUpdate(onbUpdate(555, $text)))->handle(); // steps 1..6
    }

    // Нажатие «Пропустить» на последнем (7-м) шаге.
    (new ProcessNutritionUpdate(['callback_query' => [
        'id' => 'cb1',
        'from' => ['id' => 555],
        'data' => 'onboard:skip',
        'message' => ['chat' => ['id' => 555, 'type' => 'private'], 'message_id' => 9],
    ]]))->handle();

    $fresh = $profile->fresh();
    expect($fresh->status)->toBe('active')
        ->and($fresh->ai_profile)->not->toBeNull()
        ->and($fresh->ai_profile)->toContain('Онбординг пройден');
});

it('does not route an onboarding text message to MealIntent', function () {
    $profile = onbProfile();
    app(Onboarding::class)->start($profile, 555);

    (new ProcessNutritionUpdate(onbUpdate(555, 'Аня, цель')))->handle(); // step 1 -> 2

    // Сообщение-«еда» на шаге 2 (пояс) должно вести анкету, а не классифицироваться моделью.
    (new ProcessNutritionUpdate(onbUpdate(555, 'позавтракал омлетом и кашей')))->handle();

    // Никаких обращений к модели во время анкеты (сжатие только на финале).
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com'));
    // И задан следующий вопрос (параметры), а не разбор еды.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'вес, рост, возраст'));
    expect($profile->fresh()->waiting('onboarding_step'))->toBe(3);
});

it('softly declines a photo during onboarding', function () {
    $profile = onbProfile();
    app(Onboarding::class)->start($profile, 555);

    (new ProcessNutritionUpdate(onbUpdate(555, '', [
        'photo' => [['file_id' => 'AAA']],
    ])))->handle();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'закончим знакомство'));
    // Шаг анкеты не сдвинулся.
    expect($profile->fresh()->waiting('onboarding_step'))->toBe(1);
});

it('repeats the current question on /start and blocks other commands during onboarding', function () {
    $profile = onbProfile();
    app(Onboarding::class)->start($profile, 555);
    (new ProcessNutritionUpdate(onbUpdate(555, 'Аня, цель')))->handle(); // now on step 2 (пояс)

    (new ProcessNutritionUpdate(onbUpdate(555, '/start')))->handle();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'часовом поясе'));

    (new ProcessNutritionUpdate(onbUpdate(555, '/today')))->handle();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'после онбординга'));

    // /help всё же работает.
    (new ProcessNutritionUpdate(onbUpdate(555, '/help')))->handle();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'Команды'));

    expect($profile->fresh()->status)->toBe('onboarding');
});

it('produces different ai_profiles for two different users (isolation)', function () {
    $anya = onbProfile(['telegram_user_id' => 555, 'name' => 'Аня']);
    $boris = onbProfile(['telegram_user_id' => 666, 'name' => 'Борис', 'main_chat_id' => 666]);

    app(Onboarding::class)->start($anya, 555);
    app(Onboarding::class)->start($boris, 666);

    $anyaAnswers = ['Аня, снизить вес', 'Берлин', '60 кг, 160 см, 28 лет', '07:00 23:00', 'йога', 'без глютена', 'нет'];
    $borisAnswers = ['Борис, набрать массу', 'Ереван', '85 кг, 185 см, 35 лет', '06:00 22:00', 'зал 5 раз', 'ем всё', 'нет'];

    foreach ($anyaAnswers as $text) {
        (new ProcessNutritionUpdate(onbUpdate(555, $text)))->handle();
    }
    foreach ($borisAnswers as $text) {
        (new ProcessNutritionUpdate(onbUpdate(666, $text)))->handle();
    }

    $anyaProfile = $anya->fresh()->ai_profile;
    $borisProfile = $boris->fresh()->ai_profile;

    expect($anyaProfile)->not->toBe($borisProfile)
        ->and($anyaProfile)->toContain('снизить вес')
        ->and($anyaProfile)->not->toContain('набрать массу')
        ->and($borisProfile)->toContain('набрать массу')
        ->and($borisProfile)->not->toContain('снизить вес');
});

it('sets the main chat on chatmain:yes and leaves it on chatmain:no', function () {
    $admin = nutritionProfile(['telegram_user_id' => 777, 'main_chat_id' => 111]);
    $admin->setWaiting('chatmain_offer', -100500);

    (new ProcessNutritionUpdate(['callback_query' => [
        'id' => 'cb1',
        'from' => ['id' => 777],
        'data' => 'chatmain:yes',
        'message' => ['chat' => ['id' => -100500, 'type' => 'supergroup'], 'message_id' => 7],
    ]]))->handle();

    expect($admin->fresh()->main_chat_id)->toBe(-100500);

    // Новое предложение по другому чату; ответ «Нет» — основной чат не меняется.
    $admin->refresh();
    $admin->setWaiting('chatmain_offer', -100999);

    (new ProcessNutritionUpdate(['callback_query' => [
        'id' => 'cb2',
        'from' => ['id' => 777],
        'data' => 'chatmain:no',
        'message' => ['chat' => ['id' => -100999, 'type' => 'supergroup'], 'message_id' => 8],
    ]]))->handle();

    // «Нет» ничего не меняет.
    expect($admin->fresh()->main_chat_id)->toBe(-100500);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'оставил как было'));
});
