<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionProfile;
use App\Support\Nutrition\Address;
use App\Support\Nutrition\Claude;
use App\Support\Nutrition\MealLogger;
use App\Support\Nutrition\MealPlan;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\ProgramStatus;
use App\Support\Nutrition\TelegramClient;
use App\Support\Nutrition\Tg;

class HandleCallback
{
    public function handle(array $update, NutritionProfile $profile): void
    {
        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;
        $chatId = Tg::chatId($update);
        $callback = $update['callback_query'] ?? [];
        $data = (string) ($callback['data'] ?? '');
        $callbackId = (string) ($callback['id'] ?? '');

        [$action, $arg] = array_pad(explode(':', $data, 2), 2, '');

        match ($action) {
            'ate' => $this->ate($tg, $profile, $arg, $chatId),
            'reeval' => $this->reeval($tg, $profile, $arg, $chatId),
            'cancel' => $this->cancel($tg, $profile, $arg, $chatId),
            'skip' => $this->skip($tg, $profile, $arg, $chatId),
            'mealphoto' => $this->mealPhoto($tg, $profile, $arg, $chatId),
            'atepast' => $this->atePast($tg, $profile, $arg, $chatId),
            'adj' => $this->adjust($tg, $profile, $arg, $chatId),
            'program' => $this->programStart($tg, $profile, $chatId),
            'set' => $this->setSetting($tg, $profile, $arg, $chatId),
            'chatmain' => $this->chatMain($tg, $profile, $arg, $chatId),
            'onboard' => $this->onboard($tg, $profile, $arg, $chatId),
            default => $tg->send('Не понял действие 🤔', chatId: $chatId),
        };

        if ($callbackId !== '') {
            $tg->answerCallback($callbackId);
        }
    }

    private function ate(TelegramClient $tg, NutritionProfile $profile, string $type, ?int $chatId = null): void
    {
        $meal = $this->meal($profile, $type);
        if ($meal === null) {
            $tg->send('Не нашёл такой приём на сегодня 🤔', chatId: $chatId);

            return;
        }

        // Записываем и pending, и missed (поздняя отметка). Уже eaten/skipped не трогаем.
        if (! in_array($meal->status, ['pending', 'missed'], true)) {
            $tg->send('Этот приём уже отмечен 👌🏻', chatId: $chatId);

            return;
        }

        Planner::markEaten($profile, $meal, $profile->now(), null, null);

        // Симметрично фото-пути: сообщаем сдвинутые окна следующих приёмов.
        $parts = [MealPlan::LABELS[$type].' отмечен ✅'];
        $tail = MealLogger::windowsTail($profile, $profile->now());
        if ($tail !== '') {
            $parts[] = $tail;
        }
        // Кнопка переоценки: клиент может уточнить состав и пересчитать балл.
        $tg->send(implode("\n\n", $parts), MealLogger::mealActions($meal), chatId: $chatId);
    }

    /**
     * Кнопка «🔄 Переоценить» под разбором приёма: ставим ожидание reeval на этот
     * приём и просим уточнить состав. Следующий текст клиента уйдёт в переоценку
     * ИМЕННО этого приёма (HandleQuestion::interceptReeval). Приём должен
     * существовать и быть разобран (СЪЕДЕН) сегодня.
     */
    private function reeval(TelegramClient $tg, NutritionProfile $profile, string $arg, ?int $chatId = null): void
    {
        if (! ctype_digit($arg)) {
            return;
        }

        $now = $profile->now();
        Planner::ensureDay($profile, $now);

        $meal = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('id', (int) $arg)
            ->where('status', 'eaten')
            ->first();

        if ($meal === null) {
            $tg->send('Не нашёл этот приём на сегодня 🤔', chatId: $chatId);

            return;
        }

        $profile->setWaiting('reeval', (int) $arg);
        // Взаимоисключаем с прочими ожиданиями ввода.
        $profile->clearWaiting('meal_time');
        $profile->clearWaiting('setting');

        // kind=reeval_request — staleness-guard в HandleQuestion::interceptReeval.
        $tg->send('Что там на самом деле? Напиши состав и способ готовки — пересчитаю 🙌🏼', null, 'reeval_request', $chatId);
    }

