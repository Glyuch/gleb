<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionMessage;
use App\Models\NutritionMetric;
use App\Support\Nutrition\Claude;
use App\Support\Nutrition\MealPlan;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\Settings;
use App\Support\Nutrition\TelegramClient;
use Carbon\CarbonImmutable;

class HandlePhoto
{
    private const FORBIDDEN = 'сахар, мучное/выпечка, жареное, фастфуд, газировка/пакетированные соки, алкоголь';

    public function handle(array $update): void
    {
        $tg = app(TelegramClient::class);
        $now = CarbonImmutable::now('Europe/Moscow');

        Planner::ensureDay($now);
        $meal = Planner::currentMeal($now);

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
            ->where('direction', 'out')
            ->orderByDesc('id')
            ->value('kind');
        $hasSteps = NutritionMetric::query()
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('type', 'steps')
            ->exists();

        if ($lastOutKind === 'metrics_request' && ! $hasSteps) {
            $this->pedometer($tg, $fileId, $now);

            return;
        }

        // 3. Фото еды: если нет активного приёма — перекусов на программе нет.
        if ($meal === null) {
            $tg->send('Перекусов на программе нет 👌🏻 До следующего приёма — вода/чай/кофе без всего');

            return;
        }

        $warning = $this->tooSoonWarning($now);

        $image = $tg->downloadPhotoBase64($fileId);
        $feedback = $image !== null
            ? Claude::vision($image, $this->foodPrompt($meal->type))
            : null;

        Planner::markEaten($meal, $now, $fileId, $feedback);

        // 4. Fallback: если ИИ не ответил — всё равно фиксируем приём.
        $reply = $feedback ?? 'Записал приём 👌🏻 Разбор пришлю позже';
        if ($warning !== null) {
            $reply .= "\n\n".$warning;
        }

        $tg->send($reply);
    }

    private function pedometer(TelegramClient $tg, string $fileId, CarbonImmutable $now): void
    {
        $image = $tg->downloadPhotoBase64($fileId);
        if ($image === null) {
            $tg->send('Не смог открыть скрин, пришли ещё раз 🙏');

            return;
        }

        $answer = Claude::vision(
            $image,
            'На скриншоте трекер активности. Извлеки число шагов за день. Ответь ТОЛЬКО числом, без текста. Если шагов нет — ответь 0.',
        );

        if ($answer === null || ! preg_match('/\d[\d\s]*/u', $answer, $m)) {
            $tg->send('Не смог распознать шаги на скрине, пришли число текстом 🙏');

            return;
        }

        $steps = (int) preg_replace('/\D/', '', $m[0]);

        NutritionMetric::query()->updateOrCreate(
            ['date' => $now->format('Y-m-d'), 'type' => 'steps'],
            ['value' => $steps],
        );

        $tg->send('Записал шаги: '.$steps.' 👌🏻');
    }

    private function tooSoonWarning(CarbonImmutable $now): ?string
    {
        $prev = NutritionMeal::query()
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

    private function foodPrompt(string $type): string
    {
        $portion = (int) Settings::get('portion_adjustment');
        $portionStr = ($portion > 0 ? '+' : '').$portion.'%';

        return 'На фото приём: '.MealPlan::LABELS[$type].".\n"
            .'Ожидаемый состав: '.MealPlan::COMPOSITION[$type].".\n"
            .'Запрещёнка (кратко): '.self::FORBIDDEN.".\n"
            .'Поправка порций: '.$portionStr.".\n"
            .'Оцени приём в стиле Насти — тепло и по делу, 1–3 предложения; при необходимости кратко объясни «почему» через физиологию.';
    }
}
