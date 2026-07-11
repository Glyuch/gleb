<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionMeal;
use App\Models\NutritionMetric;
use App\Models\NutritionSetting;
use App\Support\Nutrition\Fmt;
use App\Support\Nutrition\MealPlan;
use App\Support\Nutrition\Planner;
use App\Support\Nutrition\ProgramStatus;
use App\Support\Nutrition\Settings;
use App\Support\Nutrition\TelegramClient;
use App\Support\Nutrition\Tg;
use Carbon\CarbonImmutable;

class HandleCommand
{
    private const STATUS_EMOJI = [
        'eaten' => '✅',
        'pending' => '⏳',
        'skipped' => '⏭',
        'missed' => '❌',
    ];

    public function handle(array $update): void
    {
        // Любая команда выводит из режима ожидания значения настройки.
        NutritionSetting::query()->where('key', 'awaiting_setting')->delete();

        $tg = app(TelegramClient::class);
        $chatId = Tg::chatId($update);

        $text = trim((string) ($update['message']['text'] ?? ''));
        $parts = preg_split('/\s+/', $text, 2) ?: [];
        $command = strtolower($parts[0] ?? '');
        $arg = trim($parts[1] ?? '');

        match ($command) {
            '/start' => $this->start($tg, $chatId),
            '/help' => $this->help($tg, $chatId),
            '/today' => $this->today($tg, $chatId),
            '/stats' => $this->stats($tg, $chatId),
            '/weight' => $this->metric($tg, 'weight', $arg, $chatId),
            '/steps' => $this->metric($tg, 'steps', $arg, $chatId),
            '/water' => $this->metric($tg, 'water', $arg, $chatId),
            '/skip' => $this->skip($tg, $chatId),
            '/checkup' => $this->checkup($tg),
            '/settings' => $this->settings($tg, $chatId),
            default => $tg->send('Не знаю такой команды. /help — список команд.', chatId: $chatId),
        };
    }

    private function start(TelegramClient $tg, ?int $chatId = null): void
    {
        $lines = [
            'Привет! Я твой нутрициолог 🙌🏼',
            '',
            'Как мы работаем:',
            '• 2–4 приёма пищи по окнам — присылай фото каждого, дам обратную связь.',
            '• Вес — по четвергам и воскресеньям утром (командой /weight или просто числом).',
            '• Шаги и воду — вечером (/steps, /water или скрин шагомера).',
            '• Вопросы по питанию — задавай в любой момент, просто напиши текстом.',
            '• Материалы программы буду присылать по расписанию.',
            '',
        ];

        $startedOn = Settings::get('program_started_on');
        $keyboard = null;

        if ($startedOn === null) {
            $lines[] = '📍 Программа ещё не запущена.';
            $keyboard = [[['text' => '🚀 Начать программу 10 недель', 'callback_data' => 'program:start']]];
        } elseif (Settings::get('phase') === 'maintenance') {
            $lines[] = '📍 Режим поддержки.';
        } else {
            $lines[] = '📍 Идёт день '.ProgramStatus::day().' программы.';
        }

        $lines[] = '';
        $lines[] = '<b>Команды</b>';
        $lines[] = '/today — план и статусы приёмов';
        $lines[] = '/stats — вес, шаги, вода';
        $lines[] = '/checkup — недельный чек-ап';
        $lines[] = '/weight, /steps, /water — записать показатели';
        $lines[] = '/skip — пропустить ближайший приём';
        $lines[] = '/settings — режим дня и цели';
        $lines[] = '/help — справка';

        $tg->send(implode("\n", $lines), $keyboard, chatId: $chatId);
    }

    private function help(TelegramClient $tg, ?int $chatId = null): void
    {
        $lines = [
            'Привет! Я твой нутрициолог 🙌🏼',
            '',
            'Присылай фото еды — разберу приём. Пришли скрин шагомера — запишу шаги. Задавай вопросы по питанию.',
            '',
            '<b>Команды</b>',
            '/today — план и статусы приёмов',
            '/stats — вес, шаги, вода',
            '/weight 82.3 — записать вес',
            '/steps 11200 — записать шаги',
            '/water 2.5 — записать воду',
            '/skip — пропустить ближайший приём',
            '/checkup — недельный чек-ап',
            '/settings — режим дня и цели',
        ];

        $tg->send(implode("\n", $lines), chatId: $chatId);
    }

