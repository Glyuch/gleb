# Nutrition Bot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Telegram-бот «Нутрициолог» внутри Laravel-приложения gleb.finance: напоминания по динамическим окнам приёмов пищи, трекинг веса/шагов/воды, обучающие материалы и точечные ИИ-функции (Claude API) — разбор фото еды, ответы на вопросы, саммари дня, чек-апы.

**Architecture:** Webhook Telegram → контроллер → queued job (database queue). Laravel Scheduler запускает поминутный `nutrition:tick` (идемпотентный через таблицу событий). Чистая логика окон — отдельный класс `MealPlan` без зависимостей. ИИ — raw HTTP к Anthropic API через Http-фасад (без новых composer-зависимостей). Все датавремена nutrition-таблиц — наивное локальное время Europe/Moscow.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL (prod) / sqlite in-memory (тесты), Pest v4, Telegram Bot API, Anthropic Messages API (`claude-haiku-4-5` для vision, `claude-sonnet-5` для текстов).

## Global Constraints

- Вся работа по SSH: `ssh -l gleb gleb.finance`, репозиторий `/home/gleb/gleb.finance`, только ветка `master`, коммит после каждой задачи.
- НЕ добавлять composer/npm зависимости. HTTP только через `Illuminate\Support\Facades\Http`.
- Секреты (`NUTRITION_BOT_TOKEN`, `ANTHROPIC_API_KEY`, `NUTRITION_CHAT_ID`, `NUTRITION_WEBHOOK_SECRET`) — только в `.env`, никогда в git. Значения выдаёт координатор при выполнении Task 10.
- Все env-чтения — только через `config/nutrition.php` (на проде закэширован config; после изменения конфигов на сервере выполнять `php artisan config:cache`).
- Часовой пояс всей нутрициологической логики: `Europe/Moscow`. В nutrition-таблицах datetime хранится как локальное московское время; сравнение всегда с `CarbonImmutable::now('Europe/Moscow')`.
- Тесты: `php artisan test --filter=<Name>` (sqlite in-memory, безопасно). Перед каждым коммитом: `vendor/bin/pint --dirty`.
- Код-стиль: следовать существующим паттернам (тонкие контроллеры, `App\Actions\*`, `App\Support\*`, модели с `casts()` и PHPDoc — см. `app/Models/GameContent.php`).
- Идентификаторы моделей Anthropic: `claude-haiku-4-5` (фото), `claude-sonnet-5` (текст/саммари/чек-ап). Не менять и не добавлять date-суффиксы. `claude-sonnet-5` не принимает `temperature` — не передавать sampling-параметры вообще.

---

### Task 1: Конфиг, миграции, модели

**Files:**
- Create: `config/nutrition.php`
- Create: `database/migrations/2026_07_11_100000_create_nutrition_tables.php`
- Create: `app/Models/NutritionSetting.php`, `app/Models/NutritionMeal.php`, `app/Models/NutritionMetric.php`, `app/Models/NutritionMessage.php`, `app/Models/NutritionTopic.php`, `app/Models/NutritionSentEvent.php`
- Create: `app/Support/Nutrition/Settings.php`
- Test: `tests/Feature/Nutrition/SettingsTest.php`

**Interfaces:**
- Produces: `config('nutrition.bot_token'|'chat_id'|'anthropic_key'|'webhook_secret'|'timezone'|'models.vision'|'models.chat')`.
- Produces: `Settings::get(string $key, mixed $default = null): mixed`, `Settings::set(string $key, mixed $value): void` (json-хранение в `nutrition_settings`).
- Produces: `NutritionSentEvent::once(string $key, \Closure $fn): bool` — выполняет `$fn` один раз на ключ (идемпотентность тика), возвращает true если выполнил.
- Produces модели с полями (см. миграцию ниже).

- [ ] **Step 1: Конфиг**

```php
<?php
// config/nutrition.php
return [
    'bot_token' => env('NUTRITION_BOT_TOKEN'),
    'chat_id' => env('NUTRITION_CHAT_ID'),
    'anthropic_key' => env('ANTHROPIC_API_KEY'),
    'webhook_secret' => env('NUTRITION_WEBHOOK_SECRET'),
    'timezone' => 'Europe/Moscow',
    'models' => [
        'vision' => 'claude-haiku-4-5',
        'chat' => 'claude-sonnet-5',
    ],
];
```

- [ ] **Step 2: Миграция**

```php
<?php
// database/migrations/2026_07_11_100000_create_nutrition_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('nutrition_meals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('type'); // breakfast|lunch|snack|dinner
            $table->dateTime('window_start')->nullable();
            $table->dateTime('window_end')->nullable();
            $table->dateTime('eaten_at')->nullable();
            $table->string('photo_file_id')->nullable();
            $table->text('ai_feedback')->nullable();
            $table->string('status')->default('pending'); // pending|eaten|skipped|missed
            $table->timestamps();
            $table->unique(['date', 'type']);
        });

        Schema::create('nutrition_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('type'); // weight|steps|water
            $table->decimal('value', 8, 2);
            $table->timestamps();
            $table->unique(['date', 'type']);
        });

        Schema::create('nutrition_messages', function (Blueprint $table) {
            $table->id();
            $table->string('direction'); // in|out
            $table->string('kind')->nullable(); // text|photo|command|reminder|summary|checkup|topic|...
            $table->text('content')->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('nutrition_topics', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('file_path')->nullable();
            $table->text('intro')->nullable();
            $table->unsignedInteger('position');
            $table->date('scheduled_on')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nutrition_sent_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->dateTime('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_sent_events');
        Schema::dropIfExists('nutrition_topics');
        Schema::dropIfExists('nutrition_messages');
        Schema::dropIfExists('nutrition_metrics');
        Schema::dropIfExists('nutrition_meals');
        Schema::dropIfExists('nutrition_settings');
    }
};
```

