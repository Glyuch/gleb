<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionSetting;
use App\Support\Nutrition\MealPlan;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\Settings;
use App\Support\Nutrition\TelegramClient;
use App\Support\Nutrition\Tg;
use Carbon\CarbonImmutable;

class HandleCallback
{
    public function handle(array $update): void
    {
        $tg = app(TelegramClient::class);
        $chatId = Tg::chatId($update);
        $callback = $update['callback_query'] ?? [];
        $data = (string) ($callback['data'] ?? '');
        $callbackId = (string) ($callback['id'] ?? '');

        [$action, $arg] = array_pad(explode(':', $data, 2), 2, '');

        match ($action) {
            'ate' => $this->ate($tg, $arg, $chatId),
            'skip' => $this->skip($tg, $arg, $chatId),
            'adj' => $this->adjust($tg, $arg, $chatId),
            'program' => $this->programStart($tg, $chatId),
            'set' => $this->setSetting($tg, $arg, $chatId),
            default => $tg->send('Не понял действие 🤔', chatId: $chatId),
        };

        if ($callbackId !== '') {
            $tg->answerCallback($callbackId);
        }
    }

    private function ate(TelegramClient $tg, string $type, ?int $chatId = null): void
    {
        $meal = $this->meal($type);
        if ($meal === null) {
            $tg->send('Не нашёл такой приём на сегодня 🤔', chatId: $chatId);

            return;
        }

        // Повторный/устаревший callback: не затираем eaten_at/фото/фидбек.
        if ($meal->status !== 'pending') {
            $tg->send('Этот приём уже отмечен 👌🏻', chatId: $chatId);

            return;
        }

        Planner::markEaten($meal, CarbonImmutable::now('Europe/Moscow'), null, null);
        $tg->send(MealPlan::LABELS[$type].' отмечен ✅', chatId: $chatId);
    }

    private function skip(TelegramClient $tg, string $type, ?int $chatId = null): void
    {
        $meal = $this->meal($type);
        if ($meal === null) {
            $tg->send('Не нашёл такой приём на сегодня 🤔', chatId: $chatId);

            return;
        }

        // Устаревший callback по уже отмеченному приёму — БД не трогаем.
        if ($meal->status !== 'pending') {
            $tg->send('Этот приём уже отмечен 👌🏻', chatId: $chatId);

            return;
        }

        $meal->update(['status' => 'skipped']);
        Planner::recalculate(CarbonImmutable::now('Europe/Moscow')->startOfDay());
        $tg->send(MealPlan::LABELS[$type].' пропущен ⏭', chatId: $chatId);
    }

    private function adjust(TelegramClient $tg, string $decision, ?int $chatId = null): void
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
            $tg->send('Готово, обновил настройки 👌🏻', chatId: $chatId);

            return;
        }

        $this->clearPending();
        $tg->send('Ок, оставляем как есть 👌🏻', chatId: $chatId);
    }

    /**
     * Запуск программы по кнопке онбординга. Идемпотентно: если уже идёт —
     * сообщаем текущий день и ничего не меняем.
     */
    private function programStart(TelegramClient $tg, ?int $chatId = null): void
    {
        if (Settings::get('program_started_on') !== null) {
            $tg->send('Программа уже идёт (день '.$this->programDay().') 👌🏻', chatId: $chatId);

            return;
        }

        app(StartProgram::class)->handle();

        $lines = [
            'Отлично, старт зафиксирован! 🚀',
            '',
            'Первым делом пришли свой стартовый вес — просто числом в кг (например 82.3). По желанию добавь замеры (талия, бёдра).',
            '',
            'Завтра начнём первый полный день по программе: приёмы пищи по окнам, я буду напоминать. Погнали! 💪🏼',
        ];

        // kind=weight_request — следующее число распознается как стартовый вес.
        $tg->send(implode("\n", $lines), null, 'weight_request', $chatId);
    }

    /**
     * Кнопка настройки: запоминаем ожидаемый ключ и просим значение.
     * Обрабатываем только три ключа; прочее игнорируем (answerCallback всё равно сработает).
     */
    private function setSetting(TelegramClient $tg, string $key, ?int $chatId = null): void
    {
        $prompts = [
            'wake_time' => 'Пришли время подъёма в формате ЧЧ:ММ, например 07:00',
            'sleep_time' => 'Пришли время отбоя в формате ЧЧ:ММ, например 23:00',
            'steps_target' => 'Пришли число шагов в день (3000–30000)',
        ];

        if (! isset($prompts[$key])) {
            return;
        }

        Settings::set('awaiting_setting', $key);
        $tg->send($prompts[$key], null, 'setting_request', $chatId);
    }

    /**
     * Очищает pending_adjustments. Столбец value NOT NULL, поэтому «пусто»
     * выражается отсутствием строки — тогда Settings::get вернёт дефолт null.
     */
    private function clearPending(): void
    {
        NutritionSetting::query()->where('key', 'pending_adjustments')->delete();
    }

    /**
     * Номер текущего дня программы (день старта = 1). 0, если программа не запущена.
     */
    private function programDay(): int
    {
        $startedOn = Settings::get('program_started_on');
        if ($startedOn === null) {
            return 0;
        }

        $start = CarbonImmutable::parse((string) $startedOn, 'Europe/Moscow')->startOfDay();
        $today = CarbonImmutable::now('Europe/Moscow')->startOfDay();

        return (int) $start->diffInDays($today) + 1;
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
