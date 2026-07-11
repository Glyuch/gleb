<?php

namespace App\Support\Nutrition;

use App\Models\NutritionMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramClient
{
    private ?string $token;

    private mixed $chatId;

    public function __construct()
    {
        $this->token = config('nutrition.bot_token');
        $this->chatId = config('nutrition.chat_id');
    }

    /**
     * Отправляет текстовое сообщение (HTML) и логирует его в nutrition_messages.
     *
     * @param  array<int, array<int, array<string, string>>>|null  $inlineKeyboard  ряды inline-кнопок
     * @param  int|null  $chatId  чат назначения; null → основной чат из конфига
     */
    public function send(string $text, ?array $inlineKeyboard = null, string $kind = 'text', ?int $chatId = null): void
    {
        $target = $chatId ?? $this->chatId;

        if (blank($target)) {
            Log::info('Nutrition TelegramClient: chat_id пуст, сообщение не отправлено.', ['kind' => $kind]);

            return;
        }

        $params = [
            'chat_id' => $target,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($inlineKeyboard !== null) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        $result = $this->api('sendMessage', $params);

        NutritionMessage::query()->create([
            'direction' => 'out',
            'kind' => $kind,
            'content' => $text,
            'telegram_message_id' => $result['message_id'] ?? null,
        ]);
    }

    /**
     * Отправляет документ (multipart) и логирует его как kind=topic.
     *
     * @param  int|null  $chatId  чат назначения; null → основной чат из конфига
     */
    public function sendDocument(string $absolutePath, ?string $caption = null, ?int $chatId = null): void
    {
        $target = $chatId ?? $this->chatId;

        if (blank($target)) {
            Log::info('Nutrition TelegramClient: chat_id пуст, документ не отправлен.');

            return;
        }

        $params = ['chat_id' => $target];
        if ($caption !== null) {
            $params['caption'] = $caption;
            $params['parse_mode'] = 'HTML';
        }

        $result = null;
        try {
            $response = Http::timeout(30)
                ->attach('document', file_get_contents($absolutePath), basename($absolutePath))
                ->post($this->endpoint('sendDocument'), $params);

            if ($response->successful() && $response->json('ok') === true) {
                $result = $response->json('result');
            } else {
                Log::warning('Nutrition TelegramClient: sendDocument неуспешен.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Nutrition TelegramClient: sendDocument исключение.', ['message' => $e->getMessage()]);
        }

        NutritionMessage::query()->create([
            'direction' => 'out',
            'kind' => 'topic',
            'content' => $caption,
            'telegram_message_id' => $result['message_id'] ?? null,
        ]);
    }

    /**
     * Подтверждает callback-запрос (убирает "часики" на кнопке).
     */
    public function answerCallback(string $callbackQueryId): void
    {
        $this->api('answerCallbackQuery', ['callback_query_id' => $callbackQueryId]);
    }

    /**
     * Скачивает фото по file_id и возвращает base64-представление, либо null при ошибке.
     *
     * @return array{media_type: string, data: string}|null
     */
    public function downloadPhotoBase64(string $fileId): ?array
    {
        $file = $this->api('getFile', ['file_id' => $fileId]);
        $filePath = $file['file_path'] ?? null;

        if ($filePath === null) {
            return null;
        }

        try {
            $url = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";
            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                Log::warning('Nutrition TelegramClient: скачивание файла неуспешно.', ['status' => $response->status()]);

                return null;
            }

            return [
                'media_type' => 'image/jpeg',
                'data' => base64_encode($response->body()),
            ];
        } catch (Throwable $e) {
            Log::warning('Nutrition TelegramClient: скачивание файла исключение.', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Общий вызов Bot API. Возвращает поле result, только если это массив, иначе null.
     * Никогда не бросает исключений.
     *
     * @param  array<string, mixed>  $params
     * @return array<mixed>|null
     */
    public function api(string $method, array $params = []): ?array
    {
        $result = $this->apiRaw($method, $params);

        return is_array($result) ? $result : null;
    }

    /**
     * Вызов Bot API с результатом «как есть»: Telegram для некоторых методов
     * (setWebhook и т.п.) возвращает boolean в поле result. При HTTP-ошибке или
     * исключении — null. Никогда не бросает исключений.
     *
     * @param  array<string, mixed>  $params
     */
    public function apiRaw(string $method, array $params = []): mixed
    {
        try {
            $response = Http::timeout(30)
                ->retry(2, 500, throw: false)
                ->post($this->endpoint($method), $params);

            if ($response->successful() && $response->json('ok') === true) {
                return $response->json('result');
            }

            Log::warning('Nutrition TelegramClient: вызов Bot API неуспешен.', [
                'method' => $method,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('Nutrition TelegramClient: вызов Bot API исключение.', [
                'method' => $method,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function endpoint(string $method): string
    {
        return "https://api.telegram.org/bot{$this->token}/{$method}";
    }
}