- [ ] **Step 3: Модели** — каждая по образцу `GameContent` (PHPDoc свойств, `casts()`, `$fillable` со всеми колонками). Особые:

```php
<?php
// app/Models/NutritionSentEvent.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class NutritionSentEvent extends Model
{
    protected $fillable = ['event_key', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /**
     * Выполнить $fn ровно один раз на event_key (unique-индекс защищает от гонок).
     */
    public static function once(string $key, \Closure $fn): bool
    {
        try {
            static::create(['event_key' => $key, 'sent_at' => Carbon::now()]);
        } catch (QueryException) {
            return false; // уже выполнялось
        }

        $fn();

        return true;
    }
}
```

`NutritionMeal`: casts `date => 'date'`, `window_start|window_end|eaten_at => 'datetime'`. `NutritionMetric`: `date => 'date'`, `value => 'float'`. `NutritionMessage`: `meta => 'array'`. `NutritionTopic`: `scheduled_on => 'date'`, `sent_at => 'datetime'`. `NutritionSetting`: `value => 'array'`... нет — value хранит любой json-скаляр/массив, поэтому cast `value => 'json'`.

- [ ] **Step 4: Settings-обёртка**

```php
<?php
// app/Support/Nutrition/Settings.php
namespace App\Support\Nutrition;

use App\Models\NutritionSetting;

class Settings
{
    /** Дефолты программы (из плана TriDaily). */
    public const DEFAULTS = [
        'wake_time' => '07:00',
        'sleep_time' => '23:00',
        'default_windows' => [
            'breakfast' => ['start' => '07:30', 'end' => '08:30'],
            'lunch' => ['start' => '11:00', 'end' => '12:30'],
            'snack' => ['start' => '14:40', 'end' => '16:10'],
            'dinner' => ['start' => '19:00', 'end' => '20:00'],
        ],
        'steps_target' => 7000,
        'phase' => 'program', // program|maintenance
        'program_started_on' => null,
        'portion_adjustment' => 0, // проценты, напр. +15
        'pending_adjustments' => null,
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = NutritionSetting::query()->where('key', $key)->first();
        if ($row !== null) {
            return $row->value;
        }

        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function set(string $key, mixed $value): void
    {
        NutritionSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
```

- [ ] **Step 5: Тест**

```php
<?php
// tests/Feature/Nutrition/SettingsTest.php
use App\Models\NutritionSentEvent;
use App\Support\Nutrition\Settings;

it('returns defaults when setting is absent', function () {
    expect(Settings::get('steps_target'))->toBe(7000)
        ->and(Settings::get('default_windows'))->toHaveKey('breakfast');
});

it('stores and reads back values', function () {
    Settings::set('steps_target', 9000);
    expect(Settings::get('steps_target'))->toBe(9000);
});

it('runs sent-event callback only once per key', function () {
    $runs = 0;
    NutritionSentEvent::once('k1', function () use (&$runs) { $runs++; });
    NutritionSentEvent::once('k1', function () use (&$runs) { $runs++; });
    expect($runs)->toBe(1);
});
```

- [ ] **Step 6:** `php artisan test --filter=SettingsTest` — PASS (feature-тесты используют RefreshDatabase из `tests/Pest.php`; проверить, что там уже подключён `RefreshDatabase` для Feature, как в существующих тестах — иначе добавить `uses(RefreshDatabase::class)` в файл теста).
- [ ] **Step 7:** `php artisan migrate` на проде (MySQL), `vendor/bin/pint --dirty`, commit `feat(nutrition): tables, models, settings`.

---

### Task 2: MealPlan — чистая логика динамических окон (TDD)

**Files:**
- Create: `app/Support/Nutrition/MealPlan.php`
- Test: `tests/Unit/Nutrition/MealPlanTest.php`

**Interfaces:**
- Produces: `MealPlan::TYPES = ['breakfast','lunch','snack','dinner']`, `MealPlan::LABELS` (рус. названия).
- Produces: `MealPlan::windows(CarbonImmutable $date, array $defaultWindows, array $facts, string $sleepTime): array` где `$facts` — `['breakfast' => ['status' => 'eaten', 'eaten_at' => CarbonImmutable], 'lunch' => ['status' => 'pending', 'eaten_at' => null], ...]`; возвращает `['lunch' => ['start' => CarbonImmutable, 'end' => CarbonImmutable], ...]` только для приёмов со статусом pending.

Правила (из спеки):
1. Завтрак: дефолтное окно.
2. Следующий pending-приём: якорь = `eaten_at` предыдущего, окно `[якорь+3ч, якорь+4ч]`.
3. Если предыдущий skipped/missed (нет eaten_at) — якорь = конец его расчётного окна, окно `[якорь+3ч, якорь+4ч]`.
4. Если предыдущий ещё pending — у текущего окно тоже расчётное цепочкой от предыдущего расчётного: якорь = start предыдущего окна (ожидаемое время еды), окно `[якорь+3ч, якорь+4ч]`, но не раньше дефолтного окна этого приёма (берём max по start, end = start+1ч... нет). Упрощение: пока предыдущий pending и его окно ещё не закончилось — у последующих остаются дефолтные окна; пересчёт цепочки происходит только от факта (eaten) или от провала окна (missed). Это правило и тестируем.
5. Ужин: `end = min(end, sleep − 2ч)`; если `start > sleep − 2ч`, то `start = sleep − 3ч`, `end = sleep − 2ч`.