    /**
     * Кнопка «↩️ Отменить» под разбором приёма: сразу сбрасываем приём в pending и
     * пересчитываем окна (Planner::cancelMeal) — отмена обратима, повторного
     * подтверждения не требуем. Приём ищем СТРОГО в рамках этого профиля (фильтр
     * profile_id) и только за сегодня; чужой/несуществующий id → мягкий отказ, как в
     * reeval. Ожидание replace_photo здесь НЕ ставим: обычный фото-поток подхватит
     * pending-слот, если клиент пришлёт фото заново.
     */
    private function cancel(TelegramClient $tg, NutritionProfile $profile, string $arg, ?int $chatId = null): void
    {
        if (! ctype_digit($arg)) {
            return;
        }

        $now = $profile->now();
        Planner::ensureDay($profile, $now);

        $meal = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('id', (int) $arg)
            ->where('status', 'eaten')
            ->first();

        if ($meal === null) {
            $tg->send('Не нашёл этот приём на сегодня 🤔', chatId: $chatId);

            return;
        }

        $label = MealPlan::LABELS[$meal->type];
        Planner::cancelMeal($profile, $meal);

        $tg->send(Address::ensure($profile, 'отменил '.$label.', окна пересчитал ✅ Пришлёшь заново — зафиксирую 🙌🏼'), chatId: $chatId);
    }

    private function skip(TelegramClient $tg, NutritionProfile $profile, string $type, ?int $chatId = null): void
    {
        $meal = $this->meal($profile, $type);
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
        Planner::recalculate($profile, $profile->now()->startOfDay());

        $parts = [MealPlan::LABELS[$type].' пропущен ⏭'];
        $tail = MealLogger::windowsTail($profile, $profile->now());
        if ($tail !== '') {
            $parts[] = $tail;
        }
        $tg->send(implode("\n\n", $parts), chatId: $chatId);
    }

    /**
     * Выбор приёма для отложенного фото: берём сохранённый file_id, распознаём,
     * помечаем приём съеденным и очищаем ожидание.
     */
    private function mealPhoto(TelegramClient $tg, NutritionProfile $profile, string $type, ?int $chatId = null): void
    {
        if (! in_array($type, MealPlan::TYPES, true)) {
            return;
        }

        $fileId = $profile->waiting('meal_photo');
        if (! is_string($fileId) || $fileId === '') {
            $tg->send('Фото не найдено, пришли ещё раз 🙏', chatId: $chatId);

            return;
        }

        $meal = $this->meal($profile, $type);
        if ($meal === null) {
            $tg->send('Не нашёл такой приём на сегодня 🤔', chatId: $chatId);

            return;
        }

        $now = $profile->now();

        $image = $tg->downloadPhotoBase64($fileId);
        $raw = $image !== null
            ? Claude::vision($image, MealLogger::foodPrompt($profile, $type), 400, $profile)
            : null;

        $parsed = MealLogger::parseFood($raw);

        Planner::recordFoodPhoto($profile, $meal, $now, $fileId, $parsed['feedback'], $parsed['score'], $parsed['extra']);
        $profile->clearWaiting('meal_photo');

        $lines = [Address::ensure($profile, $parsed['feedback'] ?? 'Записал приём 👌🏻 Разбор пришлю позже')];
        $tail = MealLogger::windowsTail($profile, $now);
        if ($tail !== '') {
            $lines[] = '';
            $lines[] = $tail;
        }
        $lines[] = '';
        $lines[] = 'Поел раньше? Напиши время, например «в 10:00» — поправлю.';

        $tg->send(implode("\n", $lines), MealLogger::mealActions($meal), chatId: $chatId);
    }

    /**
     * «Поел раньше»: ждём время ЧЧ:ММ следующим сообщением (перехватит SettingInput).
     */
    private function atePast(TelegramClient $tg, NutritionProfile $profile, string $type, ?int $chatId = null): void
    {
        if (! in_array($type, MealPlan::TYPES, true)) {
            return;
        }

        $profile->setWaiting('meal_time', $type);
        // Взаимоисключаем с ожиданием настройки.
        $profile->clearWaiting('setting');

        // kind=meal_time_request — staleness-guard в SettingInput::interceptMealTime.
        $tg->send('Во сколько поел? Пришли время ЧЧ:ММ, например 10:00', null, 'meal_time_request', $chatId);
    }

