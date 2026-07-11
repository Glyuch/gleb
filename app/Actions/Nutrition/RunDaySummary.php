<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionMetric;
use App\Support\Nutrition\Claude;
use App\Support\Nutrition\Fmt;
use App\Support\Nutrition\PromptBuilder;
use App\Support\Nutrition\Settings;
use App\Support\Nutrition\TelegramClient;
use Carbon\CarbonImmutable;

class RunDaySummary
{
    private const INSTRUCTION = <<<'TXT'
        Подведи итог дня для Глеба: что прошло по плану, что нарушено (интервалы между приёмами,
        пропуски, запрещёнка из фидбеков, шаги и вода против цели) и один фокус на завтра.
        Пиши тепло, по-дружески на «ты», 4–6 предложений. Без списков и заголовков.
        TXT;

    /**
     * Формирует и отправляет итог дня. При недоступности модели — детерминированный fallback.
     */
    public function handle(?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now('Europe/Moscow');
        $tg = app(TelegramClient::class);

        $prompt = PromptBuilder::dayContext($now)."\n\n".self::INSTRUCTION;

        $text = Claude::text(
            [['type' => 'text', 'text' => $prompt]],
            (string) config('nutrition.models.chat'),
            600,
        );

        $tg->send($text ?? $this->fallback($now), null, 'summary');
    }

    private function fallback(CarbonImmutable $now): string
    {
        $meals = NutritionMeal::query()
            ->whereDate('date', $now->format('Y-m-d'))
            ->get();

        $eaten = $meals->where('status', 'eaten')->count();
        $missed = $meals->whereIn('status', ['missed', 'skipped'])->count();

        $steps = NutritionMetric::query()
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('type', 'steps')
            ->value('value');

        $water = NutritionMetric::query()
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('type', 'water')
            ->value('value');

        $stepsStr = $steps !== null ? (int) $steps : '—';
        $waterStr = $water !== null ? Fmt::num((float) $water) : '—';

        return implode("\n", [
            'Итог дня 🙌🏼',
            'Съедено по плану: '.$eaten.'/4, пропущено: '.$missed.'.',
            'Шаги: '.$stepsStr.' / цель '.Settings::get('steps_target').'.',
            'Вода: '.$waterStr.' л.',
            'Завтра держим ритм и не забываем про воду 👌🏻',
        ]);
    }
}
