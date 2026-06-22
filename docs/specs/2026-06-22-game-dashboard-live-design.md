# Живой дашборд результатов ФондыКвест + разделы админки — дизайн-спека

Дата: 2026-06-22
Статус: согласовано (дизайн); готовится план реализации
Проект: gleb.finance (Laravel 13 + Fortify + Inertia React; админка и игра — Blade)
Где живёт: игра `/game` → `resources/views/game.blade.php`, `app/Http/Controllers/GameController.php`; контент в БД `game_contents` (активная JSON-версия); результаты `game_results`, события `game_events`; текущая админка — `app/Http/Controllers/Admin/*` + `resources/views/admin/game/*` за middleware `auth` + `admin` (`users.is_admin`).

## 1. Проблема и цель

Аналитический отчёт по игре существует как **статичная демка** `public/reports/gamedemoresults/index.html` (~25 графиков на Chart.js). Все данные зашиты в объект `const D = {…}` — снимок на 21.06. Числа устаревают, обновлять руками нельзя.

Цель: **живая версия** этого отчёта — тот же дизайн и графики, но `D` считается из БД в реальном времени при каждом заходе. Плюс — навести порядок в админке: вынести дашборды в отдельный раздел `/admin/dashboards/*` (отдельно по игре, отдельно по сайту), всё за логином/паролем (admin-доступ уже есть).

## 2. Принятые решения

| Вопрос | Решение |
|---|---|
| Подход к рендеру | **A** — Blade + Chart.js (CDN), переиспользуем демку почти дословно; `const D = @json($report)` |
| Источник данных | живой расчёт из `game_results` / `game_events` / `game_contents` при каждом запросе (без кеша на старте) |
| Структура URL | дашборды под `/admin/dashboards/{gameresults,site}`; редакторы игры остаются под `/admin/game/*` |
| Старый `/admin/game/stats` | ретайрится → редирект на `/admin/dashboards/gameresults` |
| Лидерборд по игрокам | **сливается** в низ игрового дашборда (admin-only, PII допустим) |
| Сайтовый дашборд | базовый скелет: регистрации во времени, пользователи (всего/verified/admin), активные сессии, разбивка по проектам (игра — проект №1) |
| Дедупликация игроков | **последняя попытка каждого** (max `id` на `user_id`) — как в демке |
| Математика сценария | выносится в `App\Support\Game\Scenario`; `GameController` делегирует туда `simulate`/`benchmarks` (поведение не меняется, прикрыто текущими тестами) |
| Графическая библиотека | Chart.js 4.4.1 + chartjs-plugin-annotation 3.0.1 через CDN (как в демке); самохостинг — позже при желании |
| Статичная демка | `public/reports/gamedemoresults/` остаётся как есть (анонимные агрегаты); не трогаем |

## 3. Архитектура

### 3.1 Маршруты (`routes/web.php`)

Текущая группа `prefix('admin/game')->name('admin.game.')` перестраивается в единую `admin`-группу за `['auth','admin']`:

```php
Route::middleware(['auth','admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/dashboards/gameresults');

    Route::prefix('dashboards')->name('dashboards.')->group(function () {
        Route::get('/gameresults', [GameResultsDashboardController::class, 'index'])->name('gameresults');
        Route::get('/site', [SiteDashboardController::class, 'index'])->name('site');
    });

    Route::prefix('game')->name('game.')->group(function () {
        Route::get('/',         [GameContentController::class, 'edit'])->name('content');
        Route::put('/',         [GameContentController::class, 'update'])->name('content.update');
        Route::get('/survey',   [GameSurveyController::class, 'edit'])->name('survey');
        Route::put('/survey',   [GameSurveyController::class, 'update'])->name('survey.update');
        Route::get('/returns',  [GameReturnsController::class, 'edit'])->name('returns');
        Route::put('/returns',  [GameReturnsController::class, 'update'])->name('returns.update');
        Route::redirect('/stats', '/admin/dashboards/gameresults'); // ретайр
    });
});
```

Имена маршрутов редакторов (`admin.game.content`/`survey`/`returns` + `.update`) сохраняются — вьюхи на них ссылаются. Маршрут `admin.game.stats` удаляется (был только в навигации — заменяется ссылками на дашборды).

### 3.2 Контроллеры

