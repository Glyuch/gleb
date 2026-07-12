<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionSetting;
use App\Support\Nutrition\Claude;
use App\Support\Nutrition\MealLogger;
use App\Support\Nutrition\MealPlan;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\ProgramStatus;
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
            'mealphoto' => $this->mealPhoto($tg, $arg, $chatId),
            'atepast' => $this->atePast($tg, $arg, $chatId),
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

        // Записываем и pending, и missed (поздняя отметка). Уже eaten/skipped не трогаем.
        if (! in_array($meal->status, ['pending', 'missed'], true)) {
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

    /**
     * Выбор приёма для отложенного фото: берём сохранённый file_id, распознаём,
     * помечаем приём съеденным и очищаем ожидание.
     */
    private function mealPhoto(TelegramClient $tg, string $type, ?int $chatId = null): void
    {
        if (! in_array($type, MealPlan::TYPES, true)) {
            return;
        }

        $fileId = Settings::get('awaiting_meal_photo');
        if (! is_string($fileId) || $fileId === '') {
            $tg->send('Фото не найдено, пришли ещё раз 🙏', chatId: $chatId);

            return;
        }

        $meal = $this->meal($type);
        if ($meal === null) {
            $tg->send('Не нашёл такой приём на сегодня 🤔', chatId: $chatId);

            return;
        }

        $now = CarbonImmutable::now('Europe/Moscow');

        $image = $tg->downloadPhotoBase64($fileId);
        $feedback = $image !== null
            ? Claude::vision($image, MealLogger::foodPrompt($type))
            : null;

        Planner::markEaten($meal, $now, $fileId, $feedback);
        $this->clearKey('awaiting_meal_photo');

        $lines = [$feedback ?? 'Записал приём 👌🏻 Разбор пришлю позже'];
        $tail = MealLogger::windowsTail($now);
        if ($tail !== '') {
            $lines[] = '';
            $lines[] = $tail;
        }
        $lines[] = '';
        $lines[] = 'Поел раньше? Напиши время, например «в 10:00» — поправлю.';

        $tg->send(implode("\n", $lines), chatId: $chatId);
    }

    /**
     * «Поел раньше»: ждём время ЧЧ:ММ следующим сообщением (перехватит SettingInput).
     */
    private function atePast(TelegramClient $tg, string $type, ?int $chatId = null): void
    {
        if (! in_array($type, MealPlan::TYPES, true)) {
            return;
        }

        Settings::set('awaiting_meal_time', $type);
        // Взаимоисключаем с ожиданием настройки.
        $this->clearKey('awaiting_setting');

        // kind=meal_time_request — staleness-guard в SettingInput::interceptMealTime.
        $tg->send('Во сколько поел? Пришли время ЧЧ:ММ, например 10:00', null, 'meal_time_request', $chatId);
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
            $tg->send('Программа уже идёт (день '.ProgramStatus::day().') 👌🏻', chatId: $chatId);

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
        // Взаимоисключаем с ожиданием времени приёма.
        $this->clearKey('awaiting_meal_time');

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
     * Сбрасывает ключ настройки удалением строки (value NOT NULL → «пусто» = нет строки).
     */
    private function clearKey(string $key): void
    {
        NutritionSetting::query()->where('key', $key)->delete();
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
