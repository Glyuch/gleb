<?php

namespace App\Console\Commands;

use App\Actions\Nutrition\RunCheckup;
use App\Actions\Nutrition\RunDaySummary;
use App\Actions\Nutrition\SendTopic;
use App\Models\NutritionMeal;
use App\Models\NutritionProfile;
use App\Models\NutritionSentEvent;
use App\Models\NutritionTopicSend;
use App\Support\Nutrition\Address;
use App\Support\Nutrition\MealPlan;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\TelegramClient;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        // Персональный тик каждого активного профиля: свои окна/фаза/настройки,
        // отправка строго в его main_chat_id, ключи sent_events с префиксом p{id}.
        $profiles = NutritionProfile::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        if ($this->dryRun) {
            $this->line("DRY-RUN nutrition:tick @ {$now->format('Y-m-d H:i')} (Europe/Moscow)");
            $this->line('');
        }

        foreach ($profiles as $profile) {
            // Сбой одного профиля не должен прерывать тик остальных.
            try {
                $this->tickProfile($profile, $now);
            } catch (Throwable $e) {
                if ($this->dryRun) {
                    $this->line("[{$profile->name}] ОШИБКА тика: {$e->getMessage()}");
                    $this->line('');
                } else {
                    Log::error('nutrition: tick profile failed', [
                        'profile_id' => $profile->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function tickProfile(NutritionProfile $profile, CarbonImmutable $now): void
    {
        // Тот же абсолютный момент — в местном времени профиля. Все окна/слоты/
        // пороги ниже считаются в его поясе (для Europe/Moscow — без изменений).
        $now = $now->setTimezone($profile->tz());

        $d = $now->format('Y-m-d');

        // Guard: профилю без main_chat_id слать некуда — пропускаем.
        // В бою — once-лог раз в день, чтобы не засорять журнал.
        if (blank($profile->main_chat_id)) {
            if ($this->dryRun) {
                $this->line("[{$profile->name}] пропуск: не задан main_chat_id");
                $this->line('');
            } else {
                NutritionSentEvent::once("p{$profile->id}:{$d}:no-chat", function () use ($profile) {
                    Log::info('nutrition: tick skipped profile without main_chat_id', ['profile_id' => $profile->id]);
                });
            }

            return;
        }

        // Guard: анкета завершена (status=active), но программа ещё не запущена
        // кнопкой «Начать программу» (program_started_on=null). До старта тик
        // не должен слать приветствие/напоминания/метрики/саммари.
        if ($profile->program_started_on === null) {
            if ($this->dryRun) {
                $this->line("[{$profile->name}] пропуск: программа не начата");
                $this->line('');
            } else {
                NutritionSentEvent::once("p{$profile->id}:{$d}:not-started", function () use ($profile) {
                    Log::info('nutrition: tick skipped profile before program start', ['profile_id' => $profile->id]);
                });
            }

            return;
        }

        $phase = (string) $profile->phase;
        $chat = (int) $profile->main_chat_id;
        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;

        if ($this->dryRun) {
            $this->line("— профиль #{$profile->id} {$profile->name} (фаза {$phase}, чат {$chat}) —");
            $this->line('');
        }

        // 1. Гарантируем приёмы дня и закрываем просроченные.
        Planner::ensureDay($profile, $now);
        Planner::markMissed($profile, $now);

        $greetingTime = $now
            ->setTimeFromTimeString((string) $profile->setting('wake_time'))
            ->addMinutes(30);

        // 2. Взвешивание (чт/вс; в maintenance — только вс) и приветствие с планом дня.
        if ($now->greaterThanOrEqualTo($greetingTime)) {
            $weightDay = $phase === 'maintenance'
                ? $now->isSunday()
                : ($now->isThursday() || $now->isSunday());

            if ($weightDay) {
                $weightText = Address::ensure($profile, 'Утреннее взвешивание натощак ⚖️ Пришли вес числом');
                $this->fire($profile, "{$d}:weight_request", $weightText, fn () => $tg->send($weightText, null, 'weight_request', $chat));
            }

            $greeting = $this->greetingText($profile, $now);
            $this->fire($profile, "{$d}:greeting", $greeting, fn () => $tg->send($greeting, null, 'greeting', $chat));
        }

        // 3. Слот-привязанные напоминания по каждому pending-приёму.
        // Ключи содержат ВРЕМЯ слота (H:i), поэтому сдвиг окна регенерирует
        // напоминания: старые ключи израсходованы, новое время окна даёт новые.
        // markMissed уже отработал выше — помеченные missed сюда не попадут.
        $lead = (int) config('nutrition.reminders.lead_minutes', 30);
        $step = max(1, (int) config('nutrition.reminders.nudge_interval', 30));
        $missedAfter = (int) config('nutrition.reminders.missed_after', 90);

        $meals = NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', $d)
            ->get()
            ->keyBy('type');

        foreach (MealPlan::TYPES as $type) {
            $meal = $meals[$type] ?? null;
            if ($meal === null || $meal->status !== 'pending') {
                continue;
            }
            if ($meal->window_start === null || $meal->window_end === null) {
                continue;
            }

            // Наивное окно из БД (app.timezone) переинтерпретируем в поясе профиля,
            // чтобы сравнения со $now (местное время профиля) были верны и для
            // не-московских поясов. Для Europe/Moscow результат идентичен прежнему.
            $ws = CarbonImmutable::parse($meal->window_start->format('Y-m-d H:i:s'), $profile->tz());
            $we = CarbonImmutable::parse($meal->window_end->format('Y-m-d H:i:s'), $profile->tz());

            // (1) Пре-напоминание: [ws-lead, ws). Только в фазе программы.
            if ($phase !== 'maintenance'
                && $now->greaterThanOrEqualTo($ws->copy()->subMinutes($lead))
                && $now->lessThan($ws)) {
                $pretext = Address::ensure($profile, 'Через полчаса '.MealPlan::LABELS[$type].' 🙌🏼 Окно '
                    .$ws->format('H:i').'–'.$we->format('H:i'));
                $this->fire($profile, "{$d}:pre:{$type}:{$ws->format('H:i')}", $pretext,
                    fn () => $tg->send($pretext, null, 'reminder', $chat));
            }

            // (2) Активный 30-мин слот от ws: максимальный s <= now при now < s+step.
            // Только текущее ведро — пропущенные слоты пачкой не бэкфиллим.
            $sinceWs = (int) floor($ws->floatDiffInMinutes($now, false));
            if ($sinceWs < 0) {
                continue;
            }
            $slot = $ws->copy()->addMinutes(intdiv($sinceWs, $step) * $step);

            // Слот жив, пока приём не ушёл бы в missed (окно + грейс до missed).
            if ($slot->greaterThan($we->copy()->addMinutes($missedAfter))) {
                continue;
            }
            $slotKey = "{$d}:meal:{$type}:{$slot->format('H:i')}";

            if ($slot->equalTo($ws)) {
                // Старт окна: полное напоминание с составом + кнопки.
                $text = Address::ensure($profile, '⏰ '.MealPlan::LABELS[$type].' '
                    .$ws->format('H:i').'–'.$we->format('H:i')
                    .'. '.MealPlan::COMPOSITION[$type]);
                $this->fire($profile, $slotKey, $text, fn () => $tg->send($text, $this->mealButtons($type), 'reminder', $chat));
            } elseif ($phase !== 'maintenance' && $now->lessThanOrEqualTo($we)) {
                // Надж «поели?» — только пока окно ещё открыто (now <= window_end).
                // После конца окна в grace-период до missed больше НЕ пингуем, чтобы
                // не спамить (окна могут наслаиваться). В maintenance — всегда молчим.
                $ntext = Address::ensure($profile, 'Поели '.mb_strtolower(MealPlan::LABELS[$type]).'? ☺️');
                $this->fire($profile, $slotKey, $ntext, fn () => $tg->send($ntext, $this->mealButtons($type), 'followup', $chat));
            }
        }

        // 4. Запрос метрик вечером.
        if ($now->greaterThanOrEqualTo($now->setTime(21, 30))) {
            $mtext = Address::ensure($profile, 'Сколько шагов сегодня? Пришли число или скрин шагомера 🙌🏼 И сколько воды (л)?');
            $this->fire($profile, "{$d}:metrics_request", $mtext, fn () => $tg->send($mtext, null, 'metrics_request', $chat));
        }

        // 5. Итог дня.
        if ($now->greaterThanOrEqualTo($now->setTime(22, 30))) {
            $this->fireAction($profile, "{$d}:summary", 'итог дня', fn () => app(RunDaySummary::class)->handle($profile, $now, $chat));
        }

        // 6. Недельный чек-ап (вс вечером).
        if ($now->isSunday() && $now->greaterThanOrEqualTo($now->setTime(20, 0))) {
            $this->fireAction($profile, "{$d}:checkup", 'недельный чек-ап', fn () => app(RunCheckup::class)->handle($profile, false, $chat));
        }

        // 7. Материал дня (только в фазе программы) — из per-profile topic_sends.
        if ($phase === 'program' && $now->greaterThanOrEqualTo($now->setTime(10, 30))) {
            $sends = NutritionTopicSend::query()
                ->where('profile_id', $profile->id)
                ->whereDate('scheduled_on', $d)
                ->whereNull('sent_at')
                ->with('topic')
                ->get()
                ->sortBy(fn (NutritionTopicSend $s) => $s->topic?->position ?? PHP_INT_MAX);

            foreach ($sends as $send) {
                $topic = $send->topic;
                if ($topic === null) {
                    continue;
                }
                $this->fireAction(
                    $profile,
                    "{$d}:topic:{$topic->id}",
                    "тема «{$topic->title}»",
                    fn () => app(SendTopic::class)->handle($profile, $send, $chat),
                );
            }
        }

        // 8. Переход в фазу поддержки после 70 дней программы.
        $startedOn = $profile->program_started_on;
        if ($phase === 'program' && $startedOn !== null) {
            $start = CarbonImmutable::parse($startedOn->format('Y-m-d'), $profile->tz())->startOfDay();
            if ($start->addDays(70)->lessThanOrEqualTo($now->startOfDay())) {
                $gtext = Address::ensure($profile, $this->graduationText());
                $this->fire($profile, "{$d}:graduation", $gtext, function () use ($tg, $gtext, $profile, $chat) {
                    $tg->send($gtext, null, 'graduation', $chat);
                    $profile->update(['phase' => 'maintenance']);
                });
            }
        }
    }

    /**
     * Идемпотентно выполнить событие профиля; в dry-run — только напечатать план.
     * $bareKey — ключ без префикса ({d}:...); фактический ключ пишется с префиксом
     * p{id}:. Для admin-профиля переходно засчитывается и legacy-ключ без префикса
     * (события, отправленные до перехода на префиксы) — так деплой Task 3 не
     * дублирует уже отправленное сегодня. Можно удалить legacy-ветку после 2026-07-14.
     */
    private function fire(NutritionProfile $profile, string $bareKey, string $description, Closure $action): void
    {
        $key = "p{$profile->id}:{$bareKey}";

        if ($this->dryRun) {
            if ($this->alreadyFired($profile, $bareKey)) {
                $this->line("[{$profile->name}] — уже отправлено ранее: {$key}");

                return;
            }
            $this->line("[{$profile->name}] [{$key}]");
            $this->line($description);
            $this->line('');

            return;
        }

        if ($this->firedLegacy($profile, $bareKey)) {
            return;
        }

        NutritionSentEvent::once($key, $action);
    }

    /**
     * Идемпотентное действие (саммари/чек-ап/тема); в dry-run — только пометка.
     */
    private function fireAction(NutritionProfile $profile, string $bareKey, string $label, Closure $action): void
    {
        $key = "p{$profile->id}:{$bareKey}";

        if ($this->dryRun) {
            $done = $this->alreadyFired($profile, $bareKey);
            $this->line($done
                ? "[{$profile->name}] — уже выполнено ранее: {$key}"
                : "[{$profile->name}] [{$key}] {$label}");

            return;
        }

        if ($this->firedLegacy($profile, $bareKey)) {
            return;
        }

        NutritionSentEvent::once($key, $action);
    }

    /** Событие уже выполнено (префиксный ключ, либо legacy-ключ только для admin). */
    private function alreadyFired(NutritionProfile $profile, string $bareKey): bool
    {
        $keys = ["p{$profile->id}:{$bareKey}"];
        if ($profile->is_admin) {
            $keys[] = $bareKey; // переходно; удалить после 2026-07-14
        }

        return NutritionSentEvent::query()->whereIn('event_key', $keys)->exists();
    }

    /** Переходно: admin уже выполнил событие сегодня под legacy-ключом без префикса. */
    private function firedLegacy(NutritionProfile $profile, string $bareKey): bool
    {
        // Удалить после 2026-07-14: сегодняшние legacy-ключи к тому времени мертвы.
        return $profile->is_admin
            && NutritionSentEvent::query()->where('event_key', $bareKey)->exists();
    }

    private function greetingText(NutritionProfile $profile, CarbonImmutable $now): string
    {
        $meals = NutritionMeal::query()
            ->where('profile_id', $profile->id)
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
        $lines[] = 'Цель: шаги '.$profile->setting('steps_target').', вода 2 л. Хорошего дня! 👌🏻';

        return Address::ensure($profile, implode("\n", $lines));
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
        return [
            [
                ['text' => '✅ Поел', 'callback_data' => "ate:{$type}"],
                ['text' => '⏭ Пропускаю', 'callback_data' => "skip:{$type}"],
            ],
            [
                ['text' => '🕐 Поел раньше', 'callback_data' => "atepast:{$type}"],
            ],
        ];
    }
}
