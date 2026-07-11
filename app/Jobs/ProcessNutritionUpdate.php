<?php

namespace App\Jobs;

use App\Actions\Nutrition\HandleCallback;
use App\Actions\Nutrition\HandleCommand;
use App\Actions\Nutrition\HandleNumbers;
use App\Actions\Nutrition\HandlePhoto;
use App\Actions\Nutrition\HandleQuestion;
use App\Models\NutritionMessage;
use App\Support\Nutrition\TelegramClient;
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
            $fromId = $this->update['callback_query']['from']['id']
                ?? $this->update['message']['from']['id']
                ?? null;

            if ($fromId === null) {
                return;
            }

            $configuredChatId = config('nutrition.chat_id');

            // Ещё не настроенный бот: фиксируем кандидата в логах и подсказываем, что делать.
            if (blank($configuredChatId)) {
                Log::info('nutrition: chat candidate', ['id' => $fromId]);

                app(TelegramClient::class)->api('sendMessage', [
                    'chat_id' => $fromId,
                    'text' => 'Привет! Твой ID зафиксирован в логах — координатор добавит его в настройки.',
                ]);

                return;
            }

            // Чужой отправитель: вежливо отказываем, ничего не логируем в nutrition_messages.
            if ((int) $fromId !== (int) $configuredChatId) {
                app(TelegramClient::class)->api('sendMessage', [
                    'chat_id' => $fromId,
                    'text' => 'Это персональный бот 🙂',
                ]);

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