    private function today(TelegramClient $tg, ?int $chatId = null): void
    {
        $now = CarbonImmutable::now('Europe/Moscow');
        Planner::ensureDay($now);

        $meals = NutritionMeal::query()
            ->whereDate('date', $now->format('Y-m-d'))
            ->get()
            ->keyBy('type');

        $lines = ['<b>План на сегодня</b>', ''];

        foreach (MealPlan::TYPES as $type) {
            $meal = $meals[$type] ?? null;
            if ($meal === null) {
                continue;
            }

            $emoji = self::STATUS_EMOJI[$meal->status] ?? '•';
            $window = ($meal->window_start !== null && $meal->window_end !== null)
                ? $meal->window_start->format('H:i').'–'.$meal->window_end->format('H:i')
                : '—';

            $line = $emoji.' '.MealPlan::LABELS[$type].' '.$window;
            if ($meal->status === 'eaten' && $meal->eaten_at !== null) {
                $line .= ' (в '.$meal->eaten_at->format('H:i').')';
            }

            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = 'Цели: шаги '.Settings::get('steps_target').', вода 2 л, отбой '.Settings::get('sleep_time').'.';

        $tg->send(implode("\n", $lines), chatId: $chatId);
    }

    private function stats(TelegramClient $tg, ?int $chatId = null): void
    {
        $now = CarbonImmutable::now('Europe/Moscow');
        $from = $now->subDays(6)->format('Y-m-d');
        $to = $now->format('Y-m-d');

        $lines = ['<b>Вес</b>'];
        $weights = NutritionMetric::query()
            ->where('type', 'weight')
            ->orderByDesc('date')
            ->limit(8)
            ->get()
            ->sortBy(fn ($m) => $m->date->format('Y-m-d'))
            ->values();

        if ($weights->isEmpty()) {
            $lines[] = '— нет данных';
        } else {
            foreach ($weights as $w) {
                $lines[] = '— '.$w->date->format('d.m').' → '.Fmt::num((float) $w->value).' кг';
            }
            $delta = (float) $weights->last()->value - (float) $weights->first()->value;
            $sign = $delta > 0 ? '+' : '';
            $lines[] = 'Динамика: '.$sign.Fmt::num($delta).' кг';
        }

        $lines[] = '';
        $lines[] = '<b>Шаги (7 дней)</b>';
        $steps = NutritionMetric::query()
            ->where('type', 'steps')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get();
        $lines[] = $steps->isEmpty()
            ? '— нет данных'
            : 'Среднее: '.(int) round((float) $steps->avg('value')).' / цель '.Settings::get('steps_target');

        $lines[] = '';
        $lines[] = '<b>Вода (7 дней)</b>';
        $water = NutritionMetric::query()
            ->where('type', 'water')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get();
        $lines[] = $water->isEmpty()
            ? '— нет данных'
            : 'Среднее: '.Fmt::num((float) $water->avg('value')).' л';

        $tg->send(implode("\n", $lines), chatId: $chatId);
    }

    private function metric(TelegramClient $tg, string $type, string $arg, ?int $chatId = null): void
    {
        $raw = str_replace(',', '.', trim($arg));

        if ($raw === '' || ! is_numeric($raw)) {
            $tg->send($this->formatHint($type), chatId: $chatId);

            return;
        }

        $value = (float) $raw;

        if (! $this->inRange($type, $value)) {
            $tg->send($this->formatHint($type), chatId: $chatId);

            return;
        }

        $today = CarbonImmutable::now('Europe/Moscow')->format('Y-m-d');

        NutritionMetric::query()->updateOrCreate(
            ['date' => $today, 'type' => $type],
            ['value' => $value],
        );

        $tg->send('Записал: '.$this->metricConfirm($type, $value).' 👌🏻', chatId: $chatId);
    }

    private function skip(TelegramClient $tg, ?int $chatId = null): void
    {
        $now = CarbonImmutable::now('Europe/Moscow');
        Planner::ensureDay($now);

        $meal = Planner::currentMeal($now);
        if ($meal === null) {
            $tg->send('Нет ближайшего приёма для пропуска 👌🏻', chatId: $chatId);

            return;
        }

        $label = MealPlan::LABELS[$meal->type];
        $meal->update(['status' => 'skipped']);
        Planner::recalculate($now->startOfDay());

        $lines = [$label.' пропущен ⏭', '', 'Остаток дня:'];
        $rest = NutritionMeal::query()
            ->whereDate('date', $now->format('Y-m-d'))
            ->where('status', 'pending')
            ->orderBy('window_start')
            ->get();

        if ($rest->isEmpty()) {
            $lines[] = '— приёмов больше нет';
        } else {
            foreach ($rest as $m) {
                $lines[] = '⏳ '.MealPlan::LABELS[$m->type].' '
                    .$m->window_start->format('H:i').'–'.$m->window_end->format('H:i');
            }
        }

        $tg->send(implode("\n", $lines), chatId: $chatId);
    }

    private function checkup(TelegramClient $tg): void
    {
        app(RunCheckup::class)->handle(onDemand: true);
    }

    private function settings(TelegramClient $tg, ?int $chatId = null): void
    {
        $windows = Settings::get('default_windows');
        $windowStrings = [];
        foreach (MealPlan::TYPES as $type) {
            if (isset($windows[$type])) {
                $windowStrings[] = MealPlan::LABELS[$type].' '.$windows[$type]['start'].'–'.$windows[$type]['end'];
            }
        }

        $portion = (int) Settings::get('portion_adjustment');
        $portionStr = ($portion > 0 ? '+' : '').$portion.'%';
        $phase = Settings::get('phase') === 'program' ? 'Программа TriDaily' : 'Поддержка';
        $day = Settings::get('program_started_on') === null ? '—' : (string) ProgramStatus::day();

        $lines = [
            '<b>Настройки</b>',
            'Подъём: '.Settings::get('wake_time'),
            'Отбой: '.Settings::get('sleep_time'),
            'Окна: '.implode(', ', $windowStrings),
            'Цель шагов: '.Settings::get('steps_target'),
            'Поправка порций: '.$portionStr,
            'Фаза: '.$phase,
            'День программы: '.$day,
        ];

        $keyboard = [
            [['text' => '⏰ Подъём', 'callback_data' => 'set:wake_time']],
            [['text' => '🌙 Отбой', 'callback_data' => 'set:sleep_time']],
            [['text' => '👣 Цель шагов', 'callback_data' => 'set:steps_target']],
        ];

        $tg->send(implode("\n", $lines), $keyboard, chatId: $chatId);
    }

    /**
     * Диапазоны значений — те же, что в HandleNumbers: вес 40–150, шаги 0–100000, вода 0–10.
     */
    private function inRange(string $type, float $value): bool
    {
        return match ($type) {
            'weight' => $value >= 40 && $value <= 150,
            'steps' => $value >= 0 && $value <= 100000,
            'water' => $value > 0 && $value <= 10,
            default => true,
        };
    }

    private function metricConfirm(string $type, float $value): string
    {
        return match ($type) {
            'weight' => 'вес '.Fmt::num($value).' кг',
            'steps' => 'шаги '.(int) round($value),
            'water' => 'вода '.Fmt::num($value).' л',
            default => Fmt::num($value),
        };
    }

    private function formatHint(string $type): string
    {
        return match ($type) {
            'weight' => 'Не понял число. Формат: /weight 82.3',
            'steps' => 'Не понял число. Формат: /steps 11200',
            'water' => 'Не понял число. Формат: /water 2.5',
            default => 'Не понял число.',
        };
    }
}