Плоский неймспейс `App\Http\Controllers\Admin\` (как у существующих):

- `GameResultsDashboardController@index` → `view('admin.dashboards.gameresults', ['report' => app(BuildGameResultsReport::class)()])`.
- `SiteDashboardController@index` → `view('admin.dashboards.site', ['report' => app(BuildSiteReport::class)()])`.

Контроллеры тонкие: вся аналитика — в action-классах.

### 3.3 Навигация (общий лэйаут)

Новый общий шелл `resources/views/admin/layout.blade.php` (на основе текущего `admin/game/layout.blade.php`) с двумя группами в навбаре:

- **Дашборды**: «Игра · результаты» (`admin.dashboards.gameresults`), «Сайт» (`admin.dashboards.site`)
- **Игра**: «Контент» (`admin.game.content`), «Опрос» (`admin.game.survey`), «Доходности» (`admin.game.returns`)
- справа: «Открыть игру →» (`/game`, target=_blank)

Существующие `admin/game/{content,survey,returns}.blade.php` переключаются с `@extends('admin.game.layout')` на `@extends('admin.layout')`. Старый `admin/game/layout.blade.php` удаляется; `admin/game/stats.blade.php` удаляется (логика лидерборда переезжает в игровой дашборд).

## 4. Бэкенд: расчёт данных

### 4.1 `App\Support\Game\Scenario` (вынос математики)

Обёртка над `content.data`, единый источник правды по сценарию. Конструктор принимает `array $data`; статический `Scenario::current()` берёт `GameContent::current()->data`.

| Метод | Возвращает |
|---|---|
| `instruments(): array` | ключи инструментов из `data.choices[].k` (фолбэк — 5 канонических) |
| `quarterCount(): int` | `count(data.years)` |
| `returns(): array` | `[i => [q0..qN-1]]` доходности (доли) из `years[q].ret[i]` |
| `rate(): array` / `infl(): array` | `years[q].rate` / `years[q].infl` |
| `labels(): array` | `[i => label]` метки инструментов из `choices[].t` |
| `benchmarks(): array{bank:int,max:int}` | как сейчас `GameController::benchmarks()` (форвард-факторы, тайминг q+1) |
| `optimumPerQuarter(): array` | `[q => key]` инструмент с макс. форвард-фактором `Π_{t=q+1..N-1}(1+ret[t][i])` (это «Оптимум»/`fwd`) |
| `bestByQuarterReturn(): array` | `[q => key]` инструмент с макс. `ret[q][i]` (это `cur`, подчёркивание в таблице) |

`GameController::simulate()`/`benchmarks()`/`instruments()` рефакторятся на делегирование в `Scenario` — **без изменения чисел**. Контракт прикрыт текущими feature-тестами игры (должны остаться зелёными).

### 4.2 `App\Actions\Game\BuildGameResultsReport` (объект `D`)

Инвокабл `__invoke(): array`, возвращает массив **той же формы**, что демка (см. §5), чтобы JS демки работал без правок. Источники: `game_results`, `game_events`, активный `game_contents` через `Scenario`.

Базовые выборки:
- **Зарегистрировано** `registered = User::count()`.
- **Всего игр** `total_results = GameResult::count()`.
- **Последние попытки**: по одной строке `game_results` с макс. `id` на `user_id` → коллекция `$last` (это «уникальные игроки», `N = $last->count()`).
- Все производные метрики игроков/выбора/опроса считаются по `$last` (как в демке: «по последней попытке»).

### 4.3 `App\Actions\Admin\BuildSiteReport` (сайтовый дашборд)

Инвокабл `__invoke(): array`:
- `users_total`, `users_verified` (`email_verified_at` not null), `users_admin` (`is_admin`).
- `reg_by_day`: регистрации по дням за последние ~30 дней из `users.created_at` (+ итоги «сегодня/7д/30д»).
- `sessions_active`: число строк `sessions` с `last_activity` за последние 15 минут (и за 24 часа).
- `projects`: массив карточек проектов. Сейчас один — `{ key:'game', title:'ФондыКвест', players:N, games, events, href: route('admin.dashboards.gameresults') }`. Структура расширяема под будущие проекты.

## 5. Форма объекта `D` (игровой дашборд)

Каждое поле + источник. Все доходности в `D` — **в процентах** (`ret×100`, округление как в демке). «Надёжно» = `bank+cash`; «Фонды» = `bond+stock+mix`.

| Поле | Расчёт |
|---|---|
| `N`, `total_results`, `registered` | см. §4.2 |
| `BANK`, `MAXB` | `Scenario::benchmarks()` |
| `funnel` | `[label, uniqueUsers]` по событиям: `open`→Открыли, `start`→Начали, `finish`→Дошли до финала, `N`→Завершили+опрос, `open_fund`→Перешли на Финуслуги. Уникальные = `distinct user_id` по событию |
| `beat_bank` | число игроков `$last` с `score_you > BANK` |
| `score_mean`, `score_med` | среднее/медиана `score_you` по `$last` |
| `ratio_mean`, `ratio_med` | среднее/медиана `ratio` по `$last` |
| `score_labels`, `score_hist`, `score_lines{mean,med,bank}` | гистограмма `score_you` (≈8 бинов min..max); линии — дробный индекс бина для mean/med/BANK |
| `ratio_labels`, `ratio_hist`, `ratio_lines{mean,med}` | гистограмма `ratio` бинами по 5 (80–85…); линии mean/med |
| `instr`, `instr_label`, `instr_color` | `instr` из `Scenario::instruments()`; `instr_label` из `choices[].t`; `instr_color` — фикс. карта `{bank:#64748b,cash:#0ea5e9,bond:#22c55e,stock:#ef4444,mix:#a855f7}` |
| `total_choice` | счётчик выборов по инструментам из `$last[].choices[].k` |
| `byq_pct` | `[i => [q→%]]` доля инструмента среди выборов этого квартала (по `$last`, `choices[].quarter`) |
| `safe_q`, `fund_q` | поквартальные % надёжно/фонды |
| `quarters` | `["Q1"…"Q12"]` |
| `ret_q`, `rate_q`, `infl_q` | из `Scenario` (`ret×100`) |
| `qcards` | на квартал `{q,title,type,text,rate,infl,ret{},top,fwd,cur,shares{}}`: `title/type/text` из `years[q].ev.{title,type,text}`; `fwd`=`optimumPerQuarter`; `cur`=`bestByQuarterReturn`; `top`=модальный выбор игроков квартала; `shares`=распределение выборов квартала (%) |
| `stock_share_q`, `bond_share_q` | = `byq_pct.stock` / `byq_pct.bond` |
| `players` | на игрока `$last`: `{uid,ratio,score, fund,stock,safe (доли 0..1), switches, distinct, fwdbest}` — см. §6 |
| `pat` | `{avg_switches, avg_distinct, always_bank, all_stock, avg_fund(%), avg_safe(%), avg_fwdbest}` агрегаты по `players` |
| `cors` | корреляции Пирсона `ratio` × `{fund,stock,fwdbest,switches}` |
| `stock_buckets` | `[["Без акций",n,avgRatio],["До 25% акций",…],["Более 25% акций",…]]` (порог по доле акций) |
| `fwd_best`, `cur_best` | массивы ключей по кварталам из `Scenario` |
| `replays` | игроки с >1 результатом: `{uid,n, first(score по min id), last(score по max id)}` |
| `improved`,`worsened`,`same` | сравнение `last` vs `first` среди переигравших |
| `survey_stats` | на вопрос `{id,question,options,counts{},answered}` по `$last[].survey_answers` |
| `helped_pos`,`ready_pos`,`helped_ans`,`ready_ans` | позитив («Да»/«Скорее да») по `helped` и `ready_funds` |
| `exp_helped`,`exp_ready` | `{Опытные:[pos,total],Новички:[pos,total]}` (опытные = инвестировал/пробовал; новички = «Нет, никогда») × позитив helped/ready |
| `plan_pos`,`readyR_pos` | `{Не обыграли:[pos,total],Обыграли вклад:[pos,total]}` × позитив `plan_invest`/`ready_funds` |
| `prio_rows` | на приоритет `{prio,n, stock(ср. доля акций %), fund(ср. доля фондов %)}` |

Дополнительно к форме демки отчёт возвращает `leaderboard` — массив строк для слитого блока «Игроки» (§8): `{rank, name, email, best_score, ratio, beat_bank, plays}` по `$last` со связью `user` (сорт. по `best_score` desc). Это admin-only расширение, в демке его не было.

`tldr`/`conclusions`/`takeaway`-тексты в демке генерятся в JS из `D` — переносятся как есть (формулы не меняем). Заголовок «Данные за …» меняем на «живые» (текущая дата + `registered`/`total_results`/`N`).

## 6. Определения метрик игрока (по `choices` последней попытки)

`choices` — упорядоченный массив `{k,quarter}` (quarter 1..12). На каждого игрока:
- `fund` = доля выборов в `{bond,stock,mix}` ÷ 12; `stock` = доля `stock` ÷ 12; `safe` = доля `{bank,cash}` ÷ 12.
- `switches` = число `q∈2..12` где `k[q] ≠ k[q-1]`.
- `distinct` = число уникальных `k`.
- `fwdbest` = число `q` где `k[q] == optimumPerQuarter[q]`.

## 7. Сайтовый дашборд (`admin.dashboards.site`)

Blade + Chart.js, тот же тёмный стиль. Блоки:
- KPI-карточки: всего пользователей, verified, админов, активных сессий (15 мин), регистраций за 7д/30д.
- График регистраций по дням (bar/line, ~30 дней).
- Doughnut verified vs unverified.
- Таблица/карточки проектов: ФондыКвест → игроков/игр/событий + ссылка на игровой дашборд. (Скелет под будущие проекты.)

## 8. Фронтенд (Blade-вьюхи)

- `resources/views/admin/dashboards/gameresults.blade.php` — HTML/CSS/JS демки (`public/reports/gamedemoresults/index.html`) с заменой `const D = {…}` на `const D = @json($report)`. Внизу — секция «Игроки» (слитый лидерборд): таблица rank/имя/email/лучший счёт/ratio/обыграл вклад/число игр, данные из `report.leaderboard` (по `$last` + связь `user`). Chart.js/annotation — через CDN (как в демке).
- `resources/views/admin/dashboards/site.blade.php` — §7.
- `resources/views/admin/layout.blade.php` — §3.3.

## 9. Тестирование (Pest)

Доступ:
- гость на `/admin/dashboards/gameresults` → редирект на логин; не-админ → 403; админ → 200.
- то же для `/admin/dashboards/site`.
- `/admin/game/stats` → редирект на `/admin/dashboards/gameresults`.

`BuildGameResultsReport` (фикстуры через фабрики: пользователи, несколько `game_results` с переигровками, события, активный `game_contents`):
- `N` = число уникальных игроков по последней попытке; `total_results` = все игры.
- `funnel` — уникальные пользователи по событиям.
- `beat_bank` — игроки с `score_you > BANK`.
- `survey_stats.counts` — корректная агрегация по последним попыткам.
- `players[].fwdbest`/`switches`/`distinct`/доли — на детерминированном наборе `choices`.
- `qcards[q].fwd` = `Scenario::optimumPerQuarter()[q]`.

`Scenario`:
- `benchmarks()` совпадает с известными значениями сценария id=7 (bank=443 521, max=487 970).
- `optimumPerQuarter()`/`bestByQuarterReturn()` на контролируемой матрице доходностей.

Регресс: существующие feature-тесты `GameController` остаются зелёными после выноса `Scenario` (поведение скоринга не меняется).

`BuildSiteReport`: счётчики users/verified/admin, `reg_by_day` суммируется к total, `projects[0].players == N`.

Запуск: `php artisan test --compact` по затронутым файлам; `vendor/bin/pint --dirty` перед коммитом.

## 10. Вне скоупа / дефолты

- Статичная `public/reports/gamedemoresults/` не трогается.
- Без кеширования отчёта на первом этапе (объёмы малы: 44 польз., 48 игр, 1317 событий). Если станет тяжело — короткий `Cache::remember` точечно, отдельной задачей.
- Самохостинг Chart.js — позже.
- Расширение сайтового дашборда специфичными KPI — отдельной итерацией.

## 11. Манифест файлов

**Новые:**
- `app/Support/Game/Scenario.php`
- `app/Actions/Game/BuildGameResultsReport.php`
- `app/Actions/Admin/BuildSiteReport.php`
- `app/Http/Controllers/Admin/GameResultsDashboardController.php`
- `app/Http/Controllers/Admin/SiteDashboardController.php`
- `resources/views/admin/layout.blade.php`
- `resources/views/admin/dashboards/gameresults.blade.php`
- `resources/views/admin/dashboards/site.blade.php`
- тесты: `tests/Feature/Admin/DashboardAccessTest.php`, `tests/Feature/Admin/GameResultsReportTest.php`, `tests/Unit/Game/ScenarioTest.php`, `tests/Feature/Admin/SiteReportTest.php`

**Изменяемые:**
- `routes/web.php` (перестройка admin-группы)
- `app/Http/Controllers/GameController.php` (делегирование в `Scenario`)
- `resources/views/admin/game/{content,survey,returns}.blade.php` (`@extends('admin.layout')`)

**Удаляемые:**
- `app/Http/Controllers/Admin/GameStatsController.php`
- `resources/views/admin/game/stats.blade.php`
- `resources/views/admin/game/layout.blade.php`
