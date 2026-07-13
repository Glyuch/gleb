<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionProfile;
use App\Support\Nutrition\Address;
use App\Support\Nutrition\Claude;
use App\Support\Nutrition\MealIntent;
use App\Support\Nutrition\MealLogger;
use App\Support\Nutrition\PromptBuilder;
use App\Support\Nutrition\SettingInput;
use App\Support\Nutrition\TelegramClient;
use App\Support\Nutrition\Tg;

class HandleQuestion
{
    public function handle(array $update, NutritionProfile $profile): void
    {
        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;

        // Ожидание значения настройки/времени приёма перехватываем до модели.
        if (SettingInput::intercept($update, $profile)) {
            return;
        }

        $chatId = Tg::chatId($update);
        $text = trim((string) ($update['message']['text'] ?? ''));
        $now = $profile->now();

        $intent = MealIntent::classify($profile, $text, $now);

        // ИИ недоступен/невалидный JSON → фолбэк: обычный ответ на вопрос.
        if ($intent === null) {
            $answer = Claude::text(
                [['type' => 'text', 'text' => PromptBuilder::dayContext($profile, $now)."\n\nВопрос клиента: ".$text]],
                (string) config('nutrition.models.chat'),
                800,
                $profile,
            );

            $tg->send(Address::ensure($profile, $answer ?? 'Не смог сейчас ответить, попробуй ещё раз чуть позже 🙏'), chatId: $chatId);

            return;
        }

        // Отчёт о еде — записываем приёмы; иначе отправляем ответ ИИ.
        if ($intent['intent'] === 'meal_report' && $intent['reports'] !== []) {
            MealLogger::logReports($update, $profile, $now, $intent['reports'], $intent['reply']);

            return;
        }

        $tg->send(Address::ensure($profile, $intent['reply'] !== '' ? $intent['reply'] : 'Не смог сейчас ответить, попробуй ещё раз чуть позже 🙏'), chatId: $chatId);
    }
}
