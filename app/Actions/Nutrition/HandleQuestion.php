<?php

namespace App\Actions\Nutrition;

use App\Support\Nutrition\Claude;
use App\Support\Nutrition\PromptBuilder;
use App\Support\Nutrition\SettingInput;
use App\Support\Nutrition\TelegramClient;
use Carbon\CarbonImmutable;

class HandleQuestion
{
    public function handle(array $update): void
    {
        // Ожидание значения настройки (например, время ЧЧ:ММ) перехватываем до модели.
        if (SettingInput::intercept($update)) {
            return;
        }

        $tg = app(TelegramClient::class);
        $text = trim((string) ($update['message']['text'] ?? ''));
        $today = CarbonImmutable::now('Europe/Moscow');

        $answer = Claude::text(
            [['type' => 'text', 'text' => PromptBuilder::dayContext($today)."\n\nВопрос Глеба: ".$text]],
            (string) config('nutrition.models.chat'),
            800,
        );

        $tg->send($answer ?? 'Не смог сейчас ответить, попробуй ещё раз чуть позже 🙏');
    }
}
