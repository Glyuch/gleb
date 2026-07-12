<?php

namespace App\Actions\Nutrition;

use App\Support\Nutrition\Claude;
use App\Support\Nutrition\MealIntent;
use App\Support\Nutrition\MealLogger;
use App\Support\Nutrition\PromptBuilder;
use App\Support\Nutrition\SettingInput;
use App\Support\Nutrition\TelegramClient;
use App\Support\Nutrition\Tg;
use Carbon\CarbonImmutable;

class HandleQuestion
{
    public function handle(array $update): void
    {
        // Ожидание значения настройки/времени приёма перехватываем до модели.
        if (SettingInput::intercept($update)) {
            return;
        }

        $tg = app(TelegramClient::class);
        $chatId = Tg::chatId($update);
        $text = trim((string) ($update['message']['text'] ?? ''));
        $now = CarbonImmutable::now('Europe/Moscow');

        $intent = MealIntent::classify($text, $now);

        // ИИ недоступен/невалидный JSON → фолбэк: обычный ответ на вопрос.
        if ($intent === null) {
            $answer = Claude::text(
                [['type' => 'text', 'text' => PromptBuilder::dayContext($now)."\n\nВопрос Глеба: ".$text]],
                (string) config('nutrition.models.chat'),
                800,
            );

            $tg->send($answer ?? 'Не смог сейчас ответить, попробуй ещё раз чуть позже 🙏', chatId: $chatId);

            return;
        }

        // Отчёт о еде — записываем приёмы; иначе отправляем ответ ИИ.
        if ($intent['intent'] === 'meal_report' && $intent['reports'] !== []) {
            MealLogger::logReports($update, $now, $intent['reports'], $intent['reply']);

            return;
        }

        $tg->send($intent['reply'] !== '' ? $intent['reply'] : 'Не смог сейчас ответить, попробуй ещё раз чуть позже 🙏', chatId: $chatId);
    }
}