- [ ] **Step 1: Тесты (пишем до реализации)**

```php
<?php
// tests/Unit/Nutrition/MealPlanTest.php
use App\Support\Nutrition\MealPlan;
use Carbon\CarbonImmutable;

function mskDate(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-13 00:00', 'Europe/Moscow');
}

function defaults(): array
{
    return [
        'breakfast' => ['start' => '07:30', 'end' => '08:30'],
        'lunch' => ['start' => '11:00', 'end' => '12:30'],
        'snack' => ['start' => '14:40', 'end' => '16:10'],
        'dinner' => ['start' => '19:00', 'end' => '20:00'],
    ];
}

function pendingAll(): array
{
    $facts = [];
    foreach (MealPlan::TYPES as $t) {
        $facts[$t] = ['status' => 'pending', 'eaten_at' => null];
    }
    return $facts;
}

it('uses default windows when nothing eaten yet', function () {
    $w = MealPlan::windows(mskDate(), defaults(), pendingAll(), '23:00');
    expect($w['breakfast']['start']->format('H:i'))->toBe('07:30')
        ->and($w['lunch']['start']->format('H:i'))->toBe('11:00')
        ->and($w['dinner']['end']->format('H:i'))->toBe('20:00');
});

it('shifts next meal to eaten_at + 3..4h', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(8, 20)];
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w)->not->toHaveKey('breakfast')
        ->and($w['lunch']['start']->format('H:i'))->toBe('11:20')
        ->and($w['lunch']['end']->format('H:i'))->toBe('12:20');
});

it('cascades late lunch into snack and dinner', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(8, 0)];
    $facts['lunch'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(13, 30)];
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w['snack']['start']->format('H:i'))->toBe('16:30')
        ->and($w['dinner']['start']->format('H:i'))->toBe('19:30');
});

it('recalculates from window end when a meal is skipped', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(8, 0)];
    $facts['lunch'] = ['status' => 'skipped', 'eaten_at' => null];
    // окно обеда после завтрака в 8:00 = 11:00–12:00; пропуск → полдник от 12:00
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w['snack']['start']->format('H:i'))->toBe('15:00');
});

it('clamps dinner to 2-3 hours before sleep', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(9, 0)];
    $facts['lunch'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(13, 0)];
    $facts['snack'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(17, 30)];
    // ужин 20:30–21:30, но сон 23:00 → end ≤ 21:00
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w['dinner']['start']->format('H:i'))->toBe('20:30')
        ->and($w['dinner']['end']->format('H:i'))->toBe('21:00');
});

it('moves dinner start back when chain pushes it past sleep-2h', function () {
    $facts = pendingAll();
    $facts['breakfast'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(10, 0)];
    $facts['lunch'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(14, 30)];
    $facts['snack'] = ['status' => 'eaten', 'eaten_at' => mskDate()->setTime(18, 30)];
    // цепочка дала бы ужин 21:30+, сон 23:00 → окно 20:00–21:00
    $w = MealPlan::windows(mskDate(), defaults(), $facts, '23:00');
    expect($w['dinner']['start']->format('H:i'))->toBe('20:00')
        ->and($w['dinner']['end']->format('H:i'))->toBe('21:00');
});
```

- [ ] **Step 2:** `php artisan test --filter=MealPlanTest` — FAIL (класс не существует).
- [ ] **Step 3: Реализация**

```php
<?php
// app/Support/Nutrition/MealPlan.php
namespace App\Support\Nutrition;

use Carbon\CarbonImmutable;

class MealPlan
{
    public const TYPES = ['breakfast', 'lunch', 'snack', 'dinner'];

    public const LABELS = [
        'breakfast' => 'Завтрак',
        'lunch' => 'Обед',
        'snack' => 'Полдник',
        'dinner' => 'Ужин',
    ];

    /** Состав приёма по схеме — используется в напоминаниях и промптах. */
    public const COMPOSITION = [
        'breakfast' => 'Сложные углеводы (с кулак) + фрукт/горсть ягод или овощи',
        'lunch' => 'Правило тарелки: сложные углеводы (3–5 ст. л.) + белок с ладонь + свежий салат полтарелки (мин. 3 вида овощей) + фрукт',
        'snack' => 'Белок с ладонь + припущенные овощи al dente или салат полтарелки + вкусняшка (углеводистая)',
        'dinner' => 'Только белок с ладонь (кроме красного мяса), при голоде + овощи',
    ];

    /**
     * @param  array<string, array{start: string, end: string}>  $defaultWindows
     * @param  array<string, array{status: string, eaten_at: ?CarbonImmutable}>  $facts
     * @return array<string, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function windows(CarbonImmutable $date, array $defaultWindows, array $facts, string $sleepTime): array
    {
        $tz = $date->getTimezone();
        $at = fn (string $hhmm) => $date->setTimeFromTimeString($hhmm);
        $sleep = $at($sleepTime);

        $result = [];
        $anchor = null;       // CarbonImmutable|null — время, от которого считать следующий приём
        $chainBroken = false; // true после первого факта (eaten/skipped/missed)

        foreach (self::TYPES as $type) {
            $fact = $facts[$type];
            $default = [
                'start' => $at($defaultWindows[$type]['start']),
                'end' => $at($defaultWindows[$type]['end']),
            ];

            $window = ($chainBroken && $anchor !== null)
                ? ['start' => $anchor->addHours(3), 'end' => $anchor->addHours(4)]
                : $default;

            if ($type === 'dinner') {
                $latestEnd = $sleep->subHours(2);
                if ($window['start']->greaterThan($latestEnd)) {
                    $window = ['start' => $sleep->subHours(3), 'end' => $latestEnd];
                } elseif ($window['end']->greaterThan($latestEnd)) {
                    $window['end'] = $latestEnd;
                }
            }

            if ($fact['status'] === 'eaten' && $fact['eaten_at'] !== null) {
                $anchor = $fact['eaten_at'];
                $chainBroken = true;
                continue; // окно съеденного приёма не возвращаем
            }

            if (in_array($fact['status'], ['skipped', 'missed'], true)) {
                $anchor = $window['end'];
                $chainBroken = true;
                continue;
            }

            // pending
            $result[$type] = $window;
            $anchor = $window['start'];
        }

        return $result;
    }
}
```

