<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use App\Models\NutritionProfile;
use App\Support\Nutrition\Claude;
use App\Support\Nutrition\MealLogger;
use App\Support\Nutrition\MealPlan;
use App\Support\Nutrition\PendingRequest;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\TelegramClient;
use App\Support\Nutrition\Tg;
use Carbon\CarbonImmutable;

class HandlePhoto
{
    public function handle(array $update, NutritionProfile $profile): void
    {
        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;
        $chatId = Tg::chatId($update);
        $now = CarbonImmutable::now('Europe/Moscow');

        Planner::ensureDay($profile, $now);

        $photos = $update['message']['photo'] ?? [];
        if ($photos === []) {
            return;
        }

        // Максимальное разрешение — последний элемент массива photo.
        $fileId = (string) (end($photos)['file_id'] ?? '');
        if ($fileId === '') {
            return;
        }

        // 2. Скрин шагомера в ответ на запрос метрик (если шаги ещё не записаны).
        $lastOutKind = NutritionMessage::query()
            ->where('profile_id', $profile->id)
            ->where('direction', 'out')
            ->orderByDesc('id')
            ->value('kind');
        $hasSteps = NutritionMetric::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('type', 'steps')
            ->exists();

        if (($lastOutKind === 'metrics_request' || PendingRequest::expectsMetrics($profile, $now)) && ! $hasSteps) {
            $this->pedometer($tg, $profile, $fileId, $now, $chatId);

            return;
        }

        // 3. Фото еды: кандидаты = пропущенные приёмы до текущего + текущий приём.
        $candidates = $this->foodCandidates($profile, $now);

        if ($candidates === []) {
            $tg->send('Перекусов на программе нет 👌🏻 До следующего приёма — вода/чай/кофе без всего', chatId: $chatId);

            return;
        }

        // Есть пропущенные приёмы до текущего — не пишем разбор молча, уточняем приём.
        if (count($candidates) > 1) {
            $profile->setWaiting('meal_photo', $fileId);

            $buttons = [];
            foreach ($candidates as $candidate) {
                $buttons[] = [['text' => MealPlan::LABELS[$candidate->type], 'callback_data' => 'mealphoto:'.$candidate->type]];
            }

            $tg->send('Это какой приём? 🤔', $buttons, chatId: $chatId);

            return;
        }

        $meal = $candidates[0];
        $warning = $this->tooSoonWarning($profile, $now);

        $image = $tg->downloadPhotoBase64($fileId);
        $feedback = $image !== null
            ? Claude::vision($image, MealLogger::foodPrompt($profile, $meal->type), 400, $profile)
            : null;

        Planner::markEaten($profile, $meal, $now, $fileId, $feedback);

        // 4. Fallback: если ИИ не ответил — всё равно фиксируем приём.
        $reply = $feedback ?? 'Записал приём 👌🏻 Разбор пришлю позже';
        if ($warning !== null) {
            $reply .= "\n\n".$warning;
        }

        $tg->send($reply, chatId: $chatId);
    }

    /**
     * Кандидаты для фото еды: незакрытые приёмы (pending|missed), чьё окно уже
     * наступило (window_start <= now), по порядку окна. НЕ требуем window_end>=now —
     * иначе просроченный, но ещё не помеченный missed приём выпадает и фото молча
     * цепляется к следующему. Пустой массив — приёмов нет (перекусов нет).
     *
     * @return array<int, NutritionMeal>
     */
    private function foodCandidates(NutritionProfile $profile, CarbonImmutable $now): array
    {
        return NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->whereIn('status', ['pending', 'missed'])
            ->where('window_start', '<=', $now->format('Y-m-d H:i:s'))
            ->orderBy('window_start')
            ->get()
            ->all();
    }

    private function pedometer(TelegramClient $tg, NutritionProfile $profile, string $fileId, CarbonImmutable $now, ?int $chatId = null): void
    {
        $image = $tg->downloadPhotoBase64($fileId);
        if ($image === null) {
            $tg->send('Не смог открыть скрин, пришли ещё раз 🙏', chatId: $chatId);

            return;
        }

        $answer = Claude::vision(
            $image,
            'На скриншоте трекер активности. Извлеки число шагов за день. Ответь ТОЛЬКО числом, без текста. Если шагов нет — ответь 0.',
            400,
            $profile,
        );

        if ($answer === null || ! preg_match('/\d[\d\s]*/u', $answer, $m)) {
            $tg->send('Не смог распознать шаги на скрине, пришли число текстом 🙏', chatId: $chatId);

            return;
        }

        $steps = (int) preg_replace('/\D/', '', $m[0]);

        NutritionMetric::query()->updateOrCreate(
            ['profile_id' => $profile->id, 'date' => $now->format('Y-m-d'), 'type' => 'steps'],
            ['value' => $steps],
        );

        $tg->send('Записал шаги: '.$steps.' 👌🏻', chatId: $chatId);
    }

    private function tooSoonWarning(NutritionProfile $profile, CarbonImmutable $now): ?string
    {
        $prev = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('status', 'eaten')
            ->whereNotNull('eaten_at')
            ->orderByDesc('eaten_at')
            ->first();

        if ($prev === null || $prev->eaten_at === null) {
            return null;
        }

        $prevAt = CarbonImmutable::parse($prev->eaten_at->format('Y-m-d H:i:s'), 'Europe/Moscow');

        if (abs($prevAt->diffInMinutes($now)) < 150) {
            return '⚠️ Меньше 2,5 ч от прошлого приёма — в следующий раз чуть позже';
        }

        return null;
    }
}