    private function adjust(TelegramClient $tg, NutritionProfile $profile, string $decision, ?int $chatId = null): void
    {
        if ($decision === 'yes') {
            $pending = $profile->waiting('pending_adjustments');
            if (is_array($pending)) {
                foreach (['steps_target', 'portion_adjustment', 'sleep_time'] as $key) {
                    if (array_key_exists($key, $pending)) {
                        $profile->setSetting($key, $pending[$key]);
                    }
                }
            }
            $profile->clearWaiting('pending_adjustments');
            $tg->send('Готово, обновил настройки 👌🏻', chatId: $chatId);

            return;
        }

        $profile->clearWaiting('pending_adjustments');
        $tg->send('Ок, оставляем как есть 👌🏻', chatId: $chatId);
    }

    /**
     * Запуск программы по кнопке онбординга. Идемпотентно: если уже идёт —
     * сообщаем текущий день и ничего не меняем.
     */
    private function programStart(TelegramClient $tg, NutritionProfile $profile, ?int $chatId = null): void
    {
        if ($profile->program_started_on !== null) {
            $tg->send('Программа уже идёт (день '.ProgramStatus::day($profile).') 👌🏻', chatId: $chatId);

            return;
        }

        app(StartProgram::class)->handle($profile);

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
     * Кнопка «Сделать этот чат основным?» после добавления бота в группу.
     * yes → основной чат = чат этого колбэка; no → оставляем как было.
     */
    private function chatMain(TelegramClient $tg, NutritionProfile $profile, string $decision, ?int $chatId = null): void
    {
        // Гейт: реагируем только если ЭТОМУ профилю предлагали сделать основным
        // именно ЭТОТ чат. Иначе — тихо (answerCallback всё равно отработает в handle()).
        $offered = $profile->waiting('chatmain_offer');
        if ($chatId === null || $offered === null || (int) $offered !== $chatId) {
            return;
        }

        $profile->clearWaiting('chatmain_offer');

        if ($decision === 'yes') {
            $profile->update(['main_chat_id' => $chatId]);
            $tg->send('Готово, теперь плановые сообщения буду слать сюда 👌🏻', chatId: $chatId);

            return;
        }

        $tg->send('Ок, оставил как было 👌🏻', chatId: $chatId);
    }

    /**
     * Колбэки анкеты онбординга. skip — «Пропустить» на последнем шаге: завершаем
     * анкету без ответа о здоровье.
     */
    private function onboard(TelegramClient $tg, NutritionProfile $profile, string $arg, ?int $chatId = null): void
    {
        if ($arg === 'skip') {
            app(Onboarding::class)->skip($profile, $chatId);
        }
    }

    /**
     * Кнопка настройки: запоминаем ожидаемый ключ и просим значение.
     * Обрабатываем только три ключа; прочее игнорируем (answerCallback всё равно сработает).
     */
    private function setSetting(TelegramClient $tg, NutritionProfile $profile, string $key, ?int $chatId = null): void
    {
        $prompts = [
            'wake_time' => 'Пришли время подъёма в формате ЧЧ:ММ, например 07:00',
            'sleep_time' => 'Пришли время отбоя в формате ЧЧ:ММ, например 23:00',
            'steps_target' => 'Пришли число шагов в день (3000–30000)',
        ];

        if (! isset($prompts[$key])) {
            return;
        }

        $profile->setWaiting('setting', $key);
        // Взаимоисключаем с ожиданием времени приёма.
        $profile->clearWaiting('meal_time');

        $tg->send($prompts[$key], null, 'setting_request', $chatId);
    }

    private function meal(NutritionProfile $profile, string $type): ?NutritionMeal
    {
        if (! in_array($type, MealPlan::TYPES, true)) {
            return null;
        }

        $now = $profile->now();
        Planner::ensureDay($profile, $now);

        return NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('type', $type)
            ->first();
    }
}