- [ ] **Step 4:** `php artisan test --filter=MealPlanTest` — PASS. Если тест «skipped» не сходится — сверить арифметику (обед после завтрака 8:00 → окно 11:00–12:00, конец 12:00 + 3ч = полдник 15:00) и поправить реализацию, а не тест.
- [ ] **Step 5:** pint, commit `feat(nutrition): dynamic meal windows logic`.

---

### Task 3: Planner — окна в БД + дневной цикл

**Files:**
- Create: `app/Support/Nutrition/Planner.php`
- Test: `tests/Feature/Nutrition/PlannerTest.php`

**Interfaces:**
- Consumes: `MealPlan::windows(...)`, `Settings::get(...)`, модель `NutritionMeal`.
- Produces: `Planner::ensureDay(CarbonImmutable $date): void` — создаёт 4 строки nutrition_meals на дату (если нет) с дефолтными окнами.
- Produces: `Planner::recalculate(CarbonImmutable $date): void` — перечитывает факты из БД, вызывает `MealPlan::windows`, обновляет `window_start/window_end` pending-строк.
- Produces: `Planner::markEaten(NutritionMeal $meal, CarbonImmutable $at, ?string $photoFileId, ?string $feedback): void` — ставит eaten + recalculate.
- Produces: `Planner::currentMeal(CarbonImmutable $now): ?NutritionMeal` — pending-приём, чьё окно содержит $now или ближайшее следующее окно (для привязки фото).
- Produces: `Planner::markMissed(CarbonImmutable $now): void` — pending-приёмы с `window_end < now - 90 мин` переводит в missed + recalculate (вызывается из тика).

- [ ] **Step 1: Тесты** — 4 сценария: `ensureDay` создаёт 4 строки с окнами; `markEaten` завтрака в 8:20 сдвигает окно обеда на 11:20; `currentMeal` в 11:30 возвращает lunch; `markMissed` в 14:10 (обед не съеден, окно до 12:30) помечает lunch missed и сдвигает polдник. Пример первого:

```php
<?php
// tests/Feature/Nutrition/PlannerTest.php
use App\Models\NutritionMeal;
use App\Support\Nutrition\Planner;
use Carbon\CarbonImmutable;

it('ensures four meals with default windows', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($date);
    Planner::ensureDay($date); // идемпотентно

    expect(NutritionMeal::count())->toBe(4)
        ->and(NutritionMeal::where('type', 'lunch')->first()->window_start->format('H:i'))->toBe('11:00');
});

it('recalculates downstream windows after eating', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($date);
    $breakfast = NutritionMeal::where('type', 'breakfast')->first();

    Planner::markEaten($breakfast, $date->setTime(8, 20), null, 'ok');

    expect(NutritionMeal::where('type', 'lunch')->first()->window_start->format('H:i'))->toBe('11:20');
});

it('finds current meal by time', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($date);

    $meal = Planner::currentMeal($date->setTime(11, 30));
    expect($meal->type)->toBe('lunch');
});

it('marks overdue meals missed and shifts the rest', function () {
    $date = CarbonImmutable::parse('2026-07-13', 'Europe/Moscow');
    Planner::ensureDay($date);
    NutritionMeal::where('type', 'breakfast')->update(['status' => 'eaten', 'eaten_at' => $date->setTime(8, 0)]);
    Planner::recalculate($date); // обед 11:00–12:00

    Planner::markMissed($date->setTime(13, 45)); // 12:00 + 90 мин прошло

    expect(NutritionMeal::where('type', 'lunch')->first()->status)->toBe('missed')
        ->and(NutritionMeal::where('type', 'snack')->first()->window_start->format('H:i'))->toBe('15:00');
});
```

- [ ] **Step 2:** run — FAIL. **Step 3: Реализация** — `Planner` со статическими методами; факты собираются как в интерфейсе `MealPlan`; `eaten_at` из БД конвертировать в `CarbonImmutable` с таймзоной Europe/Moscow (`CarbonImmutable::parse($meal->eaten_at, 'Europe/Moscow')` — datetime хранится наивно). `currentMeal`: первый pending с `window_end >= now`, иначе null. `markMissed`: порог `window_end < now->subMinutes(90)`.
- [ ] **Step 4:** run — PASS. **Step 5:** pint, commit `feat(nutrition): planner with db-backed windows`.

---

### Task 4: TelegramClient + логирование сообщений

**Files:**
- Create: `app/Support/Nutrition/TelegramClient.php`
- Test: `tests/Feature/Nutrition/TelegramClientTest.php` (Http::fake)

