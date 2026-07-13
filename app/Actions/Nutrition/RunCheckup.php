<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionMetric;
use App\Models\NutritionProfile;
use App\Support\Nutrition\Address;
use App\Support\Nutrition\Claude;
use App\Support\Nutrition\Fmt;
use App\Support\Nutrition\PromptBuilder;
use App\Support\Nutrition\TelegramClient;
use Carbon\CarbonImmutable;

class RunCheckup
{
    private const INSTRUCTION = <<<'TXT'
        Проведи недельный чек-ап клиента по данным выше (тренд веса, съедено/пропущено по дням,
        среднее шагов, вода). Верни ОТВЕТ СТРОГО в формате JSON без пояснений и без markdown-заборов:
        {"message": "разбор недели 5–8 предложений, тепло и по делу", "adjustments": {"steps_target": 9000}}
        Поле adjustments — необязательное; используй null, если менять ничего не нужно.
        Допустимые ключи adjustments: steps_target (целое число шагов), portion_adjustment (целое −30..30),
        sleep_time ("HH:MM"). Не добавляй других ключей.
        TXT;

    /**
     * Запускает недельный чек-ап. При наличии корректировок сохраняет их в pending_adjustments
     * и предлагает кнопки применения.
     */
    public function handle(NutritionProfile $profile, bool $onDemand = false, ?int $chatId = null): void
    {
        $now = $profile->now();
        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;

        $occasion = $onDemand
            ? 'Это внеплановый чек-ап по личному запросу клиента.'
            : 'Это плановый воскресный чек-ап.';

        $prompt = $occasion."\n".$this->context($profile, $now)."\n\n".self::INSTRUCTION;

        $raw = Claude::text(
            [['type' => 'text', 'text' => $prompt]],
            (string) config('nutrition.models.chat'),
            1000,
            $profile,
        );

        if ($raw === null) {
            $tg->send(Address::ensure($profile, 'Не смог сейчас собрать чек-ап, попробуем позже 🙏'), null, 'checkup', $chatId);

            return;
        }

        $data = json_decode($this->stripFences($raw), true);

        // Невалидный JSON → отправляем сырой текст без корректировок.
        if (! is_array($data) || ! isset($data['message'])) {
            $tg->send(Address::ensure($profile, $raw), null, 'checkup', $chatId);

            return;
        }

        $message = (string) $data['message'];
        $adjustments = $this->validAdjustments($data['adjustments'] ?? null);

        if ($adjustments === []) {
            $tg->send(Address::ensure($profile, $message), null, 'checkup', $chatId);

            return;
        }

        $profile->setWaiting('pending_adjustments', $adjustments);

        $keyboard = [
            [['text' => 'Применить ✅', 'callback_data' => 'adj:yes']],
            [['text' => 'Не надо', 'callback_data' => 'adj:no']],
        ];

        $tg->send(Address::ensure($profile, $message), $keyboard, 'checkup', $chatId);
    }

    /**
     * Контекст за 14 дней: тренд веса, съедено/пропущено по дням, среднее шагов, вода.
     */
    private function context(NutritionProfile $profile, CarbonImmutable $now): string
    {
        $from = $now->subDays(13)->startOfDay();
        $fromStr = $from->format('Y-m-d');
        $toStr = $now->format('Y-m-d');

        $lines = ['Недельный чек-ап. Данные за 14 дней ('.$from->format('d.m').'–'.$now->format('d.m').'):'];

        // Тренд веса.
        $weights = NutritionMetric::query()
            ->where('profile_id', $profile->id)
            ->where('type', 'weight')
            ->whereDate('date', '>=', $fromStr)
            ->whereDate('date', '<=', $toStr)
            ->orderBy('date')
            ->get();

        $lines[] = '';
        $lines[] = 'Вес:';
        if ($weights->isEmpty()) {
            $lines[] = '— нет данных.';
        } else {
            foreach ($weights as $w) {
                $lines[] = '— '.$w->date->format('d.m').': '.Fmt::num((float) $w->value).' кг';
            }
            $delta = (float) $weights->last()->value - (float) $weights->first()->value;
            $lines[] = 'Динамика: '.($delta > 0 ? '+' : '').Fmt::num($delta).' кг.';
        }

        // Приёмы по дням.
        $meals = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', '>=', $fromStr)
            ->whereDate('date', '<=', $toStr)
            ->get()
            ->groupBy(fn ($m) => $m->date->format('Y-m-d'));

        $lines[] = '';
        $lines[] = 'Приёмы по дням (съедено/пропущено):';
        if ($meals->isEmpty()) {
            $lines[] = '— нет данных.';
        } else {
            foreach ($meals->sortKeys() as $day => $group) {
                $eaten = $group->where('status', 'eaten')->count();
                $missed = $group->whereIn('status', ['missed', 'skipped'])->count();
                $lines[] = '— '.CarbonImmutable::parse($day)->format('d.m').': съедено '.$eaten.', пропущено '.$missed.'.';
            }
        }

        // Среднее шагов и вода.
        $steps = NutritionMetric::query()
            ->where('profile_id', $profile->id)
            ->where('type', 'steps')
            ->whereDate('date', '>=', $fromStr)
            ->whereDate('date', '<=', $toStr)
            ->get();

        $water = NutritionMetric::query()
            ->where('profile_id', $profile->id)
            ->where('type', 'water')
            ->whereDate('date', '>=', $fromStr)
            ->whereDate('date', '<=', $toStr)
            ->get();

        $lines[] = '';
        $lines[] = 'Среднее шагов: '.($steps->isEmpty() ? '—' : (int) round((float) $steps->avg('value')))
            .' / цель '.$profile->setting('steps_target').'.';
        $lines[] = 'Среднее воды: '.($water->isEmpty() ? '—' : Fmt::num((float) $water->avg('value'))).' л.';

        // Рейтинги приёмов за период (детерминированно из БД).
        $lines[] = '';
        $lines[] = PromptBuilder::ratingsDigest($profile, $fromStr, $toStr, 'За 14 дней — ');

        return implode("\n", $lines);
    }

    private function stripFences(string $raw): string
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Оставляет только допустимые ключи корректировок с валидными значениями.
     *
     * @return array<string, int|string>
     */
    private function validAdjustments(mixed $adjustments): array
    {
        if (! is_array($adjustments)) {
            return [];
        }

        $out = [];

        if (isset($adjustments['steps_target']) && is_numeric($adjustments['steps_target'])) {
            $out['steps_target'] = (int) $adjustments['steps_target'];
        }

        if (isset($adjustments['portion_adjustment']) && is_numeric($adjustments['portion_adjustment'])) {
            $portion = (int) $adjustments['portion_adjustment'];
            if ($portion >= -30 && $portion <= 30) {
                $out['portion_adjustment'] = $portion;
            }
        }

        if (isset($adjustments['sleep_time']) && is_string($adjustments['sleep_time'])
            && preg_match('/^\d{2}:\d{2}$/', $adjustments['sleep_time'])) {
            $out['sleep_time'] = $adjustments['sleep_time'];
        }

        return $out;
    }
}
