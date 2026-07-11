<?php

namespace App\Console\Commands;

use App\Actions\Nutrition\RunCheckup;
use App\Actions\Nutrition\RunDaySummary;
use App\Actions\Nutrition\SendTopic;
use App\Models\NutritionMeal;
use App\Models\NutritionSentEvent;
use App\Models\NutritionTopic;
use App\Support\Nutrition\MealPlan;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\Settings;
use App\Support\Nutrition\TelegramClient;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NutritionTick extends Command
{
    protected $signature = 'nutrition:tick {--at= : Симуляция «сейчас» (Europe/Moscow), режим DRY-RUN — ничего не шлётся и не пишется}';

    protected $description = 'Минутный тик планировщика нутрициолога: приветствия, напоминания, метрики, саммари, чек-апы';

    /** DRY-RUN: ничего не отправляем и не пишем в sent_events/messages, только печатаем. */
    private bool $dryRun = false;

    public function handle(): int
    {
        $at = (string) ($this->option('at') ?? '');
        $this->dryRun = $at !== '';

        $now = $this->dryRun
            ? CarbonImmutable::parse($at, 'Europe/Moscow')
            : CarbonImmutable::now('Europe/Moscow');

        // Dry-run полностью изолирован от БД: любые записи (ensureDay/markMissed/
        // graduation) откатываются безусловным rollback.
        if ($this->dryRun) {
            DB::beginTransaction();
        }

        try {
            $this->tick($now);
        } finally {
            if ($this->dryRun) {
                DB::rollBack();
            }
        }

        return self::SUCCESS;
    }

    private function tick(CarbonImmutable $now): void
    {
        $d = $now->format('Y-m-d');
        $phase = (string) Settings::get('phase');
        $tg = app(TelegramClient::class);

        if ($this->dryRun) {
            $this->line("DRY-RUN nutrition:tick @ {$now->format('Y-m-d H:i')} (Europe/Moscow), фаза: {$phase}");
            $this->line('');
        }

        // 1. Гарантируем приёмы дня и закрываем просроченные.
        Planner::ensureDay($now);
        Planner::markMissed($now);

        $greetingTime = $now
            ->setTimeFromTimeString((string) Settings::get('wake_time'))
            ->addMinutes(30);

        // 2. Взвешивание (чт/вс; в maintenance — только вс) и приветствие с планом дня.
        if ($now->greaterThanOrEqualTo($greetingTime)) {
            $weightDay = $phase === 'maintenance'
                ? $now->isSunday()
                : ($now->isThursday() || $now->isSunday());

            if ($weightDay) {
                $weightText = 'Утреннее взвешивание натощак ⚖️ Пришли вес числом';
                $this->fire("{$d}:weight_request", $weightText, fn () => $tg->send($weightText, null, 'weight_request'));
            }

            $greeting = $this->greetingText($now);
            $this->fire("{$d}:greeting", $greeting, fn () => $tg->send($greeting, null, 'greeting'));
        }

        // 3. Напоминания и follow-up по каждому pending-приёму.
        $meals = NutritionMeal::query()
            ->whereDate('date', $d)
            ->get()
            ->keyBy('type');

        foreach (MealPlan::TYPES as $type) {
            $meal = $meals[$type] ?? null;
            if ($meal === null || $meal->status !== 'pending') {
                continue;
            }

            if ($meal->window_start !== null && $now->greaterThanOrEqualTo($meal->window_start)) {
                $text = '⏰ '.MealPlan::LABELS[$type].' '
                    .$meal->window_start->format('H:i').'–'.$meal->window_end->format('H:i')
                    .'. '.MealPlan::COMPOSITION[$type];
                $this->fire("{$d}:reminder:{$type}", $text, fn () => $tg->send($text, $this->mealButtons($type), 'reminder'));
            }

            if ($phase !== 'maintenance' && $meal->window_end !== null) {
                $followupAt = $meal->window_end->copy()->addMinutes(30);
                if ($now->greaterThanOrEqualTo($followupAt)) {
                    $ftext = 'Поели '.mb_strtolower(MealPlan::LABELS[$type]).'? ☺️';
                    $this->fire("{$d}:followup:{$type}", $ftext, fn () => $tg->send($ftext, $this->mealButtons($type), 'followup'));
                }
            }
        }

        // 4. Запрос метрик вечером.
        if ($now->greaterThanOrEqualTo($now->setTime(21, 30))) {
            $mtext = 'Сколько шагов сегодня? Пришли число или скрин шагомера 🙌🏼 И сколько воды (л)?';
            $this->fire("{$d}:metrics_request", $mtext, fn () => $tg->send($mtext, null, 'metrics_request'));
        }

        // 5. Итог дня.
        if ($now->greaterThanOrEqualTo($now->setTime(22, 30))) {
            $this->fireAction("{$d}:summary", 'итог дня', fn () => app(RunDaySummary::class)->handle($now));
        }

        // 6. Недельный чек-ап (вс вечером).
        if ($now->isSunday() && $now->greaterThanOrEqualTo($now->setTime(20, 0))) {
            $this->fireAction("{$d}:checkup", 'недельный чек-ап', fn () => app(RunCheckup::class)->handle());
        }

        // 7. Материал дня (только в фазе программы).
        if ($phase === 'program' && $now->greaterThanOrEqualTo($now->setTime(10, 30))) {
            $topics = NutritionTopic::query()
                ->whereDate('scheduled_on', $d)
                ->whereNull('sent_at')
                ->orderBy('position')
                ->get();

            foreach ($topics as $topic) {
                $this->fireAction(
                    "{$d}:topic:{$topic->id}",
                    "тема «{$topic->title}»",
                    fn () => app(SendTopic::class)->handle($topic),
                );
            }
        }

        // 8. Переход в фазу поддержки после 70 дней программы.
        $startedOn = Settings::get('program_started_on');
        if ($phase === 'program' && $startedOn !== null) {
            $start = CarbonImmutable::parse((string) $startedOn, 'Europe/Moscow')->startOfDay();
            if ($start->addDays(70)->lessThanOrEqualTo($now->startOfDay())) {
                $gtext = $this->graduationText();
                $this->fire("{$d}:graduation", $gtext, function () use ($tg, $gtext) {
                    $tg->send($gtext, null, 'graduation');
                    Settings::set('phase', 'maintenance');
                });
            }
        }
    }

    /**
     * Идемпотентно выполнить событие; в dry-run — только напечатать план.
     */
    private function fire(string $key, string $description, Closure $action): void
    {
        if ($this->dryRun) {
            if (NutritionSentEvent::query()->where('event_key', $key)->exists()) {
                $this->line("— уже отправлено ранее: {$key}");

                return;
            }
            $this->line("[{$key}]");
            $this->line($description);
            $this->line('');

            return;
        }

        NutritionSentEvent::once($key, $action);
    }

    /**
     * Идемпотентное действие (саммари/чек-ап); в dry-run — только пометка.
     */
    private function fireAction(string $key, string $label, Closure $action): void
    {
        if ($this->dryRun) {
            $done = NutritionSentEvent::query()->where('event_key', $key)->exists();
            $this->line($done ? "— уже выполнено ранее: {$key}" : "[{$key}] {$label}");

            return;
        }

        NutritionSentEvent::once($key, $action);
    }

    private function greetingText(CarbonImmutable $now): string
    {
        $meals = NutritionMeal::query()
            ->whereDate('date', $now->format('Y-m-d'))
            ->get()
            ->keyBy('type');

        $lines = ['Доброе утро! 🙌🏼 План на сегодня:'];

        foreach (MealPlan::TYPES as $type) {
            $meal = $meals[$type] ?? null;
            if ($meal === null || $meal->window_start === null || $meal->window_end === null) {
                continue;
            }
            $lines[] = '• '.MealPlan::LABELS[$type].' '
                .$meal->window_start->format('H:i').'–'.$meal->window_end->format('H:i');
        }

        $lines[] = '';
        $lines[] = 'Цель: шаги '.Settings::get('steps_target').', вода 2 л. Хорошего дня! 👌🏻';

        return implode("\n", $lines);
    }

    private function graduationText(): string
    {
        return implode("\n", [
            'Поздравляю! 🎉 Ты прошёл программу TriDaily — это большая работа над собой.',
            '',
            'Пришли, пожалуйста, финальные замеры: вес натощак и обхваты — сравним со стартом.',
            'Дальше переходим в режим поддержки: ритм чуть свободнее, но привычки держим 🙌🏼',
        ]);
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    private function mealButtons(string $type): array
    {
        return [[
            ['text' => '✅ Поел', 'callback_data' => "ate:{$type}"],
            ['text' => '⏭ Пропускаю', 'callback_data' => "skip:{$type}"],
        ]];
    }
}