**Interfaces:**
- Produces (все методы инстансные, `new TelegramClient()` берёт токен/чат из config):
  - `send(string $text, ?array $inlineKeyboard = null, string $kind = 'text'): void` — sendMessage c parse_mode HTML, лог в `nutrition_messages` (direction=out).
  - `sendDocument(string $absolutePath, ?string $caption = null): void`.
  - `answerCallback(string $callbackQueryId): void`.
  - `downloadPhotoBase64(string $fileId): ?array` — getFile → скачать → `['media_type' => 'image/jpeg', 'data' => <base64>]` (null при ошибке).
  - `api(string $method, array $params = []): ?array` — общий POST `https://api.telegram.org/bot{token}/{method}`, timeout 30, retry(2, 500), возвращает `result` или null; ошибки в `Log::warning`.

- [ ] **Step 1: Тесты** — `Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])])`; проверить: `send()` шлёт chat_id и text и создаёт строку в nutrition_messages с telegram_message_id=5; `downloadPhotoBase64` — fake двух вызовов (getFile → file_path, file download → бинарь) возвращает base64.
- [ ] **Step 2:** FAIL → **Step 3:** реализация → **Step 4:** PASS.
- [ ] **Step 5:** pint, commit `feat(nutrition): telegram client`.

---

### Task 5: База знаний и Claude-клиент

**Files:**
- Create: `resources/nutrition/knowledge/01-program.md`, `02-forbidden.md`, `03-style.md`, `04-profile.md` — **содержимое пишет координатор** (выжимка из PDF «Глеб питание», анализов и чата; субагент создаёт файлы-заглушки только если координатор не передал текст — тогда пометить TODO координатору в отчёте задачи).
- Create: `app/Support/Nutrition/Claude.php`
- Create: `app/Support/Nutrition/PromptBuilder.php`
- Test: `tests/Feature/Nutrition/ClaudeTest.php`, `tests/Feature/Nutrition/PromptBuilderTest.php`

**Interfaces:**
- Produces: `Claude::text(array $userContent, string $model, int $maxTokens = 1024): ?string` — POST `https://api.anthropic.com/v1/messages` c headers `x-api-key: config('nutrition.anthropic_key')`, `anthropic-version: 2023-06-01`, body `{model, max_tokens, system: PromptBuilder::system(), messages: [{role: 'user', content: $userContent}]}`; timeout 90, retry(2, 2000, throw: false). Возвращает конкатенацию text-блоков ответа или null (все ошибки — Log::warning + null, НЕ исключение).
- Produces: `Claude::vision(array $image, string $prompt, int $maxTokens = 400): ?string` — user content = `[['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['media_type'], 'data' => $image['data']]], ['type' => 'text', 'text' => $prompt]]`, модель `config('nutrition.models.vision')`.
- Produces: `PromptBuilder::system(): string` — персона нутрициолога + содержимое всех `resources/nutrition/knowledge/*.md` (в сортировке имён).
- Produces: `PromptBuilder::dayContext(CarbonImmutable $date): string` — текстовый блок: фаза/день программы, настройки (шаги, порции, окна), приёмы за дату (тип, окно, статус, время, фидбек), метрики за 7 дней, последние 30 строк nutrition_messages.

Персона (первая часть system, дословно):

```
Ты — персональный нутрициолог Глеба, работающий по программе TriDaily (10 недель + поддержка).
Ты совмещаешь две роли из настоящей команды: тёплые короткие реакции ассистента
(«Идеально! 🙌🏼», «Приятного аппетита!», «Поели полдник?☺️», эмодзи 👌🏻🙌🏼☺️⏰)
и экспертные объяснения главного нутрициолога — всегда объясняешь «почему» через
физиологию (инсулин, метаболизм, клетчатка), уверенно и по-дружески на «ты».
Отвечай кратко: реакции на еду — 1–3 предложения; ответы на вопросы — до 6.
Не выдумывай правил, которых нет в базе знаний. Не назначай лекарства и добавки;
при медицинских вопросах вне питания советуй врача. Пиши по-русски.
```

- [ ] **Step 1: Тесты.** `ClaudeTest`: `Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Привет!']]])])` → `Claude::text(...)` возвращает 'Привет!'; при `Http::response([], 500)` → null (без исключения); проверить, что запрос содержит header `x-api-key` и model. `PromptBuilderTest`: `system()` содержит строку 'TriDaily' и текст из 01-program.md; `dayContext()` содержит label приёма и метрику веса (посеять NutritionMeal + NutritionMetric).
- [ ] **Step 2:** FAIL → **Step 3:** реализация + файлы знаний (координатор передаёт текст четырёх .md при постановке задачи) → **Step 4:** PASS.
- [ ] **Step 5:** pint, commit `feat(nutrition): knowledge base and claude client`.

---

### Task 6: Webhook: маршрут, контроллер, job-диспетчер

**Files:**
- Modify: `routes/web.php` (добавить route), `bootstrap/app.php` (CSRF-исключение)
- Create: `app/Http/Controllers/NutritionBotController.php`
- Create: `app/Jobs/ProcessNutritionUpdate.php` (каркас: whitelist + маршрутизация, обработчики — Task 7)
- Test: `tests/Feature/Nutrition/WebhookTest.php`

**Interfaces:**
- Produces: `POST /nutrition-bot/webhook` — без auth, всегда 200 `{'ok': true}`; при неверном/отсутствующем header `X-Telegram-Bot-Api-Secret-Token` — 403.
- Produces: `ProcessNutritionUpdate implements ShouldQueue`, конструктор `public function __construct(public array $update)`. `handle()`: если `from.id` != `config('nutrition.chat_id')` → ответить «Это персональный бот 🙂» и выйти; иначе лог входящего в nutrition_messages и маршрутизация: callback_query → `HandleCallback`, photo → `HandlePhoto`, text `/…` → `HandleCommand`, текст из чисел/чисел с пробелами и запятыми → `HandleNumbers`, прочее → `HandleQuestion` (Action-классы — Task 7; в этой задаче job вызывает их через `app(...)->handle($update)`, а сами классы создаются пустыми no-op заглушками, которые Task 7 наполнит).

