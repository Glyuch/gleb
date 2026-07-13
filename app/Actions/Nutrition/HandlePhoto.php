<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use App\Models\NutritionProfile;
use App\Support\Nutrition\Address;
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
        $now = $profile->now();

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

        // 1.5. Альбом: Telegram шлёт каждое фото отдельным апдейтом с общим
        // media_group_id. Обрабатываем только первое фото группы, остальные молча
        // пропускаем — иначе один приём разбирается и отвечается N раз.
        $mediaGroupId = (string) ($update['message']['media_group_id'] ?? '');
        if ($mediaGroupId !== '') {
            if ($profile->waiting('photo_group') === $mediaGroupId) {
                return;
            }
            $profile->setWaiting('photo_group', $mediaGroupId);
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

        // Приём, закрытый кнопкой «✅ Поел» без фото, — цель для доклейки фото.
        // Нет живых кандидатов → узкое окно 40 мин (тихо цепляем). Есть живой
        // кандидат → неоднозначность (напр. поздний полдник vs открытый ужин):
        // окно шире (2 ч) и мы не угадываем, а спрашиваем кнопками.
        $eatenNoPhoto = $this->eatenWithoutPhoto($profile, $now, $candidates === [] ? 40 : 120);

        $choices = $candidates;
        if ($eatenNoPhoto !== null) {
            // eatenNoPhoto всегда 'eaten' → в foodCandidates (pending/missed) не попадёт.
            $choices[] = $eatenNoPhoto;
        }

        if ($choices === []) {
            $tg->send('Перекусов на программе нет 👌🏻 До следующего приёма — вода/чай/кофе без всего', chatId: $chatId);

            return;
        }

        // Несколько возможных приёмов (просроченные до текущего и/или закрытый
        // кнопкой без фото) — не угадываем, уточняем кнопками.
        if (count($choices) > 1) {
            $profile->setWaiting('meal_photo', $fileId);

            $buttons = [];
            foreach ($choices as $choice) {
                $buttons[] = [['text' => MealPlan::LABELS[$choice->type], 'callback_data' => 'mealphoto:'.$choice->type]];
            }

            $tg->send('Это какой приём? 🤔', $buttons, chatId: $chatId);

            return;
        }

        $meal = $choices[0];

        // «Слишком рано» не считаем, если клеим фото к уже съеданному приёму.
        $warning = $meal->status === 'eaten' ? null : $this->tooSoonWarning($profile, $now);

        $image = $tg->downloadPhotoBase64($fileId);
        $raw = $image !== null
            ? Claude::vision($image, MealLogger::foodPrompt($profile, $meal->type), 400, $profile)
            : null;

        $parsed = MealLogger::parseFood($raw);

        Planner::recordFoodPhoto($profile, $meal, $now, $fileId, $parsed['feedback'], $parsed['score'], $parsed['extra']);

        // 4. Fallback: если ИИ не ответил — всё равно фиксируем приём.
        $parts = [Address::ensure($profile, $parsed['feedback'] ?? 'Записал приём 👌🏻 Разбор пришлю позже')];

        // Детерминированный хвост про сдвинутые окна следующих приёмов (общий с текстовым путём).
        $tail = MealLogger::windowsTail($profile, $now);
        if ($tail !== '') {
            $parts[] = $tail;
        }

        if ($warning !== null) {
            $parts[] = $warning;
        }

        $tg->send(implode("\n\n", $parts), chatId: $chatId);
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

    /**
     * Приём, отмеченный кнопкой «✅ Поел» в пределах $withinMinutes и ещё БЕЗ фото —
     * цель, к которой можно доклеить досланное фото/разбор.
     */
    private function eatenWithoutPhoto(NutritionProfile $profile, CarbonImmutable $now, int $withinMinutes): ?NutritionMeal
    {
        return NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('status', 'eaten')
            ->whereNull('photo_file_id')
            ->whereNotNull('eaten_at')
            ->where('eaten_at', '>=', $now->subMinutes($withinMinutes)->format('Y-m-d H:i:s'))
            ->orderByDesc('eaten_at')
            ->first();
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

        $prevAt = CarbonImmutable::parse($prev->eaten_at->format('Y-m-d H:i:s'), $profile->tz());

        if (abs($prevAt->diffInMinutes($now)) < 150) {
            return '⚠️ Меньше 2,5 ч от прошлого приёма — в следующий раз чуть позже';
        }

        return null;
    }
}
