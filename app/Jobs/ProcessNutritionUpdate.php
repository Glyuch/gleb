<?php

namespace App\Jobs;

use App\Actions\Nutrition\HandleCallback;
use App\Actions\Nutrition\HandleCommand;
use App\Actions\Nutrition\HandleNumbers;
use App\Actions\Nutrition\HandlePhoto;
use App\Actions\Nutrition\HandleQuestion;
use App\Models\NutritionMessage;
use App\Support\Nutrition\TelegramClient;
use App\Support\Nutrition\Tg;
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
            // Бота добавили в группу: приветствуем прямо в чат события
            // (chat_id может быть ещё не настроен) и выходим.
            if ($this->handleNewChatMembers()) {
                return;
            }

            $fromId = $this->update['callback_query']['from']['id']
                ?? $this->update['message']['from']['id']
                ?? null;

            if ($fromId === null) {
                return;
            }

            $configuredUserId = config('nutrition.user_id');
            $configuredChatId = config('nutrition.chat_id');

            // Ещё не настроенный бот: ни владельца, ни основного чата —
            // фиксируем кандидата в логах и подсказываем, что делать.
            if (blank($configuredUserId) && blank($configuredChatId)) {
                Log::info('nutrition: chat candidate', ['id' => $fromId]);

                app(TelegramClient::class)->api('sendMessage', [
                    'chat_id' => $fromId,
                    'text' => 'Привет! Твой ID зафиксирован в логах — координатор добавит его в настройки.',
                ]);

                return;
            }

            // Владелец — user_id, если задан; иначе обратная совместимость по chat_id (личка).
            $ownerId = blank($configuredUserId) ? $configuredChatId : $configuredUserId;

            // Чужой отправитель: в группе игнорируем молча, в личке — вежливый отказ.
            if ((int) $fromId !== (int) $ownerId) {
                if (! $this->isGroup()) {
                    app(TelegramClient::class)->api('sendMessage', [
                        'chat_id' => $fromId,
                        'text' => 'Это персональный бот 🙂',
                    ]);
                }

                return;
            }

            $this->logIncoming();
            $this->route();
        } catch (Throwable $e) {
            Log::error('nutrition: ProcessNutritionUpdate failed', [
                'message' => $e->getMessage(),
            ]);
        }
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
                app(TelegramClient::class)->api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "Привет! Я — персональный нутрициолог Глеба 🙌🏼\n\n"
                        ."Разбираю приёмы пищи по фото, веду вес, шаги и воду, отвечаю на вопросы по питанию.\n\n"
                        .'Чтобы начать — отправьте /start.',
                ]);

                Log::info('nutrition: added to chat', ['chat_id' => $chatId]);

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

    private function logIncoming(): void
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

    private function route(): void
    {
        $update = $this->update;

        if (isset($update['callback_query'])) {
            app(HandleCallback::class)->handle($update);

            return;
        }

        $message = $update['message'] ?? [];

        if (isset($message['photo'])) {
            app(HandlePhoto::class)->handle($update);

            return;
        }

        $text = $message['text'] ?? '';

        if (str_starts_with($text, '/')) {
            app(HandleCommand::class)->handle($update);

            return;
        }

        if ($text !== '' && preg_match('/\d/', $text) && preg_match('/^[\d\s,.]+$/', $text)) {
            app(HandleNumbers::class)->handle($update);

            return;
        }

        app(HandleQuestion::class)->handle($update);
    }
}
