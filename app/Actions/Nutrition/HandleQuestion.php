<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionProfile;
use App\Support\Nutrition\Address;
use App\Support\Nutrition\Claude;
use App\Support\Nutrition\MealIntent;
use App\Support\Nutrition\MealLogger;
use App\Support\Nutrition\MealPlan;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\PromptBuilder;
use App\Support\Nutrition\SettingInput;
use App\Support\Nutrition\TelegramClient;
use App\Support\Nutrition\Tg;
use Carbon\CarbonImmutable;

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

        // Ожидание уточнения после кнопки «🔄 Переоценить»: следующий текст —
        // авторитетное описание, пересчитываем ИМЕННО тот приём.
        if ($this->interceptReeval($tg, $profile, $text, $now, $chatId)) {
            return;
        }

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

        // Коррекция уже разобранного приёма (естественный текст-поправка/«переоцени»).
        if ($intent['intent'] === 'correct_meal') {
            $this->correctMeal($tg, $profile, $text, $now, $chatId, $intent['target'] ?? null);

            return;
        }

        // Отчёт о еде — записываем приёмы; иначе отправляем ответ ИИ.
        if ($intent['intent'] === 'meal_report' && $intent['reports'] !== []) {
            MealLogger::logReports($update, $profile, $now, $intent['reports'], $intent['reply']);

            return;
        }

        $tg->send(Address::ensure($profile, $intent['reply'] !== '' ? $intent['reply'] : 'Не смог сейчас ответить, попробуй ещё раз чуть позже 🙏'), chatId: $chatId);
    }

    /**
     * Ожидание reeval (после кнопки «🔄 Переоценить»): следующий текст пересчитывает
     * приём с сохранённым id. Пока запрос уточнения — последнее исходящее, любой
     * текст трактуется как уточнение. Если бот успел спросить что-то ещё — сбрасываем
     * устаревший awaiting и пропускаем сообщение дальше по маршруту.
     */
    private function interceptReeval(TelegramClient $tg, NutritionProfile $profile, string $text, CarbonImmutable $now, ?int $chatId): bool
    {
        $mealId = $profile->waiting('reeval');
        if (! is_int($mealId) && ! (is_string($mealId) && ctype_digit($mealId))) {
            return false;
        }

        if ($this->lastOutKind($profile) !== 'reeval_request') {
            $profile->clearWaiting('reeval');

            return false;
        }

        $profile->clearWaiting('reeval');

        $meal = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('id', (int) $mealId)
            ->where('status', 'eaten')
            ->first();

        if ($meal === null) {
            $tg->send(Address::ensure($profile, 'Не нашёл этот приём за сегодня — пересчитывать нечего 🤔'), chatId: $chatId);

            return true;
        }

        $this->applyReeval($tg, $profile, $meal, $text, $now, $chatId);

        return true;
    }

    /**
     * Естественный текст-коррекция: берём приём по названному типу, иначе последний
     * разобранный сегодня. Нет подходящего приёма — мягкий отказ.
     */
    private function correctMeal(TelegramClient $tg, NutritionProfile $profile, string $text, CarbonImmutable $now, ?int $chatId, ?string $target): void
    {
        $meal = MealLogger::lastEvaluatedMeal($profile, $now, $target);

        if ($meal === null) {
            $label = ($target !== null && isset(MealPlan::LABELS[$target])) ? '«'.MealPlan::LABELS[$target].'»' : 'такого приёма';
            $tg->send(Address::ensure($profile, 'Не вижу сегодня '.$label.' — нечего пересчитывать 🤔'), chatId: $chatId);

            return;
        }

        $this->applyReeval($tg, $profile, $meal, $text, $now, $chatId);
    }

    /**
     * Пересчёт приёма по авторитетному описанию клиента + обновление оценки без
     * сдвига eaten_at/окон. Ответ — «пересчитал {приём}: {score}/10» + фидбек и
     * снова кнопка переоценки. Имя-обращение добавляем один раз через Address::ensure
     * (в reevalPrompt фидбек просится без имени, чтобы не дублировать).
     *
     * Защита от потери данных при сбое модели:
     *  1) Полностью пустой eval (Claude::text вернул null: таймаут/не-2xx/пусто после
     *     retry) → приём НЕ трогаем, отвечаем мягко и ПЕРЕАРМИРУЕМ ожидание reeval
     *     (setWaiting + kind=reeval_request), чтобы следующий текст снова попал в
     *     переоценку этого же приёма — кнопку жать заново не нужно (консистентно с
     *     interceptReeval, который и активируется по паре waiting+reeval_request).
     *  2) Фидбек есть, но score невалиден/null → Planner::updateEvaluation прежний
     *     балл не обнуляет; в ответе не заявляем новый балл, а честно говорим, что
     *     оставили прежний.
     */
    private function applyReeval(TelegramClient $tg, NutritionProfile $profile, NutritionMeal $meal, string $text, CarbonImmutable $now, ?int $chatId): void
    {
        $eval = MealLogger::reevaluate($profile, $meal, $text);

        // (1) Полный сбой модели — ничего осмысленного не пришло. Приём не портим.
        if ($eval['feedback'] === null && $eval['score'] === null && $eval['extra'] === null) {
            $profile->setWaiting('reeval', $meal->id);
            $tg->send(
                Address::ensure($profile, 'Не смог сейчас пересчитать — попробуй ещё раз чуть позже 🙏'),
                null,
                'reeval_request',
                $chatId,
            );

            return;
        }

        Planner::updateEvaluation($profile, $meal, $eval);

        $label = MealPlan::LABELS[$meal->type];
        $feedback = $eval['feedback'] ?? 'Записал уточнение 👌🏻';

        if ($eval['score'] !== null) {
            // Валидный новый балл.
            $head = 'Пересчитал '.$label.': '.$eval['score'].'/10 ✅';
        } elseif ($meal->score !== null) {
            // (2) Модель балл не дала (проза/битый score), прежний сохранён — не врём.
            $head = 'Уточнение учёл, балл оставил прежним — '.$meal->score.'/10';
        } else {
            $head = 'Пересчитал '.$label.' ✅';
        }

        $tg->send(Address::ensure($profile, $head."\n".$feedback), MealLogger::reevalButton($meal), chatId: $chatId);
    }

    /**
     * Последний kind исходящего сообщения этого профиля (staleness-guard reeval).
     */
    private function lastOutKind(NutritionProfile $profile): ?string
    {
        return NutritionMessage::query()
            ->where('profile_id', $profile->id)
            ->where('direction', 'out')
            ->orderByDesc('id')
            ->value('kind');
    }
}
