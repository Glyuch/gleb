<?php

namespace App\Support\Nutrition;

use App\Models\NutritionProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Claude
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * Отправляет запрос к Anthropic Messages API и возвращает конкатенацию всех
     * text-блоков ответа. Любая ошибка (HTTP, сеть, пустой ответ) → Log::warning + null;
     * исключения наружу не летят. Sampling-параметры (temperature/top_p/thinking) не передаются.
     *
     * @param  array<int, array<string, mixed>>  $userContent  содержимое user-сообщения (текст/изображения)
     * @param  NutritionProfile|null  $profile  профиль клиента — его ai_profile добавляется в system-промпт
     */
    public static function text(array $userContent, string $model, int $maxTokens = 1024, ?NutritionProfile $profile = null): ?string
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('nutrition.anthropic_key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout(90)
                ->retry(2, 2000, throw: false)
                ->post(self::ENDPOINT, [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'system' => PromptBuilder::system($profile),
                    'messages' => [
                        ['role' => 'user', 'content' => $userContent],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Nutrition Claude: запрос неуспешен.', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $blocks = $response->json('content');
            if (! is_array($blocks)) {
                Log::warning('Nutrition Claude: неожиданный ответ (нет content).', ['model' => $model]);

                return null;
            }

            $text = '';
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                }
            }

            if ($text === '') {
                Log::warning('Nutrition Claude: пустой текстовый ответ.', ['model' => $model]);

                return null;
            }

            return $text;
        } catch (Throwable $e) {
            Log::warning('Nutrition Claude: исключение при запросе.', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Обёртка над text() для анализа фото: user content = изображение + текстовый промпт,
     * модель — config('nutrition.models.vision').
     *
     * @param  array{media_type: string, data: string}  $image  base64-изображение
     */
    public static function vision(array $image, string $prompt, int $maxTokens = 400, ?NutritionProfile $profile = null): ?string
    {
        return self::text(
            [
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $image['media_type'],
                        'data' => $image['data'],
                    ],
                ],
                ['type' => 'text', 'text' => $prompt],
            ],
            (string) config('nutrition.models.vision'),
            $maxTokens,
            $profile,
        );
    }
}