Route (в конец `routes/web.php`, до `require settings.php` не важно):

```php
Route::post('/nutrition-bot/webhook', [NutritionBotController::class, 'webhook'])
    ->name('nutrition.webhook');
```

CSRF-исключение в `bootstrap/app.php` внутри `withMiddleware`:

```php
$middleware->validateCsrfTokens(except: ['nutrition-bot/webhook']);
```

Контроллер:

```php
<?php
namespace App\Http\Controllers;

use App\Jobs\ProcessNutritionUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NutritionBotController extends Controller
{
    public function webhook(Request $request): JsonResponse
    {
        abort_unless(
            hash_equals((string) config('nutrition.webhook_secret'), (string) $request->header('X-Telegram-Bot-Api-Secret-Token')),
            403
        );

        ProcessNutritionUpdate::dispatch($request->all());

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 1: Тесты**: без секрета → 403; с секретом (`config(['nutrition.webhook_secret' => 's3cret'])`, header) → 200 и `Queue::fake()` assertPushed; job с чужим from.id → `Http::fake` и проверка что отправлен ответ про персонального бота и nutrition_messages не содержит запись direction=in.
- [ ] **Step 2:** FAIL → **Step 3:** реализация → **Step 4:** PASS → **Step 5:** pint, commit `feat(nutrition): webhook endpoint and update job`.

---

### Task 7: Обработчики входящих (Actions)

**Files:**
- Create: `app/Actions/Nutrition/HandleCommand.php`, `HandlePhoto.php`, `HandleNumbers.php`, `HandleQuestion.php`, `HandleCallback.php`
- Test: `tests/Feature/Nutrition/HandlersTest.php`

**Interfaces:**
- Consumes: `Planner`, `TelegramClient`, `Claude`, `PromptBuilder`, `Settings`, `MealPlan::LABELS/COMPOSITION`.
- Каждый Action: `public function handle(array $update): void`.

Поведение:

**HandleCommand** (`$text = trim($message['text'])`, первое слово):
- `/start`, `/help` — краткая справка по командам и режиму дня.
- `/today` — `Planner::ensureDay(today)`, список приёмов: статус-эмодзи (✅/⏳/⏭/❌), label, окно `H:i–H:i`, для eaten — время; плюс текущие цели (шаги, вода 2л, отбой).
- `/stats` — вес: последние 8 записей `дата → значение` + дельта первой/последней; шаги за 7 дней (среднее/цель); вода за 7 дней.
- `/weight 82.3`, `/steps 11200`, `/water 2.5` — upsert `NutritionMetric` на сегодня, подтверждение («Записал: вес 82.3 кг 👌🏻»). Невалидное число — подсказка формата.
- `/skip` — ближайший pending-приём → skipped, `Planner::recalculate`, ответ с новым расписанием остатка дня.
- `/checkup` — как воскресный чек-ап (см. Task 8, вызывает тот же `RunCheckup::handle(onDemand: true)`).
- `/settings` — показать wake/sleep, окна, цель шагов, фазу, поправку порций.

**HandlePhoto**:
1. `Planner::ensureDay(today)`; `$meal = Planner::currentMeal(now)`.
2. Если последняя исходящая nutrition_messages kind == 'metrics_request' и сегодня нет metrics steps → это скрин шагомера: `Claude::vision($img, 'На скриншоте трекер активности. Извлеки число шагов за день. Ответь ТОЛЬКО числом, без текста. Если шагов нет — ответь 0.')` → распарсить int → сохранить метрику steps, ответить.
3. Иначе фото еды: скачать через `TelegramClient::downloadPhotoBase64` (берём `photo[последний].file_id` — максимальное разрешение); промпт vision: тип приёма, `MealPlan::COMPOSITION[type]`, запрещёнка (краткий список), поправка порций из Settings; просим ответ в стиле Насти 1–3 предложения. `Planner::markEaten($meal, now, $fileId, $feedback)`; если `now < window_start - 30 мин` от прошлого приёма меньше 2.5ч — логика добавляет к ответу «⚠️ Меньше 2,5 ч от прошлого приёма — в следующий раз чуть позже». Если `Planner::currentMeal` вернул null (все съедены/поздно) — ответ «Перекусов на программе нет 👌🏻 До следующего приёма — вода/чай/кофе без всего» без ИИ и без записи приёма.
4. Fallback: если Claude вернул null — `markEaten` всё равно, ответ «Записал приём 👌🏻 Разбор пришлю позже».

**HandleNumbers** (`preg_match_all('/\d+(?:[.,]\d+)?/', ...)`):
- Если последняя исходящая kind == 'weight_request' → первое число = вес (валидный диапазон 40–150).
- Если kind == 'metrics_request' → первое число = шаги (диапазон 0–100000), второе (если есть, ≤10) = вода в литрах.
- Иначе → передать в HandleQuestion.

**HandleQuestion**: `Claude::text([['type' => 'text', 'text' => PromptBuilder::dayContext(today) . "\n\nВопрос Глеба: " . $text]], config('nutrition.models.chat'), 800)`; fallback null → «Не смог сейчас ответить, попробуй ещё раз чуть позже 🙏».

**HandleCallback** (`callback_query.data`):
- `ate:{type}` — mark eaten без фото (feedback null), ответ + `answerCallback`.
- `skip:{type}` — skipped + recalculate.
- `adj:yes` — применить `Settings::get('pending_adjustments')` (ключи `steps_target`, `portion_adjustment`, `sleep_time` — только присутствующие), очистить pending, подтвердить. `adj:no` — очистить pending, «Ок, оставляем как есть 👌🏻».

- [ ] **Step 1: Тесты** (Http::fake для telegram+anthropic): `/weight 82.3` создаёт метрику; `/today` шлёт сообщение с «Обед»; фото при текущем окне обеда → meal eaten + ai_feedback сохранён (fake anthropic отвечает 'Идеально! 🙌🏼'); фото при null-приёме → ответ содержит «Перекусов»; число после weight_request → метрика weight; callback `ate:lunch` → lunch eaten; callback `adj:yes` при pending_adjustments `{steps_target: 9000}` → Settings steps_target == 9000.
- [ ] **Step 2:** FAIL → **Step 3:** реализация (+ job из Task 6 переключить с заглушек на реальные классы) → **Step 4:** PASS → **Step 5:** pint, commit `feat(nutrition): incoming update handlers`.

---

### Task 8: Тик планировщика, саммари и чек-апы

**Files:**
- Create: `app/Console/Commands/NutritionTick.php` (signature `nutrition:tick {--at=}` — `--at=2026-07-13 08:00` для симуляции: подменяет «сейчас», в этом режиме сообщения не шлются, а выводятся в консоль)
- Create: `app/Actions/Nutrition/RunDaySummary.php`, `app/Actions/Nutrition/RunCheckup.php`, `app/Actions/Nutrition/SendTopic.php`
- Modify: `routes/console.php` (`Schedule::command('nutrition:tick')->everyMinute();` + `use Illuminate\Support\Facades\Schedule;`)
- Test: `tests/Feature/Nutrition/TickTest.php`

**Interfaces:**
- Consumes: всё предыдущее.
- Produces: `nutrition:tick` — идемпотентные события через `NutritionSentEvent::once("{Y-m-d}:{event}", ...)`.

Логика тика (`$now` = Europe/Moscow, `$d = $now->format('Y-m-d')`):
1. `Planner::ensureDay`, `Planner::markMissed($now)`.
2. `07:30`+ → `once("$d:greeting")`: приветствие + план дня; по чт/вс перед ним `once("$d:weight_request")`: «Утреннее взвешивание натощак ⚖️ Пришли вес числом» (kind='weight_request'). В фазе maintenance взвешивание только вс.
3. Для каждого pending-приёма: `now >= window_start` → `once("$d:reminder:{type}")`: «⏰ {Label} {H:i}–{H:i}. {COMPOSITION}» (+кнопки inline: «✅ Поел» `ate:{type}` / «⏭ Пропускаю» `skip:{type}`). `now >= window_end + 30 мин` и status pending → `once("$d:followup:{type}")`: «Поели {label}? ☺️» (кнопки те же). В фазе maintenance followup не шлём.
4. `21:30`+ → `once("$d:metrics_request")` (kind='metrics_request'): «Сколько шагов сегодня? Пришли число или скрин шагомера 🙌🏼 И сколько воды (л)?».
5. `22:30`+ → `once("$d:summary")` → `RunDaySummary`.
6. Вс `20:00`+ → `once("$d:checkup")` → `RunCheckup`.
7. `10:30`+ → topic с `scheduled_on == сегодня` и `sent_at == null` (фаза program) → `SendTopic`.
8. Переход фаз: если `phase == program` и `program_started_on + 70 дней <= сегодня` → `once("$d:graduation")`: поздравление + запрос финальных замеров, `Settings::set('phase', 'maintenance')`.

**RunDaySummary**: `Claude::text` (модель chat, 600 токенов) с dayContext + инструкция: «Подведи итог дня: что по плану, что нарушено (интервалы, пропуски, запрещёнка из фидбеков, шаги/вода против цели), 1 фокус на завтра. Тёпло, 4–6 предложений». Fallback: детерминированная сводка (счётчики съедено/пропущено, шаги/цель).

**RunCheckup** (`handle(bool $onDemand = false)`): контекст за 14 дней (вес-тренд, съедено/пропущено по дням, среднее шагов, вода) + инструкция вернуть СТРОГО JSON:

```json
{"message": "текст разбора 5-8 предложений", "adjustments": {"steps_target": 9000} }
```

(`adjustments` может быть `null`; допустимые ключи: steps_target int, portion_adjustment int −30..30, sleep_time "HH:MM"). Парсить `json_decode` (обрезав возможные ```-заборы); если JSON не распарсился — отправить сырой текст без корректировок. При наличии adjustments: `Settings::set('pending_adjustments', ...)` и кнопки «Применить ✅» `adj:yes` / «Не надо» `adj:no`.

