# Nutrition Bot v2 Implementation Plan (мультипользовательский режим)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Спека: docs/superpowers/specs/2026-07-12-nutrition-bot-v2-design.md.

**Goal:** Профили пользователей с онбордингом по инвайтам, рейтинги приёмов (балл+разбивка), веб-статистика по подписанной ссылке, админка.

**Architecture:** Аддитивные миграции с бэкфиллом Глеба → атомарные переключения кода на per-profile контекст (job → handlers → tick) → онбординг → рейтинги → веб. Каждый коммит оставляет прод рабочим.

## Global Constraints

- SSH `ssh -l gleb gleb.finance`, репо `/home/gleb/gleb.finance`, master. **Коммит = мгновенный деплой для cron-путей** (scheduler читает диск): коммитить только зелёное; в пределах задачи сперва аддитивное, переключение — последним коммитом задачи.
- БЕЗ новых composer/npm зависимостей. Графики — inline SVG React-компоненты. После фронтенд-изменений `npm run build`.
- Секреты в .env; env-чтение только через config. Модели ИИ ровно `claude-haiku-4-5`/`claude-sonnet-5`, без sampling-параметров. HTTP только Http-фасад.
- Наивное московское время (v2 — все пользователи в Europe/Moscow, таймзоны per-user вне скоупа).
- Тесты СТРОГО: `php artisan config:clear && php artisan test ...; php artisan config:cache`. Baseline: 184 passed, 0 failed, 4 skipped — не уменьшать. pint --dirty перед коммитом. Коммиты с `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- После задач с миграциями/конфигом на проде: `php artisan migrate --force` и `config:cache`; после фронта — `npm run build`; после изменений кода очереди — `queue:restart` (координатор или имплементер с явным указанием).
- Существующие guard'ы обязаны пережить рефактор: staleness (setting_request/meal_time_request), already-eaten, анти-бёрст слотов, dry-run rollback, PendingRequest-контексты.

---

### Task 1: Схема v2 + бэкфилл Глеба (аддитивно, код не трогаем)

**Files:** новая миграция `2026_07_12_100000_nutrition_v2_profiles.php`; модели `NutritionProfile`, `NutritionInvite`, `NutritionTopicSend`; тест `ProfileSchemaTest`.

**Схема:**
- `nutrition_profiles`: id; telegram_user_id bigint unique; name string; username string nullable; main_chat_id bigint nullable; is_admin bool default false; status string default 'onboarding' (onboarding|active|paused); phase string default 'program'; program_started_on date nullable; ai_profile text nullable; settings json nullable; awaiting json nullable; last_seen_at datetime nullable; timestamps.
- `nutrition_invites`: id; code string unique; created_by_profile_id FK; used_by_profile_id FK nullable; used_at datetime nullable; timestamps.
- `nutrition_topic_sends`: id; profile_id FK; topic_id FK; scheduled_on date nullable; sent_at datetime nullable; timestamps; unique [profile_id, topic_id].
- `nutrition_meals`: + profile_id FK nullable, + score unsignedTinyInteger nullable, + rating json nullable. Старый unique [date,type] НЕ трогаем (снимем в Task 3).
- `nutrition_metrics`: + profile_id FK nullable (unique пока старый).
- `nutrition_messages`: + profile_id FK nullable, index.

**Бэкфилл (в той же миграции, идемпотентно):** если env NUTRITION_USER_ID непуст и профиля с таким telegram_user_id нет — создать: name='Глеб', is_admin=true, status='active', main_chat_id=env NUTRITION_CHAT_ID, phase/program_started_on/settings(wake_time,sleep_time,default_windows,steps_target,portion_adjustment)/awaiting(перенести awaiting_* если есть) — из nutrition_settings; ai_profile = содержимое `resources/nutrition/knowledge/04-profile.md` (file_get_contents, если файл есть). Всем строкам meals/metrics/messages с profile_id NULL проставить этот profile_id. topic_sends: по каждой topic с непустым scheduled_on создать send-строку Глеба (scheduled_on, sent_at), поля topics НЕ очищать (снимем в Task 4). В down() — только drop новых таблиц/колонок.

**Interfaces (produces):** `NutritionProfile` c casts (settings/awaiting json, program_started_on date, last_seen_at datetime) + хелперы: `setting(string $key, mixed $default=null)`, `setSetting(string $key, $v)`, `waiting(string $key)`, `setWaiting(string $key, $v)`, `clearWaiting(string $key)` (мутируют json-поля с save); `NutritionProfile::admin(): ?self` (is_admin=true first); `NutritionInvite::generate(NutritionProfile $by): self` (code = 6 симв. A-Z0-9 без 0/O/1/I); relation'ы. Дефолты settings при null — те же, что Settings::DEFAULTS (вынести DEFAULTS в NutritionProfile::DEFAULT_SETTINGS, Settings оставить как есть до Task 2).

**Тесты:** миграция на sqlite создаёт всё; NutritionInvite::generate уникален и читаем; profile helpers (setting fallback на дефолты, setWaiting/clearWaiting персистят); бэкфилл-логика — юнит на idempotent-часть, вынесенную в статический метод (например `NutritionProfile::backfillFromLegacy()`, вызываемый миграцией) с посевом legacy-данных.

Коммит: `feat(nutrition): v2 schema, profiles, invites, backfill`.

---

### Task 2: Per-profile контекст в обработке входящих

**Files:** modify `ProcessNutritionUpdate`, все `app/Actions/Nutrition/*`, `app/Support/Nutrition/{SettingInput,MealLogger,MealIntent,PendingRequest,Planner,PromptBuilder,ProgramStatus,Tg}`; tests обновить.

**Ядро:** новый `App\Support\Nutrition\ProfileContext`: `ProfileContext::resolve(array $update): ?NutritionProfile` (по from.id; touch last_seen_at). Job:
1. new_chat_members — как сейчас (owner-гейт: адаптировать — «владелец» = любой профиль добавившего; приветствие в чат + если у добавившего есть профиль — кнопки «Сделать этот чат основным? [Да chatmain:yes] [Нет chatmain:no]», Task 4 реализует callback, здесь только отправка при наличии профиля).
2. resolve профиль. Нет профиля: личка → «Это персональный бот. Есть инвайт-код? Пришли его 🙂» (+ если текст похож на код [A-Z0-9]{6} — проверить invite: валидный → создать профиль (status=onboarding, name из from.first_name, username), пометить invite used, ответить заглушкой «Код принят! Онбординг скоро» — Task 5 заменит на анкету); группа → молчание. Профиль paused → короткое «Профиль на паузе».
3. Есть профиль → logIncoming c profile_id → route(profile) — все Actions получают `handle(array $update, NutritionProfile $profile)`.

**Сигнатуры (produces, используют задачи 3–7):**
- `Planner::ensureDay(NutritionProfile $p, CarbonImmutable $date)`, `recalculate($p,$date)`, `markEaten($p, NutritionMeal ...)`, `currentMeal($p,$now)`, `markMissed($p,$now)` — все запросы с where profile_id; settings окон из `$p->setting(...)`.
- `PendingRequest::expectsWeight($p,$now)/expectsMetrics($p,$now)` — sent_events ключи `p{$p->id}:{d}:...`.
- `PromptBuilder::system(NutritionProfile $p)` = персона + 01/02/03 knowledge + "\n\n# Профиль клиента\n".$p->ai_profile (04-profile.md больше НЕ читается глобально — удалить из glob: фильтр `!str_starts_with(basename, '04-')` ИЛИ файл удалить в этой задаче из репо, ai_profile Глеба уже в БД из Task 1 — удалить файл); `dayContext($p,$date)`.
- Все awaiting_* (setting/meal_time/meal_photo) — через `$p->waiting()/setWaiting()/clearWaiting()`; staleness-guard'ы сохраняются (lastOutKind считать по nutrition_messages С where profile_id).
- `TelegramClient::send(..., ?int $chatId=null)` — без изменений; handlers передают Tg::chatId; тик будет слать в `$p->main_chat_id` (Task 3).
- Глобальный `Settings` класс: остаётся ТОЛЬКО для legacy-чтения в Task 1-бэкфилле; из рантайм-кода все обращения убрать. `nutrition_sent_events` без префикса продолжают учитываться только тиком до Task 3 — ок.
- **Свап уников** (последний коммит задачи, после переключения кода): meals unique [profile_id,date,type], metrics unique [profile_id,date,type] — отдельная миграция; upsert'ы метрик — updateOrCreate(['profile_id','date','type']).

**Тесты:** все существующие Feature-тесты обновляются: сидят профиль (helper в Pest.php или базовом тесте: `nutritionProfile(['telegram_user_id'=>123,...])`), config nutrition.user_id больше не источник владельца в тестах маршрутизации (изв. пользователь = наличие профиля). Новые: неизвестный в личке получает invite-подсказку; валидный код создаёт onboarding-профиль и гасит invite; невалидный — вежливый отказ; paused-профиль; два профиля не видят данных друг друга (meals/metrics/контексты PendingRequest изолированы — минимум один тест изоляции).

Коммит(ы): `feat(nutrition): profile-aware handlers` (+ `feat(nutrition): swap uniques to per-profile`).

---

### Task 3: Мультипрофильный тик

**Files:** `NutritionTick`, `RunDaySummary`, `RunCheckup`, `SendTopic`; TickTest.

- `tick()`: цикл по `NutritionProfile::where('status','active')`; guard: main_chat_id пуст → skip профиль (Log::info раз в день через once `p{id}:{d}:no-chat`). Внутри — текущая логика per-profile: ensureDay/markMissed($p), слот-напоминания (ключи `p{id}:{d}:meal:{type}:{H:i}`, pre, наджи по фазе профиля), weight_request чт/вс (фаза профиля), metrics 21:30, summary 22:30 (`RunDaySummary::handle($p)`), checkup вс 20:00 (`RunCheckup::handle($p, ...)`), темы: из nutrition_topic_sends профиля (scheduled_on=сегодня, sent_at null) → `SendTopic::handle($p, $topicSend)`; graduation по профилю (phase→maintenance у профиля).
- Все send → `chatId: $p->main_chat_id`.
- Глобальный guard blank(config chat_id) УБРАТЬ (заменён per-profile guard); dry-run rollback и withoutOverlapping сохранить. `--at` печатает события с префиксом имени профиля.
- `nutrition:start-program {date?} {--profile=}`: по умолчанию admin-профиль; StartProgram::handle($p, $date) — раскладывает topic_sends профиля (создаёт строки), settings профиля. Кнопка program:start в HandleCallback — уже per-profile из Task 2.
- Очистка: nutrition_topics.scheduled_on/sent_at колонки больше не читаются (дропнуть миграцией в этой задаче), nutrition_settings строки phase/program_started_on больше не читаются (не дропаем таблицу — там ничего вредного).

**Тесты:** два активных профиля с разными окнами → каждый получает свои напоминания в свой чат (Http::assertSent с чужим chat_id отсутствует); профиль без main_chat_id пропускается без ошибок; paused не получает ничего; темы шлются per-profile.

Коммит: `feat(nutrition): multi-profile tick`.

---

### Task 4: Онбординг + инвайты + основной чат

**Files:** новый `App\Actions\Nutrition\Onboarding` (state machine); modify HandleCommand (/invite), HandleCallback (chatmain:yes/no, onboard:skip, onboard:confirm), job (маршрут в Onboarding при status=onboarding); OnboardingTest.

- `/invite` (только $profile->is_admin): `NutritionInvite::generate`, ответ «Код: {code}. Друг пишет его боту в личку — и стартует онбординг».
- Валидный код (из Task 2 заглушки) → сразу `Onboarding::start($profile)`.
- State machine (state в `$p->waiting('onboarding_step')`, ответы копятся в `$p->waiting('onboarding_answers')` array):
  1. «Как к тебе обращаться и какая цель? (снизить вес / набрать форму / энергия ...)»
  2. «Вес, рост, возраст?» 3. «Во сколько встаёшь и ложишься? (например 07:00 и 23:00)» — парсить, писать в settings wake/sleep + окна сдвинуть от подъёма (завтрак = подъём+30..90мин, остальное дефолт). 4. «Спорт/активность?» 5. «Ограничения в еде: аллергии, что не ешь?» 6. «Заметки о здоровье, если хочешь учесть (анализы, особенности). Можно [Пропустить onboard:skip]».
  Любой текст при status=onboarding идёт в Onboarding::answer($p, $text) (мимо MealIntent/вопросов). Команды работают (/help), /start повторяет текущий вопрос.
  После 6: `Claude::text` (модель chat): сжать answers в структурированный профиль клиента (цель, параметры, режим, активность, ограничения, здоровье; 10–15 строк, безопасно: не диагнозы) → `$p->ai_profile`; показать резюме + кнопка [🚀 Начать программу program:start] (существующий callback: старт per-profile, status уже active — установить status='active' при завершении анкеты). Fallback ИИ null → ai_profile = сырые ответы списком.
- `chatmain:yes` → main_chat_id = чат callback'а, подтверждение; `chatmain:no` → «Ок, оставил как было».

**Тесты:** полный проход анкеты (6 ответов) → ai_profile заполнен (fake ИИ), status active, кнопка старта; skip на 6-м; wake/sleep из шага 3 попали в settings и окна; сообщение-еда во время онбординга НЕ уходит в MealIntent; chatmain:yes ставит main_chat_id; /invite не-админу отказывает.

Коммит: `feat(nutrition): invite onboarding flow`.

---

### Task 5: Рейтинги приёмов

**Files:** `MealLogger` (промпт/парсинг), `HandlePhoto`, `HandleCallback::mealPhoto`, `RunDaySummary`, `RunCheckup`, `PromptBuilder::dayContext` (балл в контекст); MealRatingTest.

- foodPrompt → просит СТРОГИЙ JSON: `{"feedback":"1-3 предложения в стиле Насти","score":1-10,"composition_ok":bool,"forbidden":["..."],"comment":"кратко"}`. Парсинг по паттерну MealIntent (заборы, decode, валидация: score int 1..10, иначе вся структура null). Fallback: не-JSON ответ ИИ → feedback=сырой текст, score null.
- Детерминированно код считает: `interval_ok` (2.5–4.5ч от прошлого eaten сегодня; первый приём = true), `window_ok` (eaten_at внутри [window_start, window_end+15м]). Пишет `score`, `rating` json {composition_ok, forbidden, interval_ok, window_ok, comment} в meal (и для текстовых отчётов через MealIntent — у intent'а уже есть reply; добавить в его JSON те же поля score/composition_ok/forbidden — один вызов, без второго запроса).
- Кнопка «Поел» — score null, rating только {interval_ok, window_ok}.
- RunDaySummary/RunCheckup: в контекст добавляются средний балл дня/недели и список forbidden-нарушений (детерминированно из БД, в промпт текстом).
- dayContext: у съеденных приёмов показывать балл.

**Тесты:** фото → score/rating сохранены (fake JSON); не-JSON от ИИ → приём записан, score null; interval_ok/window_ok считаются кодом (кейсы: вовремя/рано/вне окна); текстовый отчёт тоже пишет score; summary-промпт содержит средний балл.

Коммит: `feat(nutrition): meal scoring and rating breakdown`.

---

### Task 6: Страница статистики (подписанная ссылка)

**Files:** route web.php `GET /nutrition/s/{profile}` name `nutrition.stats` + `->middleware('signed')` (URL::signedRoute, бессрочная); `NutritionStatsController@show`; Inertia-страница `resources/js/pages/nutrition/stats.tsx` + маленькие SVG-компоненты (`resources/js/components/nutrition/{LineChart,BarChart,Streak}.tsx` — без библиотек); HandleCommand::stats шлёт ссылку; StatsPageTest.

- Контроллер отдаёт props: profile {name, phase, day}, weights[{date,value}] (всё время), scores[{date,avg,count}] 30 дней, adherence[{date, eaten, missed, skipped}] 30 дней, steps[{date,value,target}] 14 дней, water 14 дней, recentMeals 20 [{date,type,label,time,score,forbidden[],window_ok,interval_ok}]. Только чтение, безопасно без auth (signed).
- Дизайн: в стиле сайта (Tailwind, существующие UI-компоненты); мобильный-first (открывается из Telegram). Заголовок «Статистика — {name}», карточки: текущий вес+дельта, средний балл 7д, стрик дней без пропусков, шаги 7д. Ниже графики (SVG line: вес; bars: баллы по дням; таблица приёмов).
- `/stats` в боте: краткий текст (как сейчас) + «Подробные графики: {signedRoute}».
- `npm run build` перед коммитом; UI-проверка curl'ом (200, содержит name) — визуал проверит координатор.

**Тесты:** без подписи → 403; с подписью → 200 и данные только этого профиля; ссылка в /stats-ответе бота присутствует.

Коммит: `feat(nutrition): stats page with signed links`.

---

### Task 7: Админка

**Files:** `App\Http\Controllers\Admin\NutritionAdminController` (index/show/update/invite/pause); routes в admin-группу web.php (`/admin/nutrition`); Inertia-страницы `resources/js/pages/admin/nutrition/{index,show}.tsx`; ссылка в admin-навигацию (посмотреть, как подключены游 game-страницы — layout/menu); AdminNutritionTest.

- index: таблица профилей — имя, username, статус, фаза, день программы, last_seen, средний балл 7д, вес-дельта 30д; кнопка «Сгенерировать инвайт» (POST → показывает код); строки кликабельны.
- show: те же данные, что stats-страница (реюз props-логики через общий сервис `NutritionStats::for($profile)`) + панель управления: пауза/возобновить (toggle status), правка settings (wake/sleep/steps_target/portion_adjustment — форма), просмотр/правка ai_profile (textarea), ссылка на подписанную страницу пользователя.
- Права: существующий admin-middleware (сайтовый логин Глеба).

**Тесты:** гость/не-админ → 403/redirect; список показывает профили; invite создаётся; пауза меняет статус; апдейт настроек пишет в profile.settings.

Коммит: `feat(nutrition): admin panel`.

---

### Task 8: Деплой v2 + сквозная проверка (координатор)

- migrate --force (Task 1 схема+бэкфилл; Task 2 свап уников; Task 3 дроп topic-колонок), config:cache, queue:restart, npm run build (если не сделан в задачах).
- Проверки: профиль Глеба существует, все старые строки привязаны, бот отвечает Глебу как раньше (/today, фото), тик шлёт в его чат; /invite генерит код; тестовый второй аккаунт (жена/тестовый) проходит онбординг end-to-end; /stats ссылка открывается; /admin/nutrition показывает обоих.
- Обновить PROGRESS.md, память, локальные docs-копии спеки/плана.

---

## Self-Review Notes
- Спека v2 покрыта: профили/инвайты/онбординг (1,2,4), рейтинги (5), статистика (6), админка (7), мульти-тик (3), бэкфилл/совместимость (1,2,8).
- Рискованные швы обозначены в Global Constraints (guard'ы переживают рефактор); Task 2 самый большой — имплементеру разрешено дробить на коммиты, каждый зелёный.
- Таймзоны per-user, лимиты токенов — сознательно вне скоупа (спека).
