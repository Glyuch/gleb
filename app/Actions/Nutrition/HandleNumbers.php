<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use App\Support\Nutrition\Fmt;
use App\Support\Nutrition\SettingInput;
use App\Support\Nutrition\TelegramClient;
use App\Support\Nutrition\Tg;
use Carbon\CarbonImmutable;

class HandleNumbers
{
    public function handle(array $update): void
    {
        // Ожидание значения настройки перехватываем ДО контекстов weight/metrics.
        if (SettingInput::intercept($update)) {
            return;
        }

        $tg = app(TelegramClient::class);
        $chatId = Tg::chatId($update);
        $text = (string) ($update['message']['text'] ?? '');

        preg_match_all('/\d+(?:[.,]\d+)?/', $text, $matches);
        $numbers = $matches[0];

        if ($numbers === []) {
            app(HandleQuestion::class)->handle($update);

            return;
        }

        $lastOutKind = NutritionMessage::query()
            ->where('direction', 'out')
            ->orderByDesc('id')
            ->value('kind');

        $today = CarbonImmutable::now('Europe/Moscow')->format('Y-m-d');

        if ($lastOutKind === 'weight_request') {
            $weight = (float) str_replace(',', '.', $numbers[0]);
            if ($weight < 40 || $weight > 150) {
                $tg->send('Вес вне диапазона. Пришли значение в кг, например 82.3', chatId: $chatId);

                return;
            }

            NutritionMetric::query()->updateOrCreate(
                ['date' => $today, 'type' => 'weight'],
                ['value' => $weight],
            );

            $tg->send('Записал: вес '.Fmt::num($weight).' кг 👌🏻', chatId: $chatId);

            return;
        }

        if ($lastOutKind === 'metrics_request') {
            $steps = (int) round((float) str_replace(',', '.', $numbers[0]));
            if ($steps < 0 || $steps > 100000) {
                $tg->send('Шаги вне диапазона, проверь число 🙏', chatId: $chatId);

                return;
            }

            NutritionMetric::query()->updateOrCreate(
                ['date' => $today, 'type' => 'steps'],
                ['value' => $steps],
            );

            $reply = 'Записал шаги: '.$steps.' 👌🏻';

            if (isset($numbers[1])) {
                $water = (float) str_replace(',', '.', $numbers[1]);
                if ($water > 0 && $water <= 10) {
                    NutritionMetric::query()->updateOrCreate(
                        ['date' => $today, 'type' => 'water'],
                        ['value' => $water],
                    );
                    $reply .= ', вода '.Fmt::num($water).' л';
                }
            }

            $tg->send($reply, chatId: $chatId);

            return;
        }

        app(HandleQuestion::class)->handle($update);
    }
}
