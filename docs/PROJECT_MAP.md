# gleb.finance — Project Map

> **Read this first, before exploring the codebase.** This file maps every sub-project to *where its code lives* (routes, controllers, models, DB tables, frontend files, specs), so each session doesn't re-discover the repo from scratch.
> Framework / coding conventions live in `AGENTS.md` (auto-generated Laravel Boost guidelines) — not duplicated here.
> **Keep it current:** when you add/rename/move a project or its routes, models, or tables, update this file in the *same commit*. Regenerate raw facts with `bash docs/project-map-facts.sh`.

## How the codebase is organized

- **One** Laravel 13 app, **one** MySQL DB, **one** Fortify auth — shared by all projects.
- Projects are **logical, not physical**: there are no per-project folders. They are separated by naming convention (`Game*` controllers/models, route prefixes such as `/game`, `/admin/game`) and by frontend stack.
- **Two frontend stacks coexist — check which one before editing:**
  - **Inertia + React 19** — `resources/js/pages/*.tsx`. Main site: home, dashboard, settings, standard auth.
  - **Blade (server-rendered)** — `resources/views/**/*.blade.php`. The **game** (`/game`) and **all admin** (`/admin/*`). Don't assume React when touching game/admin.
- Admin is behind middleware `auth` + `admin` (the `admin` gate checks `users.is_admin`).
- Design specs live in `docs/specs/`.

## Data model (game)

| Table | Purpose | Notable columns |
|---|---|---|
| `users` | shared auth | `is_admin`, `email_verified_at` |
| `game_contents` | scenario config, JSON-versioned, one active version | `data` (JSON: `choices[]`, `years[].{ret,rate,infl,ev}`) |
| `game_results` | one row per play | `user_id`, `score_you` / `score_bank` / `score_max`, `ratio`, `composition`, `choices` (JSON `{k,quarter}[]`), `survey_answers` |
| `game_events` | telemetry | `user_id`, event type (`open` / `start` / `finish` / `open_fund` / …) |

Migrations: `database/migrations/2026_06_20_*`.

## Projects

### 1. Site shell (gleb.finance)
- **URLs:** `/` (`home`), `/dashboard` (auth + verified).
- **Frontend (React):** `resources/js/pages/welcome.tsx`, `dashboard.tsx`. Home shows the ФондыКвест card.
- **Routes:** top of `routes/web.php`.

### 2. ФондыКвест — the game (`/game`)
A DCA portfolio quest. **Blade-rendered.**
- **URLs:** `/game` (show), `/game/register`, `/game/login` (own branded RU guest gate); `POST /game/result`, `GET /game/leaderboard`, `POST /game/event`.
- **Controller:** `app/Http/Controllers/GameController.php` — includes `simulate()`, `benchmarks()`, scoring.
- **Models:** `GameContent` (scenario), `GameResult` (plays), `GameEvent` (telemetry).
- **Views:** `resources/views/game.blade.php`, `resources/views/auth/game-{landing,layout,login,register}.blade.php`.
- **Tables:** `game_contents`, `game_results`, `game_events`.
- **Spec:** `docs/specs/2026-06-20-portfolio-dca-design.md`.

### 3. Game admin (`/admin/game`)
Behind `auth` + `admin`. **Blade.**
- **URLs:** `/admin/game` (content), `/admin/game/survey`, `/admin/game/returns` (per-quarter returns-matrix editor). `/admin/game/stats` now **redirects** to `/admin/dashboards/gameresults` (results moved to §4).
- **Controllers:** `app/Http/Controllers/Admin/Game{Content,Survey,Returns}Controller.php`.
- **Views:** `resources/views/admin/game/{content,survey,returns,layout}.blade.php`.

### 4. Reports & dashboards
Behind `auth` + `admin`. **Blade.** Live and data-driven (rebuilt from the static demo).
- **URLs:** `/admin/dashboards/gameresults` (game analytics) and `/admin/dashboards/site` (site KPIs). `/admin` → redirect to `/admin/dashboards/site`; `/admin/game/stats` → redirect to `/admin/dashboards/gameresults`.
- **Controllers:** `Admin\GameResultsDashboardController`, `Admin\SiteDashboardController`.
- **Report builders:** `App\Actions\Game\BuildGameResultsReport` (live game `D` object; **scopes results and events to the active scenario version** — `game_content_id`) and `App\Actions\Admin\BuildSiteReport` (site KPIs: users/verified/admin, registrations/day, active `sessions`, per-project cards).
- **Scenario math:** `App\Support\Game\Scenario` (`simulate` / `benchmarks` / `optimumPerQuarter` / `bestByQuarterReturn`), extracted from `GameController`.
- **Views:** `resources/views/admin/dashboards/{gameresults,site}.blade.php`; shared shell `resources/views/admin/layout.blade.php`. Chart.js is loaded from CDN with SRI; user-supplied strings are escaped client-side.
- **Static demo (design reference, untouched):** `public/reports/gamedemoresults.html` (+ identical `public/reports/gamedemoresults/index.html`) — ~25 Chart.js graphs with data hardcoded in `const D`. The original design target the live dashboard was built from; committed so Forge deploys keep it.
- **Spec:** `docs/specs/2026-06-22-game-dashboard-live-design.md`.

### 5. Auth & settings (Fortify)
- **URLs:** standard Fortify auth; `/settings/profile`, `/settings/security`, `/settings/appearance`.
- **Controllers:** `app/Http/Controllers/Settings/{Profile,Security}Controller.php`.
- **Frontend (React):** `resources/js/pages/auth/*.tsx`, `resources/js/pages/settings/*.tsx`. Note: the **game ships its own Blade auth**, separate from this.

## Adding a new project
Append a section under **Projects** using this stub:

```
### N. <Name> (`/<url>`)
- URLs / routes: …
- Controllers: …
- Models / tables: …
- Frontend: Blade or Inertia? which files
- Spec: docs/specs/<date>-<name>-design.md
```

## Refresh
Print raw structural facts (routes, controllers, models, pages, tables), then reconcile this file:

```
bash docs/project-map-facts.sh
```