**SendTopic**: `sendDocument(storage_path('app/nutrition/materials/'.$topic->file_path), $topic->intro)`; если файла нет — только intro текстом; `sent_at = now`.

- [ ] **Step 1: Тесты** (Http::fake, `travelTo` московского времени): тик в 07:35 четверга шлёт weight_request и greeting, повторный тик не дублирует (nutrition_messages count не растёт); тик в 11:05 шлёт напоминание про обед один раз; тик в 13:05 (обед не съеден, окно до 12:30) шлёт followup; тик в 22:35 создаёт summary (fake anthropic); тик вс 20:05 шлёт чек-ап и при adjustments в ответе сохраняет pending_adjustments.
- [ ] **Step 2:** FAIL → **Step 3:** реализация → **Step 4:** PASS.
- [ ] **Step 5:** Проверить симуляцию вручную: `php artisan nutrition:tick --at="2026-07-16 07:35"` печатает план сообщений в консоль (dry-run, без отправки и без записи sent_events).
- [ ] **Step 6:** pint, commit `feat(nutrition): scheduler tick, summaries, checkups`.

---

### Task 9: Сидер тем и артизан-команды обслуживания

**Files:**
- Create: `database/seeders/NutritionTopicSeeder.php`
- Create: `app/Console/Commands/NutritionSetWebhook.php` (signature `nutrition:set-webhook`)
- Create: `app/Console/Commands/NutritionStart.php` (signature `nutrition:start-program {date?}`)
- Test: `tests/Feature/Nutrition/TopicSeederTest.php`

