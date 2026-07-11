<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionSetting;
use App\Support\Nutrition\MealPlan;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\Settings;
use App\Support\Nutrition\TelegramClient;
use Carbon\CarbonImmutable;

class HandleCallback
{
    public function handle(array $update): void
    {
        $tg = app(TelegramClient::class);
        $callback = $update['callback_query'] ?? [];
        $data = (string) ($callback['data'] ?? '');
        $callbackId = (string) ($callback['id'] ?? '');

        [$action, $arg] = array_pad(explode(':', $data, 2), 2, '');

        match ($action) {
            'ate' => $this->ate($tg, $arg),
            'skip' => $this->skip($tg, $arg),
            'adj' => $this->adjust($tg, $arg),
            default => $tg->send('Не понял действие 🤔'),
        };

        if ($callbackId !== '') {
            $tg->answerCallback($callbackId);
        }
    }

    private function ate(TelegramClient $tg, string $type): void
    {
        $meal = $this->meal($type);
        if ($meal === null) {
            $tg->send('Не нашёл такой приём на сегодня 🤔');

            return;
        }

        Planner::markEaten($meal, CarbonImmutable::now('Europe/Moscow'), null, null);
        $tg->send(MealPlan::LABELS[$type].' отмечен ✅');
    }

    private function skip(TelegramClient $tg, string $type): void
    {
        $meal = $this->meal($type);
        if ($meal === null) {
            $tg->send('Не нашёл такой приём на сегодня 🤔');

            return;
        }

        $meal->update(['status' => 'skipped']);
        Planner::recalculate(CarbonImmutable::now('Europe/Moscow')->startOfDay());
        $tg->send(MealPlan::LABELS[$type].' пропущен ⏭');
    }

    private function adjust(TelegramClient $tg, string $decision): void
    {
        if ($decision === 'yes') {
            $pending = Settings::get('pending_adjustments');
            if (is_array($pending)) {
                foreach (['steps_target', 'portion_adjustment', 'sleep_time'] as $key) {
                    if (array_key_exists($key, $pending)) {
                        Settings::set($key, $pending[$key]);
                    }
                }
            }
            $this->clearPending();
            $tg->send('Готово, обновил настройки 👌🏻');

            return;
        }

        $this->clearPending();
        $tg->send('Ок, оставляем как есть 👌🏻');
    }

    /**
     * Очищает pending_adjustments. Столбец value NOT NULL, поэтому «пусто»
     * выражается отсутствием строки — тогда Settings::get вернёт дефолт null.
     */
    private function clearPending(): void
    {
        NutritionSetting::query()->where('key', 'pending_adjustments')->delete();
    }

    private function meal(string $type): ?NutritionMeal
    {
        if (! in_array($type, MealPlan::TYPES, true)) {
            return null;
        }

        $now = CarbonImmutable::now('Europe/Moscow');
        Planner::ensureDay($now);

        return NutritionMeal::query()
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('type', $type)
            ->first();
    }
}
