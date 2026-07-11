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
            'program' => $this->programStart($tg),
            'set' => $this->setSetting($tg, $arg),
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

        // Повторный/устаревший callback: не затираем eaten_at/фото/фидбек.
        if ($meal->status !== 'pending') {
            $tg->send('Этот приём уже отмечен 👌🏻');

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

        // Устаревший callback по уже отмеченному приёму — БД не трогаем.
        if ($meal->status !== 'pending') {
            $tg->send('Этот приём уже отмечен 👌🏻');

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
     * Запуск программы по кнопке онбординга. Идемпотентно: если уже идёт —
     * сообщаем текущий день и ничего не меняем.
     */
    private function programStart(TelegramClient $tg): void
    {
        if (Settings::get('program_started_on') !== null) {
            $tg->send('Программа уже идёт (день '.$this->programDay().') 👌🏻');

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
        $tg->send(implode("\n", $lines), null, 'weight_request');
    }

    /**
     * Кнопка настройки: запоминаем ожидаемый ключ и просим значение.
     * Обрабатываем только три ключа; прочее игнорируем (answerCallback всё равно сработает).
     */
    private function setSetting(TelegramClient $tg, string $key): void
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
        $tg->send($prompts[$key], null, 'setting_request');
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