**Interfaces:**
- Produces: сидер создаёт 12 тем в порядке программы (метаболизм; интервалы; завтрак; ходьба + окно жиросжигания; вода; сон; молочная продукция + протеин; яйца и хлеб; жиры; как сгорает жир; сборник «Здоровая еда» + закупочный лист; кофеин) с `file_path` = имена PDF из `storage/app/nutrition/materials/` (точные имена файлов передаст координатор — файлы совпадают с папкой «Питание»; intro — 2–3 предложения на тему, пишет субагент по названию темы в стиле Марины). `scheduled_on` = null (проставляется при старте программы).
- Produces: `nutrition:start-program {date?}` — `Settings::set('program_started_on', $date ?? today)`, `phase = program`, раскладывает `scheduled_on` тем: позиции 1–12 → дни 3, 8, 13, 18, 23, 28, 33, 38, 43, 48, 53, 58 от старта (≈2 темы в неделю, кроме выходных не заморачиваемся). Идемпотентно (повторный запуск пересчитывает даты).
- Produces: `nutrition:set-webhook` — вызывает Telegram `setWebhook` с `url = url('/nutrition-bot/webhook')`, `secret_token = config('nutrition.webhook_secret')`, `allowed_updates = ['message','callback_query']`; печатает ответ API.

- [ ] **Step 1: Тест сидера**: 12 строк, позиции 1–12 уникальны; `nutrition:start-program 2026-07-14` → у темы №1 `scheduled_on = 2026-07-17`.
- [ ] **Step 2:** FAIL → **Step 3:** реализация → **Step 4:** PASS → **Step 5:** pint, commit `feat(nutrition): topics seeder and ops commands`.

---

### Task 10: Деплой и сквозная проверка (координатор + пользователь)

Выполняет координатор (не субагент — нужны секреты и локальные PDF):

- [ ] **Step 1:** Добавить в `.env` на сервере: `NUTRITION_BOT_TOKEN`, `ANTHROPIC_API_KEY`, `NUTRITION_WEBHOOK_SECRET` (сгенерировать `openssl rand -hex 24`), `NUTRITION_CHAT_ID` (пока пусто). `php artisan config:cache && php artisan route:cache`.
- [ ] **Step 2:** `php artisan migrate --force`, `php artisan db:seed --class=NutritionTopicSeeder`.
- [ ] **Step 3:** Залить PDF: с локальной машины `scp "/Users/glyuch/vcode/gleb/Питание/"*.pdf gleb@gleb.finance:/home/gleb/gleb.finance/storage/app/nutrition/materials/` (создать каталог), сверить имена с file_path сидера, поправить сидер при расхождениях.
- [ ] **Step 4:** `php artisan nutrition:set-webhook` — убедиться `{"ok":true}`.
- [ ] **Step 5:** **Пользователь**: в Forge добавить (а) Scheduler: `php artisan schedule:run` каждую минуту (Forge → Site → Scheduler или стандартный cron-пункт), (б) Daemon/Queue worker: `php artisan queue:work --tries=3 --timeout=120`. Без этого бот не отвечает и не напоминает — сообщить пользователю до теста.
- [ ] **Step 6:** Пользователь пишет боту `/start`. Если `NUTRITION_CHAT_ID` пуст — job логирует from.id в `laravel.log` (предусмотрено в Task 6: при пустом chat_id отвечаем «Привет! Твой ID зафиксирован» и пишем Log::info('nutrition: chat candidate', ['id' => ...])). Координатор вписывает ID в `.env`, `config:cache`, рестарт воркера (`php artisan queue:restart`).
- [ ] **Step 7:** `php artisan nutrition:start-program` (дату старта согласовать с пользователем).
- [ ] **Step 8:** Сквозной прогон с пользователем: `/today`; фото еды → ИИ-разбор; `/weight 82.3`; вопрос текстом → ответ ИИ; `/checkup`; `php artisan nutrition:tick --at="<завтра> 07:35"` — dry-run выглядит правильно.
- [ ] **Step 9:** Финальный commit, обновить PROGRESS.md разделом про бота.

---

## Self-Review Notes

- Спека: все пункты покрыты задачами 1–10, кроме дашборда (фаза 2 — сознательно вне плана) и Apple Health (вне скоупа по спеке).
- «Второй белковый приём ~22:00» из спеки реализуется советом в саммари/фидбеке ИИ (правило есть в базе знаний), отдельной строки приёма в v1 нет — упрощение зафиксировано.
- Замеры (nutrition_measurements) — вводятся через свободный текст/вопрос ИИ и хранятся в бз знаний? Нет: таблица не создаётся в v1; старт/финиш замеры бот запрашивает текстом и сохраняет в nutrition_messages, итоговое сравнение делает ИИ по логу. Упрощение допустимо (YAGNI), при необходимости добавим таблицу позже.
