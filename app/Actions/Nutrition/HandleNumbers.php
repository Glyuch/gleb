<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use App\Models\NutritionProfile;
use App\Support\Nutrition\Fmt;
use App\Support\Nutrition\PendingRequest;
use App\Support\Nutrition\SettingInput;
use App\Support\Nutrition\TelegramClient;
use App\Support\Nutrition\Tg;
use Carbon\CarbonImmutable;

class HandleNumbers
{
    public function handle(array $update, NutritionProfile $profile): void
    {
        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;

        // Ожидание значения настройки перехватываем ДО контекстов weight/metrics
        // (со своим setting_request-guard внутри intercept).
        if (SettingInput::intercept($update, $profile)) {
            return;
        }

        $chatId = Tg::chatId($update);
        $text = (string) ($update['message']['text'] ?? '');

        preg_match_all('/\d+(?:[.,]\d+)?/', $text, $matches);
        $numbers = $matches[0];

        if ($numbers === []) {
            app(HandleQuestion::class)->handle($update, $profile);

            return;
        }

        $now = CarbonImmutable::now('Europe/Moscow');
        $today = $now->format('Y-m-d');

        // lastOutKind — быстрый путь (в т.ч. для program:start-ветки, где
        // weight_request шлётся колбэком без sent_event); PendingRequest —
        // устойчивый контекст на случай, когда после запроса ушли ещё сообщения.
        $lastOutKind = NutritionMessage::query()
            ->where('profile_id', $profile->id)
            ->where('direction', 'out')
            ->orderByDesc('id')
            ->value('kind');

        if ($lastOutKind === 'weight_request' || PendingRequest::expectsWeight($profile, $now)) {
            $weight = (float) str_replace(',', '.', $numbers[0]);
            if ($weight < 40 || $weight > 150) {
                $tg->send('Вес вне диапазона. Пришли значение в кг, например 82.3', chatId: $chatId);

                return;
            }

            NutritionMetric::query()->updateOrCreate(
                ['profile_id' => $profile->id, 'date' => $today, 'type' => 'weight'],
                ['value' => $weight],
            );

            $tg->send('Записал: вес '.Fmt::num($weight).' кг 👌🏻', chatId: $chatId);

            return;
        }

        if ($lastOutKind === 'metrics_request' || PendingRequest::expectsMetrics($profile, $now)) {
            $steps = (int) round((float) str_replace(',', '.', $numbers[0]));
            if ($steps < 0 || $steps > 100000) {
                $tg->send('Шаги вне диапазона, проверь число 🙏', chatId: $chatId);

                return;
            }

            NutritionMetric::query()->updateOrCreate(
                ['profile_id' => $profile->id, 'date' => $today, 'type' => 'steps'],
                ['value' => $steps],
            );

            $reply = 'Записал шаги: '.$steps.' 👌🏻';

            if (isset($numbers[1])) {
                $water = (float) str_replace(',', '.', $numbers[1]);
                if ($water > 0 && $water <= 10) {
                    NutritionMetric::query()->updateOrCreate(
                        ['profile_id' => $profile->id, 'date' => $today, 'type' => 'water'],
                        ['value' => $water],
                    );
                    $reply .= ', вода '.Fmt::num($water).' л';
                }
            }

            $tg->send($reply, chatId: $chatId);

            return;
        }

        app(HandleQuestion::class)->handle($update, $profile);
    }
}
