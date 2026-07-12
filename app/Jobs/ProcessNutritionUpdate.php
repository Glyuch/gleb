<?php

namespace App\Jobs;

use App\Actions\Nutrition\HandleCallback;
use App\Actions\Nutrition\HandleCommand;
use App\Actions\Nutrition\HandleNumbers;
use App\Actions\Nutrition\HandlePhoto;
use App\Actions\Nutrition\HandleQuestion;
use App\Actions\Nutrition\Onboarding;
use App\Models\NutritionInvite;
use App\Models\NutritionMessage;
use App\Models\NutritionProfile;
use App\Support\Nutrition\ProfileContext;
use App\Support\Nutrition\TelegramClient;
use App\Support\Nutrition\Tg;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessNutritionUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  array<string, mixed>  $update
     */
    public function __construct(public array $update) {}

    public function handle(): void
    {
        try {
            // Бота добавили в группу: приветствуем прямо в чат события и выходим.
            if ($this->handleNewChatMembers()) {
                return;
            }

            $profile = ProfileContext::resolve($this->update);
            $chatId = Tg::chatId($this->update);
            $fromId = $this->update['callback_query']['from']['id']
                ?? $this->update['message']['from']['id']
                ?? null;

            if ($fromId === null) {
                return;
            }

            // Неизвестный отправитель: в группе молчим, в личке — инвайт-подсказка
            // (с попыткой погасить инвайт-код, если прислан).
            if ($profile === null) {
                if (! $this->isGroup()) {
                    $this->handleUnknownPrivate((int) $fromId, $chatId);
                }

                return;
            }

            // Профиль на паузе: в группе молчим (не мешаем чужой беседе), в личке —
            // короткое уведомление, без обработки.
            if ($profile->status === 'paused') {
                if (! $this->isGroup()) {
                    app(TelegramClient::class)->api('sendMessage', [
                        'chat_id' => $chatId ?? $fromId,
                        'text' => 'Профиль на паузе 💤',
                    ]);
                }

                return;
            }

            $tg = app(TelegramClient::class);
            $tg->profileId = $profile->id;

            $this->logIncoming($profile);
            $this->route($profile);
        } catch (Throwable $e) {
            Log::error('nutrition: ProcessNutritionUpdate failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Личка от неизвестного: если прислан похожий на инвайт-код — пробуем погасить
     * (валидный → создаём onboarding-профиль, заглушка про онбординг; невалидный —
     * вежливый отказ). Иначе — подсказка про инвайт-код.
     */
    private function handleUnknownPrivate(int $fromId, ?int $chatId): void
    {
        $target = $chatId ?? $fromId;
        $text = trim((string) ($this->update['message']['text'] ?? ''));

        if (preg_match('/^[A-Za-z0-9]{6}$/', $text)) {
            $this->redeemInvite(strtoupper($text), $fromId, $target);

            return;
        }

        app(TelegramClient::class)->api('sendMessage', [
            'chat_id' => $target,
            'text' => 'Это персональный бот. Есть инвайт-код? Пришли его 🙂',
        ]);
    }

    /**
     * Гасит инвайт-код: при валидном (существующий, ещё не использованный) создаёт
     * onboarding-профиль отправителя, помечает инвайт использованным и запускает
     * анкету онбординга. Невалидный — вежливый отказ.
     */
    private function redeemInvite(string $code, int $fromId, int $target): void
    {
        $tg = app(TelegramClient::class);

        $invite = NutritionInvite::query()
            ->where('code', $code)
            ->whereNull('used_by_profile_id')
            ->first();

        if ($invite === null) {
            $tg->api('sendMessage', [
                'chat_id' => $target,
                'text' => 'Код не подошёл 🙈 Проверь его или попроси новый.',
            ]);

            return;
        }

        $from = $this->update['message']['from'] ?? [];

        $profile = NutritionProfile::query()->create([
            'telegram_user_id' => $fromId,
            'name' => (string) ($from['first_name'] ?? 'Друг'),
            'username' => $from['username'] ?? null,
            'main_chat_id' => $target,
            'status' => 'onboarding',
        ]);

        $invite->update([
            'used_by_profile_id' => $profile->id,
            'used_at' => CarbonImmutable::now('Europe/Moscow'),
        ]);

        Log::info('nutrition: invite redeemed', ['profile_id' => $profile->id]);

        app(Onboarding::class)->start($profile, $target);
    }

    /**
     * Обрабатывает событие new_chat_members. Возвращает true, если событие
     * поглощено (бот добавлен → приветствие; либо вход участников без бота).
     */
    private function handleNewChatMembers(): bool
    {
        $members = $this->update['message']['new_chat_members'] ?? null;

        if (! is_array($members)) {
            return false;
        }

        $botId = $this->botId();
        $chatId = $this->update['message']['chat']['id'] ?? null;

        foreach ($members as $member) {
            if ($botId !== 0 && (int) ($member['id'] ?? 0) === $botId) {
                $adder = ProfileContext::resolve($this->update);

                // Приветствуем, если бота добавил владелец профиля ЛИБО инстанс ещё
                // не настроен (bootstrap: профилей нет). Чужие добавления — молча.
                $bootstrap = NutritionProfile::query()->doesntExist();
                if ($adder === null && ! $bootstrap) {
                    return true;
                }

                $tg = app(TelegramClient::class);
                $tg->profileId = $adder?->id;

                $tg->api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "Привет! Я — персональный нутрициолог 🙌🏼\n\n"
                        .'Разбираю приёмы пищи по фото, веду вес, шаги и воду, отвечаю на вопросы по питанию.'."\n\n"
                        .'Чтобы начать — отправьте /start.',
                ]);

                Log::info('nutrition: added to chat', ['chat_id' => $chatId]);

                // Владельцу предлагаем сделать этот чат основным (callback — Task 4).
                if ($adder !== null && $chatId !== null) {
                    $tg->send(
                        'Сделать этот чат основным?',
                        [[
                            ['text' => 'Да', 'callback_data' => 'chatmain:yes'],
                            ['text' => 'Нет', 'callback_data' => 'chatmain:no'],
                        ]],
                        chatId: (int) $chatId,
                    );
                }

                return true;
            }
        }

        // Вход участников без бота — служебное событие, молча игнорируем.
        return true;
    }

    /**
     * ID бота = часть токена до двоеточия. 0, если токен не задан/некорректен.
     */
    private function botId(): int
    {
        return (int) explode(':', (string) config('nutrition.bot_token'))[0];
    }

    /**
     * Групповой чат-источник (group/supergroup)?
     */
    private function isGroup(): bool
    {
        $type = $this->update['callback_query']['message']['chat']['type']
            ?? $this->update['message']['chat']['type']
            ?? null;

        return in_array($type, ['group', 'supergroup'], true);
    }

    private function logIncoming(NutritionProfile $profile): void
    {
        $message = $this->update['message'] ?? null;
        $callback = $this->update['callback_query'] ?? null;

        if ($callback !== null) {
            $kind = 'callback';
            $content = $callback['data'] ?? null;
            $telegramMessageId = $callback['message']['message_id'] ?? null;
        } elseif (isset($message['photo'])) {
            $kind = 'photo';
            $content = $message['caption'] ?? null;
            $telegramMessageId = $message['message_id'] ?? null;
        } elseif (isset($message['text']) && str_starts_with($message['text'], '/')) {
            $kind = 'command';
            $content = $message['text'];
            $telegramMessageId = $message['message_id'] ?? null;
        } else {
            $kind = 'text';
            $content = $message['text'] ?? null;
            $telegramMessageId = $message['message_id'] ?? null;
        }

        NutritionMessage::query()->create([
            'profile_id' => $profile->id,
            'direction' => 'in',
            'kind' => $kind,
            'content' => $content,
            'telegram_message_id' => $telegramMessageId,
            'meta' => array_filter([
                'message' => $message,
                'callback_query' => $callback,
                'chat_id' => Tg::chatId($this->update),
            ], fn ($value) => $value !== null),
        ]);
    }

    private function route(NutritionProfile $profile): void
    {
        $update = $this->update;

        if (isset($update['callback_query'])) {
            app(HandleCallback::class)->handle($update, $profile);

            return;
        }

        // Профиль на онбординге: весь свободный текст ведёт анкету, мимо обычной
        // маршрутизации (MealIntent/вопросы/числа). Фото и команды — особый разбор.
        if ($profile->status === 'onboarding') {
            $this->routeOnboarding($profile);

            return;
        }

        $message = $update['message'] ?? [];

        if (isset($message['photo'])) {
            app(HandlePhoto::class)->handle($update, $profile);

            return;
        }

        $text = $message['text'] ?? '';

        if (str_starts_with($text, '/')) {
            app(HandleCommand::class)->handle($update, $profile);

            return;
        }

        if ($text !== '' && preg_match('/\d/', $text) && preg_match('/^[\d\s,.]+$/', $text)) {
            app(HandleNumbers::class)->handle($update, $profile);

            return;
        }

        app(HandleQuestion::class)->handle($update, $profile);
    }

    /**
     * Маршрутизация во время онбординга: фото — мягкое «сначала закончим»;
     * /help — обычная справка; /start — повтор текущего вопроса; прочие команды —
     * «после онбординга»; любой другой текст — ответ на текущий вопрос анкеты.
     */
    private function routeOnboarding(NutritionProfile $profile): void
    {
        $message = $this->update['message'] ?? [];
        $chatId = Tg::chatId($this->update);

        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;

        if (isset($message['photo'])) {
            $tg->send('Сначала закончим знакомство 🙂 Ответь, пожалуйста, на вопрос выше — фото разберём потом.', chatId: $chatId);

            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if (str_starts_with($text, '/')) {
            $command = strtolower((string) (preg_split('/\s+/', $text)[0] ?? ''));

            if ($command === '/help') {
                app(HandleCommand::class)->handle($this->update, $profile);

                return;
            }

            if ($command === '/start') {
                app(Onboarding::class)->repeat($profile, $chatId);

                return;
            }

            $tg->send('Это будет доступно после онбординга 🙂 Сейчас закончим пару вопросов.', chatId: $chatId);

            return;
        }

        app(Onboarding::class)->answer($profile, $text, $chatId);
    }
}
