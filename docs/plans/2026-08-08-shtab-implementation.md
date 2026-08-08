# Штаб (Shtab) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Shtab management cockpit (`/shtab`) — Gleb's personal strategy-map for tracking who is focused on what, per spec `docs/specs/2026-08-08-shtab-management-cockpit-design.md`.

**Architecture:** New logical sub-project inside the gleb.finance Laravel 13 monorepo. Five `shtab_`-prefixed MySQL tables; thin controllers under `App\Http\Controllers\Shtab`; board assembly in an Action class; Inertia v3 + React 19 page (`resources/js/pages/shtab/`) with three tabs (Карта / Люди / Хроника). Mutations are classic Inertia POSTs with `redirect()->back()` — no separate REST layer. Every mutation writes a `shtab_events` row in the same transaction.

**Tech Stack:** Laravel 13, Inertia v3, React 19, Tailwind 4, existing shadcn-style ui components (`resources/js/components/ui/`), Pest v4 (sqlite `:memory:`), native HTML5 drag-and-drop (no new npm deps).

**Environment rules (from repo AGENTS.md):**
- ALL work happens on the remote server via `ssh -l gleb gleb.finance`, repo `/home/gleb/gleb.finance`, branch `master` only. No worktrees, no local edits.
- Before ANY test run: `php artisan config:clear` (cached config sends tests to the production MySQL DB — known hazard).
- After PHP changes: `vendor/bin/pint --dirty --format agent`. After frontend changes: `npm run build`.
- Run tests with `php artisan test --compact --filter=...`.
- Access gate: existing `auth` + `admin` middleware (`users.is_admin`), consistent with other admin sub-projects. Non-admins get 403 (spec said 404; 403 follows repo convention — approved deviation).
- The server runs with a CACHED route table (`bootstrap/cache/routes-v7.php`): after ANY `routes/web.php` change run `php artisan route:cache`, or new routes stay invisible (tests 404).

**Static-analysis conventions (added after wave-1 code review — apply ON TOP of every code snippet below; the snippets predate this addendum):**
- The repo has a CLEAN Larastan (phpstan level 7) and ESLint baseline. Every task must leave them clean: run `vendor/bin/phpstan analyse --memory-limit=512M` and scoped `npx eslint <your files>` as part of verification, alongside Pint and tests.
- PHP factories: annotate class with `/** @extends Factory<Model> */` and `definition()` with `@return array<string, mixed>`.
- PHP models: `/** @use HasFactory<ModelFactory> */` above the trait use; relation generics (`@return BelongsTo<Target, $this>`, `@return HasMany<Target, $this>`); scopes annotated `@param Builder<self> $query @return Builder<self>`; class-level `@property` docblock for every column (follow `app/Models/User.php` / `NutritionMetric.php` style).
- PHP controllers/actions: explicit return types everywhere (already in snippets) + PHPDoc array shapes for array returns.
- TSX: braces on all `if` bodies, blank line before `return` (`curly` + `@stylistic/padding-line-between-statements` rules); never render `String(maybeUndefined)` — coalesce with `?? '?'` first.

---

## Task 1: Relocate spec to repo convention location

Specs live in `docs/specs/` (see PROJECT_MAP.md), not `docs/superpowers/specs/`.

**Files:**
- Move: `docs/superpowers/specs/2026-08-08-shtab-management-cockpit-design.md` → `docs/specs/2026-08-08-shtab-management-cockpit-design.md`
- Move: `docs/superpowers/specs/assets/2026-08-08-shtab-cockpit-final.html` → `docs/specs/assets/2026-08-08-shtab-cockpit-final.html`

- [ ] **Step 1.1: Move files with git mv**

```bash
cd /home/gleb/gleb.finance
mkdir -p docs/specs/assets
git mv docs/superpowers/specs/2026-08-08-shtab-management-cockpit-design.md docs/specs/
git mv docs/superpowers/specs/assets/2026-08-08-shtab-cockpit-final.html docs/specs/assets/
rmdir docs/superpowers/specs/assets docs/superpowers/specs docs/superpowers 2>/dev/null || true
```

- [ ] **Step 1.2: Fix the asset path reference inside the spec**

In `docs/specs/2026-08-08-shtab-management-cockpit-design.md` replace the string
`docs/superpowers/specs/assets/2026-08-08-shtab-cockpit-final.html` with
`docs/specs/assets/2026-08-08-shtab-cockpit-final.html`.

- [ ] **Step 1.3: Commit**

```bash
git add -A docs && git commit -m "docs: move shtab spec to docs/specs per repo convention"
```

---

## Task 2: Config, migrations, models, factories

**Files:**
- Create: `config/shtab.php`
- Create: `database/migrations/<timestamp>_create_shtab_people_table.php`
- Create: `database/migrations/<timestamp>_create_shtab_objects_table.php`
- Create: `database/migrations/<timestamp>_create_shtab_assignments_table.php`
- Create: `database/migrations/<timestamp>_create_shtab_metrics_table.php`
- Create: `database/migrations/<timestamp>_create_shtab_events_table.php`
- Create: `app/Models/ShtabPerson.php`, `app/Models/ShtabObject.php`, `app/Models/ShtabAssignment.php`, `app/Models/ShtabMetric.php`, `app/Models/ShtabEvent.php`
- Create: `database/factories/ShtabPersonFactory.php`, `ShtabObjectFactory.php`, `ShtabAssignmentFactory.php`, `ShtabMetricFactory.php`
- Test: `tests/Feature/Shtab/ShtabModelsTest.php`

- [ ] **Step 2.1: Write the failing test**

`php artisan make:test --pest Shtab/ShtabModelsTest`, then content:

```php
<?php

use App\Models\ShtabAssignment;
use App\Models\ShtabEvent;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;

it('creates the full object graph via factories', function () {
    $manager = ShtabPerson::factory()->create(['is_me' => true]);
    $person = ShtabPerson::factory()->create(['manager_id' => $manager->id]);

    $product = ShtabObject::factory()->create(['type' => 'product', 'focus_level' => 2]);
    $project = ShtabObject::factory()->create(['type' => 'project', 'parent_id' => $product->id]);

    $metric = ShtabMetric::factory()->create(['object_id' => $product->id, 'status' => 'red']);
    $businessMetric = ShtabMetric::factory()->create(['object_id' => null]);

    $assignment = ShtabAssignment::factory()->create([
        'person_id' => $person->id,
        'object_id' => $project->id,
    ]);

    expect($person->manager->is($manager))->toBeTrue()
        ->and($project->parent->is($product))->toBeTrue()
        ->and($product->children->first()->is($project))->toBeTrue()
        ->and($product->metrics->first()->is($metric))->toBeTrue()
        ->and($businessMetric->object_id)->toBeNull()
        ->and($person->activeAssignments->first()->is($assignment))->toBeTrue()
        ->and($project->activeAssignments->first()->person->is($person))->toBeTrue();
});

it('excludes ended assignments from active scopes', function () {
    $assignment = ShtabAssignment::factory()->create(['ended_at' => now()->toDateString()]);

    expect(ShtabAssignment::query()->active()->count())->toBe(0)
        ->and($assignment->person->activeAssignments)->toHaveCount(0)
        ->and($assignment->person->in_reserve ?? true)->toBeTrue();
});

it('records events with payload', function () {
    $person = ShtabPerson::factory()->create();

    $event = ShtabEvent::record('assignment_started', [
        'person_id' => $person->id,
        'payload' => ['role_label' => 'владелец'],
        'comment' => 'тест',
    ]);

    expect($event->refresh()->payload['role_label'])->toBe('владелец')
        ->and($event->type)->toBe('assignment_started')
        ->and($event->person->is($person))->toBeTrue();
});
```

- [ ] **Step 2.2: Run test to verify it fails**

```bash
cd /home/gleb/gleb.finance && php artisan config:clear && php artisan test --compact --filter=ShtabModelsTest
```
Expected: FAIL — `Class "App\Models\ShtabPerson" not found`.

- [ ] **Step 2.3: Create config `config/shtab.php`**

```php
<?php

return [
    // Больше скольких активных 🔥-назначений человек считается перегруженным.
    'overload_threshold' => (int) env('SHTAB_OVERLOAD_THRESHOLD', 2),
];
```

- [ ] **Step 2.4: Create migrations**

Generate with artisan (keeps timestamp convention), then fill in:

```bash
php artisan make:migration create_shtab_people_table --no-interaction
php artisan make:migration create_shtab_objects_table --no-interaction
php artisan make:migration create_shtab_assignments_table --no-interaction
php artisan make:migration create_shtab_metrics_table --no-interaction
php artisan make:migration create_shtab_events_table --no-interaction
```

`create_shtab_people_table` `up()`:

```php
Schema::create('shtab_people', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('initials', 8);
    $table->string('class'); // роль-класс: Аналитик, Маркетолог, Разраб…
    $table->string('color', 7)->default('#64748B');
    $table->boolean('is_direct')->default(true);
    $table->foreignId('manager_id')->nullable()->constrained('shtab_people')->nullOnDelete();
    $table->boolean('is_me')->default(false);
    $table->unsignedInteger('sort')->default(0);
    $table->timestamp('archived_at')->nullable();
    $table->timestamps();
});
```

`create_shtab_objects_table` `up()`:

```php
Schema::create('shtab_objects', function (Blueprint $table) {
    $table->id();
    $table->string('type'); // product | project | enabler
    $table->foreignId('parent_id')->nullable()->constrained('shtab_objects')->nullOnDelete();
    $table->string('name');
    $table->string('emoji', 16)->nullable();
    $table->unsignedTinyInteger('focus_level')->default(0); // 0 фоновый | 1 🔥 | 2 🔥🔥
    $table->string('color', 7)->default('#5B6EE8');
    $table->unsignedInteger('sort')->default(0);
    $table->timestamp('archived_at')->nullable();
    $table->timestamps();
});
```

`create_shtab_assignments_table` `up()`:

```php
Schema::create('shtab_assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('person_id')->constrained('shtab_people')->cascadeOnDelete();
    $table->foreignId('object_id')->constrained('shtab_objects')->cascadeOnDelete();
    $table->string('role_label'); // «владелец», «аналитика», …
    $table->text('comment')->nullable();
    $table->date('started_at');
    $table->date('ended_at')->nullable(); // NULL = активно
    $table->timestamps();
    $table->index(['person_id', 'ended_at']);
    $table->index(['object_id', 'ended_at']);
});
```

`create_shtab_metrics_table` `up()`:

```php
Schema::create('shtab_metrics', function (Blueprint $table) {
    $table->id();
    $table->foreignId('object_id')->nullable()->constrained('shtab_objects')->cascadeOnDelete(); // NULL = бизнес в целом
    $table->string('name');
    $table->string('status')->default('green'); // green | yellow | red
    $table->string('value_text')->nullable();
    $table->unsignedInteger('sort')->default(0);
    $table->timestamps();
});
```

`create_shtab_events_table` `up()`:

```php
Schema::create('shtab_events', function (Blueprint $table) {
    $table->id();
    $table->string('type');
    $table->foreignId('person_id')->nullable()->constrained('shtab_people')->nullOnDelete();
    $table->foreignId('object_id')->nullable()->constrained('shtab_objects')->nullOnDelete();
    $table->foreignId('metric_id')->nullable()->constrained('shtab_metrics')->nullOnDelete();
    $table->json('payload')->nullable();
    $table->text('comment')->nullable();
    $table->timestamps();
    $table->index('type');
});
```

- [ ] **Step 2.5: Create models**

`app/Models/ShtabPerson.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShtabPerson extends Model
{
    use HasFactory;

    protected $table = 'shtab_people';

    protected $fillable = [
        'name', 'initials', 'class', 'color', 'is_direct',
        'manager_id', 'is_me', 'sort', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_direct' => 'boolean',
            'is_me' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShtabAssignment::class, 'person_id');
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('ended_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
```

`app/Models/ShtabObject.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShtabObject extends Model
{
    use HasFactory;

    protected $table = 'shtab_objects';

    protected $fillable = [
        'type', 'parent_id', 'name', 'emoji', 'focus_level', 'color', 'sort', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'focus_level' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ShtabMetric::class, 'object_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShtabAssignment::class, 'object_id');
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('ended_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
```

`app/Models/ShtabAssignment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShtabAssignment extends Model
{
    use HasFactory;

    protected $table = 'shtab_assignments';

    protected $fillable = [
        'person_id', 'object_id', 'role_label', 'comment', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_date',
            'ended_at' => 'immutable_date',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(ShtabPerson::class, 'person_id');
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(ShtabObject::class, 'object_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
```

`app/Models/ShtabMetric.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShtabMetric extends Model
{
    use HasFactory;

    protected $table = 'shtab_metrics';

    protected $fillable = ['object_id', 'name', 'status', 'value_text', 'sort'];

    public function object(): BelongsTo
    {
        return $this->belongsTo(ShtabObject::class, 'object_id');
    }
}
```

`app/Models/ShtabEvent.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShtabEvent extends Model
{
    protected $table = 'shtab_events';

    protected $fillable = ['type', 'person_id', 'object_id', 'metric_id', 'payload', 'comment'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(ShtabPerson::class, 'person_id');
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(ShtabObject::class, 'object_id');
    }

    public function metric(): BelongsTo
    {
        return $this->belongsTo(ShtabMetric::class, 'metric_id');
    }

    /**
     * Единая точка записи Хроники. Вызывается из контроллеров внутри транзакции мутации.
     *
     * @param  array{person_id?: int|null, object_id?: int|null, metric_id?: int|null, payload?: array<string, mixed>|null, comment?: string|null}  $attrs
     */
    public static function record(string $type, array $attrs = []): self
    {
        return self::create(array_merge(['type' => $type], $attrs));
    }
}
```

- [ ] **Step 2.6: Create factories**

`database/factories/ShtabPersonFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ShtabPerson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ShtabPersonFactory extends Factory
{
    protected $model = ShtabPerson::class;

    public function definition(): array
    {
        $name = fake()->firstName();

        return [
            'name' => $name,
            'initials' => Str::upper(Str::substr($name, 0, 2)),
            'class' => fake()->randomElement(['Аналитик', 'Маркетолог', 'Разраб', 'Биздев']),
            'color' => fake()->randomElement(['#10B981', '#8B5CF6', '#F59E0B', '#EC4899']),
            'is_direct' => true,
            'is_me' => false,
        ];
    }
}
```

`database/factories/ShtabObjectFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ShtabObject;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShtabObjectFactory extends Factory
{
    protected $model = ShtabObject::class;

    public function definition(): array
    {
        return [
            'type' => 'product',
            'name' => fake()->unique()->word(),
            'emoji' => '🏰',
            'focus_level' => 0,
            'color' => '#5B6EE8',
        ];
    }
}
```

`database/factories/ShtabAssignmentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ShtabAssignment;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShtabAssignmentFactory extends Factory
{
    protected $model = ShtabAssignment::class;

    public function definition(): array
    {
        return [
            'person_id' => ShtabPerson::factory(),
            'object_id' => ShtabObject::factory(),
            'role_label' => fake()->randomElement(['владелец', 'аналитика', 'разработка']),
            'started_at' => now()->toDateString(),
            'ended_at' => null,
        ];
    }
}
```

`database/factories/ShtabMetricFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShtabMetricFactory extends Factory
{
    protected $model = ShtabMetric::class;

    public function definition(): array
    {
        return [
            'object_id' => ShtabObject::factory(),
            'name' => fake()->word(),
            'status' => 'green',
            'value_text' => null,
        ];
    }
}
```

- [ ] **Step 2.7: Run test to verify it passes**

```bash
php artisan config:clear && php artisan test --compact --filter=ShtabModelsTest
```
Expected: PASS (3 tests). Note: `in_reserve` in the second test uses `?? true` so it passes at model level; the real flag is computed in Task 4.

- [ ] **Step 2.8: Run production migration**

```bash
php artisan migrate --force --no-interaction
```
Expected: 5 `create_shtab_*` migrations ran.

- [ ] **Step 2.9: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(shtab): schema, models, factories for management cockpit"
```

---

## Task 3: Route, access gate, empty page shell

**Files:**
- Modify: `routes/web.php` (add `/shtab` group after the game admin group)
- Create: `app/Http/Controllers/Shtab/ShtabController.php`
- Create: `resources/js/pages/shtab/index.tsx` (minimal shell, real UI in Task 8)
- Test: `tests/Feature/Shtab/ShtabAccessTest.php`

- [ ] **Step 3.1: Write the failing test**

```php
<?php

use App\Models\User;

it('redirects guests to login', function () {
    $this->get('/shtab')->assertRedirect('/login');
});

it('forbids non-admins', function () {
    $this->actingAs(User::factory()->create())->get('/shtab')->assertForbidden();
});

it('renders the shtab page for the admin', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($admin)->get('/shtab')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('shtab/index')
            ->has('board.people')
            ->has('board.objects')
            ->has('board.business_metrics')
            ->has('events'));
});
```

- [ ] **Step 3.2: Run test to verify it fails**

```bash
php artisan config:clear && php artisan test --compact --filter=ShtabAccessTest
```
Expected: FAIL — 404 on `/shtab`.

- [ ] **Step 3.3: Add the route group**

In `routes/web.php` after the `admin` group, before the nutrition webhook:

```php
use App\Http\Controllers\Shtab\ShtabController;

Route::middleware(['auth', 'admin'])->prefix('shtab')->name('shtab.')->group(function () {
    Route::get('/', [ShtabController::class, 'index'])->name('index');
});
```

(Import goes to the top of the file with the other `use` statements.)

- [ ] **Step 3.4: Create the controller (board assembly comes in Task 4)**

`app/Http/Controllers/Shtab/ShtabController.php`:

```php
<?php

namespace App\Http\Controllers\Shtab;

use App\Actions\Shtab\BuildShtabBoard;
use App\Http\Controllers\Controller;
use App\Models\ShtabEvent;
use Inertia\Inertia;
use Inertia\Response;

class ShtabController extends Controller
{
    public function index(BuildShtabBoard $board): Response
    {
        return Inertia::render('shtab/index', [
            'board' => $board->handle(),
            'events' => ShtabEvent::query()
                ->with(['person:id,name,initials,color', 'object:id,name,emoji', 'metric:id,name'])
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn (ShtabEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'person' => $event->person?->only(['id', 'name', 'initials', 'color']),
                    'object' => $event->object?->only(['id', 'name', 'emoji']),
                    'metric' => $event->metric?->only(['id', 'name']),
                    'payload' => $event->payload,
                    'comment' => $event->comment,
                    'created_at' => $event->created_at->toIso8601String(),
                ]),
        ]);
    }
}
```

- [ ] **Step 3.5: Create a stub BuildShtabBoard so the controller works**

`app/Actions/Shtab/BuildShtabBoard.php` (full logic in Task 4):

```php
<?php

namespace App\Actions\Shtab;

class BuildShtabBoard
{
    /**
     * @return array{people: array<int, mixed>, objects: array<int, mixed>, business_metrics: array<int, mixed>}
     */
    public function handle(): array
    {
        return [
            'people' => [],
            'objects' => [],
            'business_metrics' => [],
        ];
    }
}
```

- [ ] **Step 3.6: Create minimal page shell**

`resources/js/pages/shtab/index.tsx`:

```tsx
import { Head } from '@inertiajs/react';

export default function ShtabIndex() {
    return (
        <div className="min-h-screen bg-[#F2F0EA] p-6">
            <Head title="Штаб" />
            <h1 className="text-lg font-extrabold text-gray-900">⌘ ШТАБ</h1>
        </div>
    );
}
```

- [ ] **Step 3.7: Run test to verify it passes**

```bash
php artisan config:clear && php artisan test --compact --filter=ShtabAccessTest
```
Expected: PASS (3 tests).

- [ ] **Step 3.8: Pint, build, commit**

```bash
vendor/bin/pint --dirty --format agent
npm run build
git add -A && git commit -m "feat(shtab): route, admin gate, page shell"
```

---

## Task 4: BuildShtabBoard — derived flags

**Files:**
- Modify: `app/Actions/Shtab/BuildShtabBoard.php` (replace stub)
- Test: `tests/Feature/Shtab/ShtabBoardTest.php`

- [ ] **Step 4.1: Write the failing test**

```php
<?php

use App\Actions\Shtab\BuildShtabBoard;
use App\Models\ShtabAssignment;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;

function board(): array
{
    return (new BuildShtabBoard)->handle();
}

it('marks people without active assignments as reserve', function () {
    $busy = ShtabPerson::factory()->create();
    $idle = ShtabPerson::factory()->create();
    ShtabAssignment::factory()->create(['person_id' => $busy->id]);
    ShtabAssignment::factory()->create(['person_id' => $idle->id, 'ended_at' => now()->toDateString()]);

    $people = collect(board()['people'])->keyBy('id');

    expect($people[$busy->id]['in_reserve'])->toBeFalse()
        ->and($people[$idle->id]['in_reserve'])->toBeTrue();
});

it('counts hot assignments and flags overload above threshold', function () {
    config(['shtab.overload_threshold' => 2]);
    $person = ShtabPerson::factory()->create();
    foreach ([2, 2, 1] as $level) {
        ShtabAssignment::factory()->create([
            'person_id' => $person->id,
            'object_id' => ShtabObject::factory()->create(['focus_level' => $level])->id,
        ]);
    }
    ShtabAssignment::factory()->create([
        'person_id' => $person->id,
        'object_id' => ShtabObject::factory()->create(['focus_level' => 0])->id,
    ]);

    $row = collect(board()['people'])->firstWhere('id', $person->id);

    expect($row['focus_count'])->toBe(4)
        ->and($row['hot_count'])->toBe(3)
        ->and($row['is_overloaded'])->toBeTrue();
});

it('computes uncovered days from the last ended assignment', function () {
    $object = ShtabObject::factory()->create(['focus_level' => 2, 'created_at' => now()->subDays(60)]);
    ShtabAssignment::factory()->create([
        'object_id' => $object->id,
        'started_at' => now()->subDays(40)->toDateString(),
        'ended_at' => now()->subDays(12)->toDateString(),
    ]);

    $row = collect(board()['objects'])->firstWhere('id', $object->id);

    expect($row['is_uncovered'])->toBeTrue()
        ->and($row['uncovered_days'])->toBe(12);
});

it('computes uncovered days from creation when never assigned', function () {
    $object = ShtabObject::factory()->create(['created_at' => now()->subDays(9)]);

    $row = collect(board()['objects'])->firstWhere('id', $object->id);

    expect($row['uncovered_days'])->toBe(9);
});

it('reports days on object for active assignments', function () {
    $assignment = ShtabAssignment::factory()->create(['started_at' => now()->subDays(51)->toDateString()]);

    $row = collect(board()['objects'])->firstWhere('id', $assignment->object_id);

    expect($row['is_uncovered'])->toBeFalse()
        ->and($row['assignments'][0]['days'])->toBe(51);
});

it('orders objects by focus level desc and separates business metrics', function () {
    $background = ShtabObject::factory()->create(['focus_level' => 0]);
    $hot = ShtabObject::factory()->create(['focus_level' => 2]);
    ShtabMetric::factory()->create(['object_id' => null, 'name' => 'выручка']);

    $result = board();

    expect($result['objects'][0]['id'])->toBe($hot->id)
        ->and($result['objects'][1]['id'])->toBe($background->id)
        ->and($result['business_metrics'][0]['name'])->toBe('выручка');
});

it('hides archived people and objects from the board', function () {
    ShtabPerson::factory()->create(['archived_at' => now()]);
    ShtabObject::factory()->create(['archived_at' => now()]);

    expect(board()['people'])->toHaveCount(0)
        ->and(board()['objects'])->toHaveCount(0);
});
```

- [ ] **Step 4.2: Run test to verify it fails**

```bash
php artisan config:clear && php artisan test --compact --filter=ShtabBoardTest
```
Expected: FAIL — stub returns empty arrays.

- [ ] **Step 4.3: Implement BuildShtabBoard**

Replace `app/Actions/Shtab/BuildShtabBoard.php`:

```php
<?php

namespace App\Actions\Shtab;

use App\Models\ShtabAssignment;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use Carbon\CarbonImmutable;

class BuildShtabBoard
{
    /**
     * Собирает весь стейт доски одним массивом: люди и территории с активными
     * назначениями плюс выведенные флаги (резерв, перегруз, дыры покрытия).
     *
     * @return array{people: array<int, mixed>, objects: array<int, mixed>, business_metrics: array<int, mixed>}
     */
    public function handle(): array
    {
        $today = CarbonImmutable::today();
        $threshold = (int) config('shtab.overload_threshold');

        $people = ShtabPerson::query()->active()
            ->with(['activeAssignments.object:id,name,emoji,focus_level'])
            ->orderBy('sort')->orderBy('name')
            ->get();

        $objects = ShtabObject::query()->active()
            ->with(['metrics', 'activeAssignments.person:id,name,initials,class,color'])
            ->orderByDesc('focus_level')->orderBy('sort')->orderBy('name')
            ->get();

        return [
            'people' => $people->map(function (ShtabPerson $person) use ($today, $threshold): array {
                $hotCount = $person->activeAssignments
                    ->filter(fn (ShtabAssignment $a): bool => ($a->object?->focus_level ?? 0) >= 1)
                    ->count();

                return [
                    'id' => $person->id,
                    'name' => $person->name,
                    'initials' => $person->initials,
                    'class' => $person->class,
                    'color' => $person->color,
                    'is_direct' => $person->is_direct,
                    'manager_id' => $person->manager_id,
                    'is_me' => $person->is_me,
                    'assignments' => $person->activeAssignments->map(fn (ShtabAssignment $a): array => [
                        'id' => $a->id,
                        'object_id' => $a->object_id,
                        'object_name' => $a->object?->name,
                        'object_emoji' => $a->object?->emoji,
                        'role_label' => $a->role_label,
                        'comment' => $a->comment,
                        'started_at' => $a->started_at->toDateString(),
                        'days' => (int) $a->started_at->diffInDays($today),
                    ])->values()->all(),
                    'focus_count' => $person->activeAssignments->count(),
                    'hot_count' => $hotCount,
                    'is_overloaded' => $hotCount > $threshold,
                    'in_reserve' => $person->activeAssignments->isEmpty(),
                ];
            })->values()->all(),
            'objects' => $objects->map(function (ShtabObject $object) use ($today): array {
                $uncovered = $object->activeAssignments->isEmpty();
                $uncoveredDays = null;

                if ($uncovered) {
                    $lastEnd = ShtabAssignment::query()
                        ->where('object_id', $object->id)
                        ->whereNotNull('ended_at')
                        ->max('ended_at');
                    // startOfDay: created_at несёт время суток, без нормализации diffInDays усекает день.
                    $from = ($lastEnd ? CarbonImmutable::parse($lastEnd) : $object->created_at->toImmutable())->startOfDay();
                    $uncoveredDays = (int) $from->diffInDays($today);
                }

                return [
                    'id' => $object->id,
                    'type' => $object->type,
                    'parent_id' => $object->parent_id,
                    'name' => $object->name,
                    'emoji' => $object->emoji,
                    'focus_level' => $object->focus_level,
                    'color' => $object->color,
                    'metrics' => $object->metrics->map(fn (ShtabMetric $m): array => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'status' => $m->status,
                        'value_text' => $m->value_text,
                    ])->values()->all(),
                    'assignments' => $object->activeAssignments->map(fn (ShtabAssignment $a): array => [
                        'id' => $a->id,
                        'person_id' => $a->person_id,
                        'person_name' => $a->person?->name,
                        'person_initials' => $a->person?->initials,
                        'person_color' => $a->person?->color,
                        'role_label' => $a->role_label,
                        'started_at' => $a->started_at->toDateString(),
                        'days' => (int) $a->started_at->diffInDays($today),
                    ])->values()->all(),
                    'is_uncovered' => $uncovered,
                    'uncovered_days' => $uncoveredDays,
                ];
            })->values()->all(),
            'business_metrics' => ShtabMetric::query()
                ->whereNull('object_id')->orderBy('sort')->get()
                ->map(fn (ShtabMetric $m): array => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'status' => $m->status,
                    'value_text' => $m->value_text,
                ])->values()->all(),
        ];
    }
}
```

- [ ] **Step 4.4: Run tests to verify they pass**

```bash
php artisan config:clear && php artisan test --compact --filter=ShtabBoardTest
```
Expected: PASS (7 tests). Also re-run `--filter=ShtabAccessTest` — still PASS.

- [ ] **Step 4.5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(shtab): board assembly with reserve/overload/coverage flags"
```

---

## Task 5: Assignment mutations (create / end / move)

**Files:**
- Modify: `routes/web.php` (inside the shtab group)
- Create: `app/Http/Controllers/Shtab/AssignmentsController.php`
- Test: `tests/Feature/Shtab/ShtabAssignmentsTest.php`

- [ ] **Step 5.1: Write the failing test**

```php
<?php

use App\Models\ShtabAssignment;
use App\Models\ShtabEvent;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use App\Models\User;

function shtabAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('creates an assignment and writes a started event', function () {
    $person = ShtabPerson::factory()->create();
    $object = ShtabObject::factory()->create();

    $this->actingAs(shtabAdmin())
        ->post('/shtab/assignments', [
            'person_id' => $person->id,
            'object_id' => $object->id,
            'role_label' => 'аналитика',
            'comment' => 'на месяц, до релиза',
        ])
        ->assertRedirect();

    $assignment = ShtabAssignment::sole();
    expect($assignment->started_at->toDateString())->toBe(now()->toDateString())
        ->and($assignment->ended_at)->toBeNull();

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('assignment_started')
        ->and($event->person_id)->toBe($person->id)
        ->and($event->object_id)->toBe($object->id)
        ->and($event->payload['role_label'])->toBe('аналитика')
        ->and($event->comment)->toBe('на месяц, до релиза');
});

it('rejects a duplicate active assignment for the same person and object', function () {
    $existing = ShtabAssignment::factory()->create();

    $this->actingAs(shtabAdmin())
        ->from('/shtab')
        ->post('/shtab/assignments', [
            'person_id' => $existing->person_id,
            'object_id' => $existing->object_id,
            'role_label' => 'дубль',
        ])
        ->assertRedirect('/shtab')
        ->assertSessionHasErrors('person_id');

    expect(ShtabAssignment::count())->toBe(1);
});

it('ends an assignment and writes an ended event', function () {
    $assignment = ShtabAssignment::factory()->create(['started_at' => now()->subDays(10)->toDateString()]);

    $this->actingAs(shtabAdmin())
        ->post("/shtab/assignments/{$assignment->id}/end", ['comment' => 'релиз вышел'])
        ->assertRedirect();

    expect($assignment->refresh()->ended_at->toDateString())->toBe(now()->toDateString());

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('assignment_ended')
        ->and($event->payload['role_label'])->toBe($assignment->role_label)
        ->and($event->payload['days'])->toBe(10)
        ->and($event->comment)->toBe('релиз вышел');
});

it('cannot end an already ended assignment', function () {
    $assignment = ShtabAssignment::factory()->create(['ended_at' => now()->toDateString()]);

    $this->actingAs(shtabAdmin())
        ->from('/shtab')
        ->post("/shtab/assignments/{$assignment->id}/end")
        ->assertSessionHasErrors('assignment');
});

it('moves an assignment: ends the old one and starts a new one atomically', function () {
    $assignment = ShtabAssignment::factory()->create();
    $target = ShtabObject::factory()->create();

    $this->actingAs(shtabAdmin())
        ->post("/shtab/assignments/{$assignment->id}/move", [
            'object_id' => $target->id,
            'role_label' => 'ведёт',
            'comment' => 'перекинул на запуск',
        ])
        ->assertRedirect();

    expect($assignment->refresh()->ended_at)->not->toBeNull();

    $new = ShtabAssignment::query()->active()->sole();
    expect($new->object_id)->toBe($target->id)
        ->and($new->person_id)->toBe($assignment->person_id)
        ->and(ShtabEvent::query()->pluck('type')->all())
        ->toBe(['assignment_ended', 'assignment_started']);
});

it('forbids non-admins from all assignment routes', function () {
    $assignment = ShtabAssignment::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/shtab/assignments', [])->assertForbidden();
    $this->actingAs($user)->post("/shtab/assignments/{$assignment->id}/end")->assertForbidden();
    $this->actingAs($user)->post("/shtab/assignments/{$assignment->id}/move", [])->assertForbidden();
});
```

- [ ] **Step 5.2: Run test to verify it fails**

```bash
php artisan config:clear && php artisan test --compact --filter=ShtabAssignmentsTest
```
Expected: FAIL — 404 (routes missing).

- [ ] **Step 5.3: Add routes**

Inside the `shtab` group in `routes/web.php`:

```php
Route::post('/assignments', [AssignmentsController::class, 'store'])->name('assignments.store');
Route::post('/assignments/{assignment}/end', [AssignmentsController::class, 'end'])->name('assignments.end');
Route::post('/assignments/{assignment}/move', [AssignmentsController::class, 'move'])->name('assignments.move');
```

Add `use App\Http\Controllers\Shtab\AssignmentsController;` at the top.

- [ ] **Step 5.4: Create the controller**

`app/Http/Controllers/Shtab/AssignmentsController.php`:

```php
<?php

namespace App\Http\Controllers\Shtab;

use App\Http\Controllers\Controller;
use App\Models\ShtabAssignment;
use App\Models\ShtabEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'person_id' => ['required', 'integer', 'exists:shtab_people,id'],
            'object_id' => ['required', 'integer', 'exists:shtab_objects,id'],
            'role_label' => ['required', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $duplicate = ShtabAssignment::query()->active()
            ->where('person_id', $data['person_id'])
            ->where('object_id', $data['object_id'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'person_id' => 'Этот человек уже назначен на эту территорию.',
            ]);
        }

        DB::transaction(function () use ($data): void {
            ShtabAssignment::query()->create([
                ...$data,
                'started_at' => now()->toDateString(),
            ]);

            ShtabEvent::record('assignment_started', [
                'person_id' => $data['person_id'],
                'object_id' => $data['object_id'],
                'payload' => ['role_label' => $data['role_label']],
                'comment' => $data['comment'] ?? null,
            ]);
        });

        return redirect()->back();
    }

    public function end(Request $request, ShtabAssignment $assignment): RedirectResponse
    {
        $data = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->endAssignment($assignment, $data['comment'] ?? null);

        return redirect()->back();
    }

    public function move(Request $request, ShtabAssignment $assignment): RedirectResponse
    {
        $data = $request->validate([
            'object_id' => ['required', 'integer', 'exists:shtab_objects,id'],
            'role_label' => ['required', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($assignment, $data): void {
            $this->endAssignment($assignment, $data['comment'] ?? null);

            ShtabAssignment::query()->create([
                'person_id' => $assignment->person_id,
                'object_id' => $data['object_id'],
                'role_label' => $data['role_label'],
                'comment' => $data['comment'] ?? null,
                'started_at' => now()->toDateString(),
            ]);

            ShtabEvent::record('assignment_started', [
                'person_id' => $assignment->person_id,
                'object_id' => $data['object_id'],
                'payload' => ['role_label' => $data['role_label']],
                'comment' => $data['comment'] ?? null,
            ]);
        });

        return redirect()->back();
    }

    private function endAssignment(ShtabAssignment $assignment, ?string $comment): void
    {
        if ($assignment->ended_at !== null) {
            throw ValidationException::withMessages([
                'assignment' => 'Назначение уже завершено.',
            ]);
        }

        DB::transaction(function () use ($assignment, $comment): void {
            $today = CarbonImmutable::today();
            $assignment->update(['ended_at' => $today->toDateString()]);

            ShtabEvent::record('assignment_ended', [
                'person_id' => $assignment->person_id,
                'object_id' => $assignment->object_id,
                'payload' => [
                    'role_label' => $assignment->role_label,
                    'days' => (int) $assignment->started_at->diffInDays($today),
                ],
                'comment' => $comment,
            ]);
        });
    }
}
```

- [ ] **Step 5.5: Run tests to verify they pass**

```bash
php artisan config:clear && php artisan test --compact --filter=ShtabAssignmentsTest
```
Expected: PASS (6 tests).

- [ ] **Step 5.6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(shtab): assignment create/end/move with chronicle events"
```

---

## Task 6: Metric status + object focus mutations

**Files:**
- Modify: `routes/web.php` (shtab group)
- Create: `app/Http/Controllers/Shtab/MetricsController.php` (status only; CRUD in Task 7)
- Create: `app/Http/Controllers/Shtab/ObjectsController.php` (focus only; CRUD in Task 7)
- Test: `tests/Feature/Shtab/ShtabStatusTest.php`

- [ ] **Step 6.1: Write the failing test**

```php
<?php

use App\Models\ShtabEvent;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\User;

function statusAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('changes metric status and records old and new values', function () {
    $metric = ShtabMetric::factory()->create(['status' => 'green', 'value_text' => '12%']);

    $this->actingAs(statusAdmin())
        ->patch("/shtab/metrics/{$metric->id}/status", [
            'status' => 'red',
            'value_text' => '8%',
            'comment' => 'просели после релиза',
        ])
        ->assertRedirect();

    expect($metric->refresh()->status)->toBe('red')
        ->and($metric->value_text)->toBe('8%');

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('metric_status_changed')
        ->and($event->metric_id)->toBe($metric->id)
        ->and($event->object_id)->toBe($metric->object_id)
        ->and($event->payload)->toBe(['from' => 'green', 'to' => 'red', 'value_text' => '8%'])
        ->and($event->comment)->toBe('просели после релиза');
});

it('rejects unknown metric status', function () {
    $metric = ShtabMetric::factory()->create();

    $this->actingAs(statusAdmin())
        ->from('/shtab')
        ->patch("/shtab/metrics/{$metric->id}/status", ['status' => 'purple'])
        ->assertSessionHasErrors('status');
});

it('changes object focus level and records the change', function () {
    $object = ShtabObject::factory()->create(['focus_level' => 0]);

    $this->actingAs(statusAdmin())
        ->patch("/shtab/objects/{$object->id}/focus", ['focus_level' => 2])
        ->assertRedirect();

    expect($object->refresh()->focus_level)->toBe(2);

    $event = ShtabEvent::sole();
    expect($event->type)->toBe('focus_level_changed')
        ->and($event->object_id)->toBe($object->id)
        ->and($event->payload)->toBe(['from' => 0, 'to' => 2]);
});
```

- [ ] **Step 6.2: Run test to verify it fails**

```bash
php artisan config:clear && php artisan test --compact --filter=ShtabStatusTest
```
Expected: FAIL — 404.

- [ ] **Step 6.3: Add routes**

```php
Route::patch('/metrics/{metric}/status', [MetricsController::class, 'status'])->name('metrics.status');
Route::patch('/objects/{object}/focus', [ObjectsController::class, 'focus'])->name('objects.focus');
```

Imports: `use App\Http\Controllers\Shtab\MetricsController;` and `use App\Http\Controllers\Shtab\ObjectsController;`.

- [ ] **Step 6.4: Create controllers**

`app/Http/Controllers/Shtab/MetricsController.php`:

```php
<?php

namespace App\Http\Controllers\Shtab;

use App\Http\Controllers\Controller;
use App\Models\ShtabEvent;
use App\Models\ShtabMetric;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MetricsController extends Controller
{
    public function status(Request $request, ShtabMetric $metric): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['green', 'yellow', 'red'])],
            'value_text' => ['nullable', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($metric, $data): void {
            $from = $metric->status;
            $metric->update([
                'status' => $data['status'],
                'value_text' => $data['value_text'] ?? $metric->value_text,
            ]);

            ShtabEvent::record('metric_status_changed', [
                'metric_id' => $metric->id,
                'object_id' => $metric->object_id,
                'payload' => [
                    'from' => $from,
                    'to' => $data['status'],
                    'value_text' => $metric->refresh()->value_text,
                ],
                'comment' => $data['comment'] ?? null,
            ]);
        });

        return redirect()->back();
    }
}
```

`app/Http/Controllers/Shtab/ObjectsController.php`:

```php
<?php

namespace App\Http\Controllers\Shtab;

use App\Http\Controllers\Controller;
use App\Models\ShtabEvent;
use App\Models\ShtabObject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ObjectsController extends Controller
{
    public function focus(Request $request, ShtabObject $object): RedirectResponse
    {
        $data = $request->validate([
            'focus_level' => ['required', 'integer', Rule::in([0, 1, 2])],
        ]);

        DB::transaction(function () use ($object, $data): void {
            $from = $object->focus_level;
            $object->update(['focus_level' => $data['focus_level']]);

            ShtabEvent::record('focus_level_changed', [
                'object_id' => $object->id,
                'payload' => ['from' => $from, 'to' => $data['focus_level']],
            ]);
        });

        return redirect()->back();
    }
}
```

- [ ] **Step 6.5: Run tests to verify they pass**

```bash
php artisan config:clear && php artisan test --compact --filter=ShtabStatusTest
```
Expected: PASS (3 tests).

- [ ] **Step 6.6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(shtab): metric status and focus level mutations"
```

---

## Task 7: CRUD for people, objects, metrics (+ archive guards)

**Files:**
- Modify: `routes/web.php` (shtab group)
- Create: `app/Http/Controllers/Shtab/PeopleController.php`
- Modify: `app/Http/Controllers/Shtab/ObjectsController.php` (add store/update/archive)
- Modify: `app/Http/Controllers/Shtab/MetricsController.php` (add store/update/destroy)
- Test: `tests/Feature/Shtab/ShtabCrudTest.php`

- [ ] **Step 7.1: Write the failing test**

```php
<?php

use App\Models\ShtabAssignment;
use App\Models\ShtabEvent;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use App\Models\User;

function crudAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('creates and updates a person', function () {
    $this->actingAs(crudAdmin())
        ->post('/shtab/people', [
            'name' => 'Вика',
            'initials' => 'ВК',
            'class' => 'Аналитик',
            'color' => '#8B5CF6',
            'is_direct' => true,
        ])
        ->assertRedirect();

    $person = ShtabPerson::sole();
    expect($person->name)->toBe('Вика')
        ->and(ShtabEvent::query()->where('type', 'person_created')->count())->toBe(1);

    $this->actingAs(crudAdmin())
        ->put("/shtab/people/{$person->id}", [
            'name' => 'Вика Соколова',
            'initials' => 'ВК',
            'class' => 'Аналитик',
            'color' => '#8B5CF6',
            'is_direct' => false,
        ])
        ->assertRedirect();

    expect($person->refresh()->name)->toBe('Вика Соколова')
        ->and($person->is_direct)->toBeFalse();
});

it('blocks archiving a person with active assignments', function () {
    $assignment = ShtabAssignment::factory()->create();

    $this->actingAs(crudAdmin())
        ->from('/shtab')
        ->post("/shtab/people/{$assignment->person_id}/archive")
        ->assertSessionHasErrors('person');

    expect($assignment->person->refresh()->archived_at)->toBeNull();
});

it('archives a person after assignments are ended', function () {
    $assignment = ShtabAssignment::factory()->create(['ended_at' => now()->toDateString()]);

    $this->actingAs(crudAdmin())
        ->post("/shtab/people/{$assignment->person_id}/archive")
        ->assertRedirect();

    expect($assignment->person->refresh()->archived_at)->not->toBeNull()
        ->and(ShtabEvent::query()->where('type', 'person_archived')->count())->toBe(1);
});

it('creates a project inside a product and validates type', function () {
    $product = ShtabObject::factory()->create(['type' => 'product']);

    $this->actingAs(crudAdmin())
        ->post('/shtab/objects', [
            'type' => 'project',
            'parent_id' => $product->id,
            'name' => 'Запуск v2',
            'emoji' => '🚀',
            'focus_level' => 1,
            'color' => '#14B8A6',
        ])
        ->assertRedirect();

    $project = ShtabObject::query()->where('type', 'project')->sole();
    expect($project->parent->is($product))->toBeTrue()
        ->and(ShtabEvent::query()->where('type', 'object_created')->count())->toBe(1);

    $this->actingAs(crudAdmin())
        ->from('/shtab')
        ->post('/shtab/objects', ['type' => 'kingdom', 'name' => 'x'])
        ->assertSessionHasErrors('type');
});

it('blocks archiving an object with active assignments', function () {
    $assignment = ShtabAssignment::factory()->create();

    $this->actingAs(crudAdmin())
        ->from('/shtab')
        ->post("/shtab/objects/{$assignment->object_id}/archive")
        ->assertSessionHasErrors('object');
});

it('archives an empty object with an event', function () {
    $object = ShtabObject::factory()->create();

    $this->actingAs(crudAdmin())
        ->post("/shtab/objects/{$object->id}/archive")
        ->assertRedirect();

    expect($object->refresh()->archived_at)->not->toBeNull()
        ->and(ShtabEvent::query()->where('type', 'object_archived')->count())->toBe(1);
});

it('creates, updates and deletes a metric', function () {
    $object = ShtabObject::factory()->create();

    $this->actingAs(crudAdmin())
        ->post('/shtab/metrics', ['object_id' => $object->id, 'name' => 'маржа', 'status' => 'green'])
        ->assertRedirect();

    $metric = ShtabMetric::sole();

    $this->actingAs(crudAdmin())
        ->put("/shtab/metrics/{$metric->id}", ['name' => 'маржа %', 'object_id' => null])
        ->assertRedirect();

    expect($metric->refresh()->name)->toBe('маржа %')
        ->and($metric->object_id)->toBeNull();

    $this->actingAs(crudAdmin())
        ->delete("/shtab/metrics/{$metric->id}")
        ->assertRedirect();

    expect(ShtabMetric::count())->toBe(0);
});
```

- [ ] **Step 7.2: Run test to verify it fails**

```bash
php artisan config:clear && php artisan test --compact --filter=ShtabCrudTest
```
Expected: FAIL — 404.

- [ ] **Step 7.3: Add routes**

```php
Route::post('/people', [PeopleController::class, 'store'])->name('people.store');
Route::put('/people/{person}', [PeopleController::class, 'update'])->name('people.update');
Route::post('/people/{person}/archive', [PeopleController::class, 'archive'])->name('people.archive');

Route::post('/objects', [ObjectsController::class, 'store'])->name('objects.store');
Route::put('/objects/{object}', [ObjectsController::class, 'update'])->name('objects.update');
Route::post('/objects/{object}/archive', [ObjectsController::class, 'archive'])->name('objects.archive');

Route::post('/metrics', [MetricsController::class, 'store'])->name('metrics.store');
Route::put('/metrics/{metric}', [MetricsController::class, 'update'])->name('metrics.update');
Route::delete('/metrics/{metric}', [MetricsController::class, 'destroy'])->name('metrics.destroy');
```

Import: `use App\Http\Controllers\Shtab\PeopleController;`.

- [ ] **Step 7.4: Create PeopleController**

`app/Http/Controllers/Shtab/PeopleController.php`:

```php
<?php

namespace App\Http\Controllers\Shtab;

use App\Http\Controllers\Controller;
use App\Models\ShtabEvent;
use App\Models\ShtabPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PeopleController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data): void {
            $person = ShtabPerson::query()->create($data);
            ShtabEvent::record('person_created', ['person_id' => $person->id]);
        });

        return redirect()->back();
    }

    public function update(Request $request, ShtabPerson $person): RedirectResponse
    {
        $person->update($this->validated($request));

        return redirect()->back();
    }

    public function archive(ShtabPerson $person): RedirectResponse
    {
        if ($person->activeAssignments()->exists()) {
            throw ValidationException::withMessages([
                'person' => 'Сначала сними человека со всех территорий.',
            ]);
        }

        DB::transaction(function () use ($person): void {
            $person->update(['archived_at' => now()]);
            ShtabEvent::record('person_archived', ['person_id' => $person->id]);
        });

        return redirect()->back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'initials' => ['required', 'string', 'max:8'],
            'class' => ['required', 'string', 'max:50'],
            'color' => ['required', 'string', 'max:7'],
            'is_direct' => ['boolean'],
            'manager_id' => ['nullable', 'integer', 'exists:shtab_people,id'],
            'is_me' => ['boolean'],
        ]);
    }
}
```

- [ ] **Step 7.5: Extend ObjectsController**

Add to `app/Http/Controllers/Shtab/ObjectsController.php` (keep `focus()` from Task 6; add `use App\Models\ShtabAssignment;` is NOT needed — guards use relations; add `use Illuminate\Validation\ValidationException;`):

```php
public function store(Request $request): RedirectResponse
{
    $data = $this->validated($request);

    DB::transaction(function () use ($data): void {
        $object = ShtabObject::query()->create($data);
        ShtabEvent::record('object_created', ['object_id' => $object->id]);
    });

    return redirect()->back();
}

public function update(Request $request, ShtabObject $object): RedirectResponse
{
    $object->update($this->validated($request));

    return redirect()->back();
}

public function archive(ShtabObject $object): RedirectResponse
{
    if ($object->activeAssignments()->exists()) {
        throw ValidationException::withMessages([
            'object' => 'Сначала сними людей с этой территории.',
        ]);
    }

    DB::transaction(function () use ($object): void {
        $object->update(['archived_at' => now()]);
        ShtabEvent::record('object_archived', ['object_id' => $object->id]);
    });

    return redirect()->back();
}

/**
 * @return array<string, mixed>
 */
private function validated(Request $request): array
{
    return $request->validate([
        'type' => ['required', Rule::in(['product', 'project', 'enabler'])],
        'parent_id' => ['nullable', 'integer', 'exists:shtab_objects,id'],
        'name' => ['required', 'string', 'max:100'],
        'emoji' => ['nullable', 'string', 'max:16'],
        'focus_level' => ['required', 'integer', Rule::in([0, 1, 2])],
        'color' => ['required', 'string', 'max:7'],
    ]);
}
```

- [ ] **Step 7.6: Extend MetricsController**

Add to `app/Http/Controllers/Shtab/MetricsController.php`:

```php
public function store(Request $request): RedirectResponse
{
    ShtabMetric::query()->create($this->validated($request));

    return redirect()->back();
}

public function update(Request $request, ShtabMetric $metric): RedirectResponse
{
    $metric->update($this->validated($request));

    return redirect()->back();
}

public function destroy(ShtabMetric $metric): RedirectResponse
{
    $metric->delete();

    return redirect()->back();
}

/**
 * @return array<string, mixed>
 */
private function validated(Request $request): array
{
    return $request->validate([
        'object_id' => ['nullable', 'integer', 'exists:shtab_objects,id'],
        'name' => ['required', 'string', 'max:100'],
        'status' => ['sometimes', Rule::in(['green', 'yellow', 'red'])],
        'value_text' => ['nullable', 'string', 'max:100'],
    ]);
}
```

- [ ] **Step 7.7: Run tests to verify they pass**

```bash
php artisan config:clear && php artisan test --compact --filter=ShtabCrudTest
```
Expected: PASS (7 tests). Then run the whole suite once: `php artisan test --compact --filter=Shtab` — all green.

- [ ] **Step 7.8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(shtab): people/objects/metrics CRUD with archive guards"
```

---

## Task 8: Frontend — types + static Карта tab

Backend is complete; the rest is React. No JS test infra exists in this repo — frontend is verified by `npm run build` + browser smoke.

**Files:**
- Create: `resources/js/pages/shtab/types.ts`
- Create: `resources/js/pages/shtab/components/person-chip.tsx`
- Create: `resources/js/pages/shtab/components/sector-card.tsx`
- Create: `resources/js/pages/shtab/components/chronicle-panel.tsx`
- Modify: `resources/js/pages/shtab/index.tsx` (real layout: top bar, tabs, map grid)

- [ ] **Step 8.1: Types mirroring the board payload**

`resources/js/pages/shtab/types.ts`:

```ts
export type MetricStatus = 'green' | 'yellow' | 'red';

export interface BoardMetric {
    id: number;
    name: string;
    status: MetricStatus;
    value_text: string | null;
}

export interface PersonAssignment {
    id: number;
    object_id: number;
    object_name: string | null;
    object_emoji: string | null;
    role_label: string;
    comment: string | null;
    started_at: string;
    days: number;
}

export interface BoardPerson {
    id: number;
    name: string;
    initials: string;
    class: string;
    color: string;
    is_direct: boolean;
    manager_id: number | null;
    is_me: boolean;
    assignments: PersonAssignment[];
    focus_count: number;
    hot_count: number;
    is_overloaded: boolean;
    in_reserve: boolean;
}

export interface ObjectAssignment {
    id: number;
    person_id: number;
    person_name: string | null;
    person_initials: string | null;
    person_color: string | null;
    role_label: string;
    started_at: string;
    days: number;
}

export interface BoardObject {
    id: number;
    type: 'product' | 'project' | 'enabler';
    parent_id: number | null;
    name: string;
    emoji: string | null;
    focus_level: 0 | 1 | 2;
    color: string;
    metrics: BoardMetric[];
    assignments: ObjectAssignment[];
    is_uncovered: boolean;
    uncovered_days: number | null;
}

export interface Board {
    people: BoardPerson[];
    objects: BoardObject[];
    business_metrics: BoardMetric[];
}

export interface ChronicleEvent {
    id: number;
    type: string;
    person: { id: number; name: string; initials: string; color: string } | null;
    object: { id: number; name: string; emoji: string | null } | null;
    metric: { id: number; name: string } | null;
    payload: Record<string, unknown> | null;
    comment: string | null;
    created_at: string;
}

export const FIRE: Record<number, string> = { 0: '', 1: '🔥', 2: '🔥🔥' };
export const STATUS_DOT: Record<MetricStatus, string> = {
    green: 'bg-green-500',
    yellow: 'bg-yellow-500',
    red: 'bg-red-500',
};
```

- [ ] **Step 8.2: Person chip (compact character card)**

`resources/js/pages/shtab/components/person-chip.tsx`:

```tsx
import type { BoardPerson, ObjectAssignment } from '../types';

interface Props {
    person: BoardPerson;
    assignment?: ObjectAssignment; // задан, когда чип живёт внутри сектора
    onClick?: () => void;
    draggable?: boolean;
    onDragStart?: (e: React.DragEvent) => void;
}

// Компактная карточка-персонаж: медальон, имя, роль/класс, гемы-статы, дата.
export default function PersonChip({ person, assignment, onClick, draggable, onDragStart }: Props) {
    return (
        <button
            type="button"
            onClick={onClick}
            draggable={draggable}
            onDragStart={onDragStart}
            className="flex w-[104px] cursor-pointer flex-col items-center rounded-lg border border-[#DFE4EC] bg-white px-2 py-2 text-center shadow-sm transition hover:shadow-md"
        >
            <span
                className="flex h-9 w-9 items-center justify-center rounded-full text-xs font-extrabold text-white ring-2 ring-offset-1"
                style={{ backgroundColor: person.color, ['--tw-ring-color' as string]: `${person.color}55` }}
            >
                {person.initials}
            </span>
            <span className="mt-1 text-[11px] font-bold text-gray-900">{person.name}</span>
            <span className="text-[9px] font-semibold tracking-wide text-gray-500 uppercase">
                {assignment ? assignment.role_label : person.class}
            </span>
            <span className="mt-1 flex gap-1">
                <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[9px] font-extrabold text-gray-700">
                    {person.focus_count}
                </span>
                <span
                    className={`rounded px-1.5 py-0.5 text-[9px] font-extrabold ${person.is_overloaded ? 'bg-red-100 text-red-700' : 'bg-orange-50 text-orange-600'}`}
                >
                    🔥{person.hot_count}
                </span>
            </span>
            {assignment && (
                <span className="mt-1 text-[9px] text-gray-400">
                    с {assignment.started_at.slice(8, 10)}.{assignment.started_at.slice(5, 7)} · {assignment.days} дн
                </span>
            )}
        </button>
    );
}
```

- [ ] **Step 8.3: Sector card**

`resources/js/pages/shtab/components/sector-card.tsx`:

```tsx
import type { Board, BoardObject } from '../types';
import { FIRE, STATUS_DOT } from '../types';
import PersonChip from './person-chip';

interface Props {
    object: BoardObject;
    board: Board;
    onAssignClick: (objectId: number) => void;
    onPersonDrop: (personId: number, assignmentId: number | null, objectId: number) => void;
    onPersonClick: (assignmentId: number) => void;
    onMetricClick: (metricId: number) => void;
    onEditClick: (objectId: number) => void;
}

const TYPE_LABEL: Record<BoardObject['type'], string> = {
    product: '',
    project: 'проект',
    enabler: 'энейблер',
};

export default function SectorCard({ object, board, onAssignClick, onPersonDrop, onPersonClick, onMetricClick, onEditClick }: Props) {
    const uncoveredHot = object.is_uncovered && object.focus_level >= 1;
    const borderStyle = object.is_uncovered
        ? { borderColor: uncoveredHot ? '#D97706' : '#94A3B8' }
        : { borderColor: object.color, backgroundColor: `${object.color}14` };

    return (
        <div
            className={`rounded-xl border-[1.5px] p-3 ${object.is_uncovered ? 'border-dashed' : ''}`}
            style={borderStyle}
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => {
                e.preventDefault();
                const personId = Number(e.dataTransfer.getData('personId'));
                const assignmentId = e.dataTransfer.getData('assignmentId');
                if (personId) onPersonDrop(personId, assignmentId ? Number(assignmentId) : null, object.id);
            }}
        >
            <div className="mb-2 flex items-center justify-between">
                <button type="button" onClick={() => onEditClick(object.id)} className="cursor-pointer text-[11px] font-extrabold tracking-wide text-[#3B475C] uppercase">
                    {FIRE[object.focus_level]} {object.emoji} {object.name}
                    {TYPE_LABEL[object.type] && <span className="ml-1 font-semibold text-gray-400 normal-case">· {TYPE_LABEL[object.type]}</span>}
                </button>
                <span className="flex gap-1">
                    {object.metrics.map((m) => (
                        <button
                            key={m.id}
                            type="button"
                            title={`${m.name}${m.value_text ? `: ${m.value_text}` : ''}`}
                            onClick={() => onMetricClick(m.id)}
                            className={`h-3 w-3 cursor-pointer rounded-full ${STATUS_DOT[m.status]}`}
                        />
                    ))}
                </span>
            </div>
            <div className="flex flex-wrap gap-2">
                {object.assignments.map((a) => {
                    const person = board.people.find((p) => p.id === a.person_id);
                    return person ? (
                        <PersonChip
                            key={a.id}
                            person={person}
                            assignment={a}
                            draggable
                            onDragStart={(e) => {
                                e.dataTransfer.setData('personId', String(person.id));
                                e.dataTransfer.setData('assignmentId', String(a.id));
                            }}
                            onClick={() => onPersonClick(a.id)}
                        />
                    ) : null;
                })}
                <button
                    type="button"
                    onClick={() => onAssignClick(object.id)}
                    className="flex h-[104px] w-[64px] cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-gray-400 text-gray-400 transition hover:border-gray-600 hover:text-gray-600"
                >
                    <span className="text-lg">+</span>
                    {object.is_uncovered && (
                        <span className={`px-1 text-center text-[9px] ${uncoveredHot ? 'text-amber-700' : ''}`}>
                            пусто {object.uncovered_days} дн
                        </span>
                    )}
                </button>
            </div>
        </div>
    );
}
```

- [ ] **Step 8.4: Chronicle panel**

`resources/js/pages/shtab/components/chronicle-panel.tsx`:

```tsx
import type { ChronicleEvent } from '../types';

const TYPE_META: Record<string, { dot: string; label: (e: ChronicleEvent) => string }> = {
    assignment_started: {
        dot: 'bg-emerald-500',
        label: (e) => `${e.person?.name ?? '—'} → ${e.object?.name ?? '—'}, ${String(e.payload?.role_label ?? '')}`,
    },
    assignment_ended: {
        dot: 'bg-slate-400',
        label: (e) => `${e.person?.name ?? '—'} снят с ${e.object?.name ?? '—'} (${String(e.payload?.days ?? '?')} дн)`,
    },
    metric_status_changed: {
        dot: 'bg-red-500',
        label: (e) => `${e.metric?.name ?? '—'}: ${String(e.payload?.from)} → ${String(e.payload?.to)}`,
    },
    focus_level_changed: {
        dot: 'bg-orange-500',
        label: (e) => `Фокус ${e.object?.name ?? '—'}: ${String(e.payload?.from)} → ${String(e.payload?.to)}`,
    },
    person_created: { dot: 'bg-blue-400', label: (e) => `Добавлен ${e.person?.name ?? '—'}` },
    person_archived: { dot: 'bg-slate-400', label: (e) => `В архив: ${e.person?.name ?? '—'}` },
    object_created: { dot: 'bg-blue-400', label: (e) => `Новая территория: ${e.object?.name ?? '—'}` },
    object_archived: { dot: 'bg-slate-400', label: (e) => `Территория в архиве: ${e.object?.name ?? '—'}` },
};

function formatWhen(iso: string): string {
    const d = new Date(iso);
    const days = Math.floor((Date.now() - d.getTime()) / 86_400_000);
    if (days === 0) return `сегодня, ${d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`;
    if (days === 1) return 'вчера';
    return `${d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })} · ${days} дн назад`;
}

export default function ChroniclePanel({ events, limit }: { events: ChronicleEvent[]; limit?: number }) {
    const shown = limit ? events.slice(0, limit) : events;

    return (
        <div className="space-y-3">
            {shown.length === 0 && <p className="text-xs text-gray-400">Пока пусто — первое назначение появится здесь.</p>}
            {shown.map((e) => {
                const meta = TYPE_META[e.type] ?? { dot: 'bg-gray-300', label: () => e.type };
                return (
                    <div key={e.id} className="flex gap-2">
                        <span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${meta.dot}`} />
                        <div>
                            <p className="text-xs font-semibold text-gray-700">{meta.label(e)}</p>
                            <p className="text-[10px] text-gray-400">
                                {formatWhen(e.created_at)}
                                {e.comment && <span> · «{e.comment}»</span>}
                            </p>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
```

- [ ] **Step 8.5: Assemble the Карта tab in `index.tsx`**

Replace `resources/js/pages/shtab/index.tsx`:

```tsx
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import ChroniclePanel from './components/chronicle-panel';
import SectorCard from './components/sector-card';
import PersonChip from './components/person-chip';
import type { Board, ChronicleEvent } from './types';
import { STATUS_DOT } from './types';

interface Props {
    board: Board;
    events: ChronicleEvent[];
}

type Tab = 'map' | 'people' | 'chronicle';

export default function ShtabIndex({ board, events }: Props) {
    const [tab, setTab] = useState<Tab>('map');
    const reserve = board.people.filter((p) => p.in_reserve);

    // Обработчики диалогов подключаются в Задаче 9; пока — заглушки.
    const noop = () => undefined;

    return (
        <div className="min-h-screen bg-[#F2F0EA]">
            <Head title="Штаб" />
            <header className="flex items-center gap-6 border-b border-[#E4E1D8] bg-white px-5 py-2.5">
                <span className="text-sm font-extrabold text-gray-900">⌘ ШТАБ</span>
                <nav className="flex gap-1">
                    {(
                        [
                            ['map', 'Карта'],
                            ['people', 'Люди'],
                            ['chronicle', 'Хроника'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setTab(key)}
                            className={`cursor-pointer rounded-full px-3 py-1 text-xs font-bold ${tab === key ? 'bg-[#EDEAE0] text-gray-900' : 'text-gray-500 hover:text-gray-800'}`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
                <div className="ml-auto flex items-center gap-2">
                    {board.business_metrics.map((m) => (
                        <span key={m.id} className="flex items-center gap-1 text-[10px] text-gray-500">
                            <span className={`h-2.5 w-2.5 rounded-full ${STATUS_DOT[m.status]}`} />
                            {m.name}
                        </span>
                    ))}
                    <span className="ml-3 text-[10px] text-gray-400">Резерв:</span>
                    <div className="flex gap-1">
                        {reserve.map((p) => (
                            <span
                                key={p.id}
                                title={p.name}
                                draggable
                                onDragStart={(e) => e.dataTransfer.setData('personId', String(p.id))}
                                className="flex h-6 w-6 cursor-grab items-center justify-center rounded-full text-[9px] font-extrabold text-white ring-1 ring-white"
                                style={{ backgroundColor: p.color }}
                            >
                                {p.initials}
                            </span>
                        ))}
                        {reserve.length === 0 && <span className="text-[10px] text-gray-300">пуст</span>}
                    </div>
                </div>
            </header>

            {tab === 'map' && (
                <main className="grid grid-cols-1 gap-4 p-5 lg:grid-cols-[1fr_300px]">
                    <div className="grid auto-rows-min grid-cols-1 gap-4 md:grid-cols-2">
                        {board.objects.map((object) => (
                            <SectorCard
                                key={object.id}
                                object={object}
                                board={board}
                                onAssignClick={noop}
                                onPersonDrop={noop}
                                onPersonClick={noop}
                                onMetricClick={noop}
                                onEditClick={noop}
                            />
                        ))}
                        {board.objects.length === 0 && (
                            <div className="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-400">
                                Территорий пока нет — добавь первый продукт или проект.
                            </div>
                        )}
                    </div>
                    <aside className="rounded-xl border border-[#E4E1D8] bg-white p-4">
                        <h2 className="mb-3 text-xs font-extrabold text-gray-900">ХРОНИКА</h2>
                        <ChroniclePanel events={events} limit={12} />
                    </aside>
                </main>
            )}

            {tab === 'people' && (
                <main className="p-5">
                    <div className="flex flex-wrap gap-3">
                        {board.people.map((p) => (
                            <PersonChip key={p.id} person={p} />
                        ))}
                    </div>
                </main>
            )}

            {tab === 'chronicle' && (
                <main className="mx-auto max-w-2xl p-5">
                    <div className="rounded-xl border border-[#E4E1D8] bg-white p-5">
                        <ChroniclePanel events={events} />
                    </div>
                </main>
            )}
        </div>
    );
}
```

(Таб «Люди» здесь временный — полноценные крупные карточки в Задаче 10.)

- [ ] **Step 8.6: Build + verify + commit**

```bash
npm run build
php artisan config:clear && php artisan test --compact --filter=Shtab
```
Expected: build OK, tests PASS. Then open `https://gleb.finance/shtab` as Gleb — page renders with empty-state (or data if any).

```bash
git add -A && git commit -m "feat(shtab): static map tab with sectors, chips, chronicle panel"
```

---

## Task 9: Frontend — interactions (dialogs, DnD, CRUD)

**Step 9.0 (backend hardening — added after wave-4 code review; runs AFTER Task 7 lands, files: AssignmentsController, MetricsController, ObjectsController, ShtabAssignmentsTest, ShtabStatusTest):**

1. `AssignmentsController::move` — duplicate-active guard on the TARGET object (mirrors store):

```php
$duplicate = ShtabAssignment::query()->active()
    ->where('person_id', $assignment->person_id)
    ->where('object_id', $data['object_id'])
    ->exists();

if ($duplicate) {
    throw ValidationException::withMessages([
        'object_id' => 'Этот человек уже назначен на эту территорию.',
    ]);
}
```

Test (ShtabAssignmentsTest):

```php
it('rejects moving onto an object where the person is already active', function () {
    $assignment = ShtabAssignment::factory()->create();
    $target = ShtabObject::factory()->create();
    ShtabAssignment::factory()->create(['person_id' => $assignment->person_id, 'object_id' => $target->id]);

    $this->actingAs(shtabAdmin())
        ->from('/shtab')
        ->post("/shtab/assignments/{$assignment->id}/move", ['object_id' => $target->id, 'role_label' => 'дубль'])
        ->assertSessionHasErrors('object_id');

    expect($assignment->refresh()->ended_at)->toBeNull();
});
```

2. No-op guards — skip mutation AND event when nothing changes: in `MetricsController::status` early-return `redirect()->back()` when `$metric->status === $data['status']` and value_text unchanged; in `ObjectsController::focus` when `$object->focus_level === $data['focus_level']`. Tests: posting the same status/focus adds 0 events.

3. Archived-row validation: in AssignmentsController store/move use `Rule::exists('shtab_people', 'id')->whereNull('archived_at')` / `Rule::exists('shtab_objects', 'id')->whereNull('archived_at')` instead of bare `exists:` strings.

**UI amendments for step 9.6 (from the same review):** active tab button gets `aria-current="page"`; reserve chips get `role="img" aria-label={p.name}` and remain draggable; in the map grid, order sectors parent-adjacent: products (focus desc) each followed by their child projects, then standalone objects — implement as a small `orderSectors(board.objects)` helper in index.tsx using `parent_id`.

**Files:**
- Create: `resources/js/pages/shtab/components/assign-dialog.tsx`
- Create: `resources/js/pages/shtab/components/assignment-dialog.tsx` (view/end/move an existing assignment)
- Create: `resources/js/pages/shtab/components/metric-dialog.tsx`
- Create: `resources/js/pages/shtab/components/person-form-dialog.tsx`
- Create: `resources/js/pages/shtab/components/object-form-dialog.tsx`
- Modify: `resources/js/pages/shtab/index.tsx` (wire real handlers, add «+ человек» / «+ территория» buttons)

Use existing ui components: `@/components/ui/dialog`, `@/components/ui/button`, `@/components/ui/input`, `@/components/ui/label`. Mutations via `router.post/put/patch/delete` from `@inertiajs/react` with `preserveScroll: true` (page props auto-refresh on redirect back). Show validation errors from `usePage().props.errors` via `sonner` toast (already installed; add `<Toaster />` once in `index.tsx`).

- [ ] **Step 9.1: Assign dialog**

`resources/js/pages/shtab/components/assign-dialog.tsx`:

```tsx
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Board } from '../types';

export interface AssignIntent {
    objectId: number;
    personId: number | null; // null → показать пикер людей
    moveAssignmentId: number | null; // задан → это перенос с другой территории
}

interface Props {
    intent: AssignIntent | null;
    board: Board;
    onClose: () => void;
}

export default function AssignDialog({ intent, board, onClose }: Props) {
    const [personId, setPersonId] = useState<number | null>(null);
    const [roleLabel, setRoleLabel] = useState('');
    const [comment, setComment] = useState('');

    if (!intent) return null;

    const object = board.objects.find((o) => o.id === intent.objectId);
    const chosenPersonId = intent.personId ?? personId;
    const alreadyThere = new Set(object?.assignments.map((a) => a.person_id));
    const candidates = board.people.filter((p) => !alreadyThere.has(p.id));

    const submit = () => {
        if (!chosenPersonId || !roleLabel.trim()) return;
        const payload = { role_label: roleLabel.trim(), comment: comment.trim() || null, object_id: intent.objectId };
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (intent.moveAssignmentId) {
            router.post(`/shtab/assignments/${intent.moveAssignmentId}/move`, payload, opts);
        } else {
            router.post('/shtab/assignments', { ...payload, person_id: chosenPersonId }, opts);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>
                        {intent.moveAssignmentId ? 'Перенос на' : 'Назначение на'} {object?.emoji} {object?.name}
                    </DialogTitle>
                </DialogHeader>
                {intent.personId === null && (
                    <div className="flex flex-wrap gap-2">
                        {candidates.map((p) => (
                            <button
                                key={p.id}
                                type="button"
                                onClick={() => setPersonId(p.id)}
                                className={`cursor-pointer rounded-full border px-2 py-1 text-xs font-bold ${personId === p.id ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-300'}`}
                            >
                                {p.name}
                            </button>
                        ))}
                    </div>
                )}
                <div className="space-y-3">
                    <div>
                        <Label htmlFor="role_label">Роль на территории</Label>
                        <Input id="role_label" value={roleLabel} onChange={(e) => setRoleLabel(e.target.value)} placeholder="владелец / аналитика / ведёт…" />
                    </div>
                    <div>
                        <Label htmlFor="assign_comment">Почему (для Хроники)</Label>
                        <Input id="assign_comment" value={comment} onChange={(e) => setComment(e.target.value)} placeholder="на месяц, до релиза" />
                    </div>
                    <Button onClick={submit} disabled={!chosenPersonId || !roleLabel.trim()} className="w-full">
                        {intent.moveAssignmentId ? 'Перенести' : 'Назначить'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
```

- [ ] **Step 9.2: Existing-assignment dialog (details + снять)**

`resources/js/pages/shtab/components/assignment-dialog.tsx`:

```tsx
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Board } from '../types';

interface Props {
    assignmentId: number | null;
    board: Board;
    onClose: () => void;
}

export default function AssignmentDialog({ assignmentId, board, onClose }: Props) {
    const [comment, setComment] = useState('');

    if (!assignmentId) return null;

    const object = board.objects.find((o) => o.assignments.some((a) => a.id === assignmentId));
    const assignment = object?.assignments.find((a) => a.id === assignmentId);
    if (!object || !assignment) return null;

    const end = () => {
        router.post(
            `/shtab/assignments/${assignment.id}/end`,
            { comment: comment.trim() || null },
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>
                        {assignment.person_name} на {object.emoji} {object.name}
                    </DialogTitle>
                </DialogHeader>
                <p className="text-sm text-gray-600">
                    {assignment.role_label} · с {assignment.started_at} · {assignment.days} дн
                </p>
                <div className="space-y-3">
                    <div>
                        <Label htmlFor="end_comment">Комментарий к снятию</Label>
                        <Input id="end_comment" value={comment} onChange={(e) => setComment(e.target.value)} placeholder="релиз вышел / передал Диме…" />
                    </div>
                    <Button variant="destructive" onClick={end} className="w-full">
                        Снять с территории
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
```

- [ ] **Step 9.3: Metric dialog**

`resources/js/pages/shtab/components/metric-dialog.tsx`:

```tsx
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Board, MetricStatus } from '../types';

interface Props {
    metricId: number | null;
    board: Board;
    onClose: () => void;
}

const STATUSES: { value: MetricStatus; label: string; cls: string }[] = [
    { value: 'green', label: '🟢 в норме', cls: 'border-green-500' },
    { value: 'yellow', label: '🟡 внимание', cls: 'border-yellow-500' },
    { value: 'red', label: '🔴 проблема', cls: 'border-red-500' },
];

export default function MetricDialog({ metricId, board, onClose }: Props) {
    const metric =
        board.business_metrics.find((m) => m.id === metricId) ??
        board.objects.flatMap((o) => o.metrics).find((m) => m.id === metricId);

    const [status, setStatus] = useState<MetricStatus>(metric?.status ?? 'green');
    const [valueText, setValueText] = useState(metric?.value_text ?? '');
    const [comment, setComment] = useState('');

    if (!metricId || !metric) return null;

    const submit = () => {
        router.patch(
            `/shtab/metrics/${metric.id}/status`,
            { status, value_text: valueText.trim() || null, comment: comment.trim() || null },
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>Метрика: {metric.name}</DialogTitle>
                </DialogHeader>
                <div className="space-y-3">
                    <div className="flex gap-2">
                        {STATUSES.map((s) => (
                            <button
                                key={s.value}
                                type="button"
                                onClick={() => setStatus(s.value)}
                                className={`cursor-pointer rounded-lg border-2 px-2 py-1 text-xs font-bold ${status === s.value ? s.cls : 'border-transparent bg-gray-100'}`}
                            >
                                {s.label}
                            </button>
                        ))}
                    </div>
                    <div>
                        <Label htmlFor="value_text">Значение (текстом)</Label>
                        <Input id="value_text" value={valueText} onChange={(e) => setValueText(e.target.value)} placeholder="12% / 34K MAU" />
                    </div>
                    <div>
                        <Label htmlFor="metric_comment">Комментарий</Label>
                        <Input id="metric_comment" value={comment} onChange={(e) => setComment(e.target.value)} />
                    </div>
                    <Button onClick={submit} className="w-full">Сохранить</Button>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            className="flex-1"
                            onClick={() => {
                                const name = window.prompt('Новое название метрики', metric.name);
                                if (name?.trim()) {
                                    router.put(`/shtab/metrics/${metric.id}`, { name: name.trim(), object_id: board.objects.find((o) => o.metrics.some((m) => m.id === metric.id))?.id ?? null }, { preserveScroll: true });
                                }
                            }}
                        >
                            Переименовать
                        </Button>
                        <Button
                            variant="destructive"
                            className="flex-1"
                            onClick={() => {
                                if (window.confirm(`Удалить метрику «${metric.name}»?`)) {
                                    router.delete(`/shtab/metrics/${metric.id}`, { preserveScroll: true, onSuccess: onClose });
                                }
                            }}
                        >
                            Удалить
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
```

- [ ] **Step 9.4: Person form dialog (create/edit/archive)**

`resources/js/pages/shtab/components/person-form-dialog.tsx`:

```tsx
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Board, BoardPerson } from '../types';

const COLORS = ['#10B981', '#8B5CF6', '#F59E0B', '#EC4899', '#3B82F6', '#14B8A6', '#EF4444', '#64748B'];

interface Props {
    open: boolean;
    person: BoardPerson | null; // null → создание
    board: Board;
    onClose: () => void;
}

export default function PersonFormDialog({ open, person, board, onClose }: Props) {
    const [name, setName] = useState(person?.name ?? '');
    const [initials, setInitials] = useState(person?.initials ?? '');
    const [klass, setKlass] = useState(person?.class ?? '');
    const [color, setColor] = useState(person?.color ?? COLORS[0]);
    const [isDirect, setIsDirect] = useState(person?.is_direct ?? true);
    const [managerId, setManagerId] = useState<number | null>(person?.manager_id ?? null);

    if (!open) return null;

    const submit = () => {
        const payload = {
            name: name.trim(),
            initials: initials.trim() || name.trim().slice(0, 2).toUpperCase(),
            class: klass.trim() || 'Боец',
            color,
            is_direct: isDirect,
            manager_id: managerId,
            is_me: person?.is_me ?? false,
        };
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (person) router.put(`/shtab/people/${person.id}`, payload, opts);
        else router.post('/shtab/people', payload, opts);
    };

    const archive = () => {
        if (person) router.post(`/shtab/people/${person.id}/archive`, {}, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{person ? `Персонаж: ${person.name}` : 'Новый персонаж'}</DialogTitle>
                </DialogHeader>
                <div className="space-y-3">
                    <div>
                        <Label htmlFor="p_name">Имя</Label>
                        <Input id="p_name" value={name} onChange={(e) => setName(e.target.value)} />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <Label htmlFor="p_initials">Инициалы</Label>
                            <Input id="p_initials" value={initials} onChange={(e) => setInitials(e.target.value)} maxLength={8} />
                        </div>
                        <div>
                            <Label htmlFor="p_class">Класс</Label>
                            <Input id="p_class" value={klass} onChange={(e) => setKlass(e.target.value)} placeholder="Аналитик" />
                        </div>
                    </div>
                    <div className="flex gap-1.5">
                        {COLORS.map((c) => (
                            <button
                                key={c}
                                type="button"
                                onClick={() => setColor(c)}
                                className={`h-6 w-6 cursor-pointer rounded-full ${color === c ? 'ring-2 ring-gray-900 ring-offset-1' : ''}`}
                                style={{ backgroundColor: c }}
                            />
                        ))}
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={isDirect} onChange={(e) => setIsDirect(e.target.checked)} />
                        Прямой подчинённый
                    </label>
                    <div>
                        <Label htmlFor="p_manager">Руководитель</Label>
                        <select
                            id="p_manager"
                            className="w-full rounded-md border border-gray-300 p-2 text-sm"
                            value={managerId ?? ''}
                            onChange={(e) => setManagerId(e.target.value ? Number(e.target.value) : null)}
                        >
                            <option value="">—</option>
                            {board.people
                                .filter((p) => p.id !== person?.id)
                                .map((p) => (
                                    <option key={p.id} value={p.id}>{p.name}</option>
                                ))}
                        </select>
                    </div>
                    <Button onClick={submit} disabled={!name.trim()} className="w-full">Сохранить</Button>
                    {person && (
                        <Button variant="outline" onClick={archive} className="w-full">В архив (если без назначений)</Button>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
```

- [ ] **Step 9.5: Object form dialog**

`resources/js/pages/shtab/components/object-form-dialog.tsx` — same shape as person form:

```tsx
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Board, BoardObject } from '../types';

const COLORS = ['#5B6EE8', '#0EA5E9', '#14B8A6', '#F59E0B', '#EC4899', '#64748B'];

interface Props {
    open: boolean;
    object: BoardObject | null; // null → создание
    board: Board;
    onClose: () => void;
}

export default function ObjectFormDialog({ open, object, board, onClose }: Props) {
    const [type, setType] = useState<BoardObject['type']>(object?.type ?? 'product');
    const [name, setName] = useState(object?.name ?? '');
    const [emoji, setEmoji] = useState(object?.emoji ?? '🏰');
    const [focusLevel, setFocusLevel] = useState<0 | 1 | 2>(object?.focus_level ?? 0);
    const [color, setColor] = useState(object?.color ?? COLORS[0]);
    const [parentId, setParentId] = useState<number | null>(object?.parent_id ?? null);

    if (!open) return null;

    const submit = () => {
        const payload = { type, name: name.trim(), emoji: emoji.trim() || null, focus_level: focusLevel, color, parent_id: parentId };
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (object) router.put(`/shtab/objects/${object.id}`, payload, opts);
        else router.post('/shtab/objects', payload, opts);
    };

    const archive = () => {
        if (object) router.post(`/shtab/objects/${object.id}/archive`, {}, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{object ? `Территория: ${object.name}` : 'Новая территория'}</DialogTitle>
                </DialogHeader>
                <div className="space-y-3">
                    <div className="flex gap-2">
                        {(
                            [
                                ['product', 'Продукт'],
                                ['project', 'Проект'],
                                ['enabler', 'Энейблер'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setType(value)}
                                className={`cursor-pointer rounded-lg border px-2 py-1 text-xs font-bold ${type === value ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-300'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="grid grid-cols-[1fr_70px] gap-3">
                        <div>
                            <Label htmlFor="o_name">Название</Label>
                            <Input id="o_name" value={name} onChange={(e) => setName(e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor="o_emoji">Эмодзи</Label>
                            <Input id="o_emoji" value={emoji} onChange={(e) => setEmoji(e.target.value)} maxLength={4} />
                        </div>
                    </div>
                    <div>
                        <Label>Твой фокус</Label>
                        <div className="flex gap-2">
                            {(
                                [
                                    [0, 'фоновый'],
                                    [1, '🔥'],
                                    [2, '🔥🔥'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => setFocusLevel(value)}
                                    className={`cursor-pointer rounded-lg border px-3 py-1 text-xs font-bold ${focusLevel === value ? 'border-orange-500 bg-orange-50' : 'border-gray-300'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>
                    {type !== 'product' && (
                        <div>
                            <Label htmlFor="o_parent">Часть продукта</Label>
                            <select
                                id="o_parent"
                                className="w-full rounded-md border border-gray-300 p-2 text-sm"
                                value={parentId ?? ''}
                                onChange={(e) => setParentId(e.target.value ? Number(e.target.value) : null)}
                            >
                                <option value="">— самостоятельный</option>
                                {board.objects
                                    .filter((o) => o.type === 'product' && o.id !== object?.id)
                                    .map((o) => (
                                        <option key={o.id} value={o.id}>{o.emoji} {o.name}</option>
                                    ))}
                            </select>
                        </div>
                    )}
                    <div className="flex gap-1.5">
                        {COLORS.map((c) => (
                            <button
                                key={c}
                                type="button"
                                onClick={() => setColor(c)}
                                className={`h-6 w-6 cursor-pointer rounded-full ${color === c ? 'ring-2 ring-gray-900 ring-offset-1' : ''}`}
                                style={{ backgroundColor: c }}
                            />
                        ))}
                    </div>
                    <Button onClick={submit} disabled={!name.trim()} className="w-full">Сохранить</Button>
                    {object && (
                        <Button variant="outline" onClick={archive} className="w-full">В архив (если пуста)</Button>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
```

Note: metric CRUD (add/rename/delete metrics per object) lives inside `ObjectFormDialog` in a later iteration; v1 creates metrics via a small "+" in the sector header metric row using `router.post('/shtab/metrics', {object_id, name})` with `window.prompt('Название метрики')`. Add that button in Step 9.6.

- [ ] **Step 9.6: Wire everything in `index.tsx`**

Update `resources/js/pages/shtab/index.tsx`: replace the `noop` handlers with state-driven dialogs:

```tsx
// новые импорты
import { Toaster, toast } from 'sonner';
import { usePage } from '@inertiajs/react';
import AssignDialog, { type AssignIntent } from './components/assign-dialog';
import AssignmentDialog from './components/assignment-dialog';
import MetricDialog from './components/metric-dialog';
import PersonFormDialog from './components/person-form-dialog';
import ObjectFormDialog from './components/object-form-dialog';

// внутри компонента:
const { errors } = usePage().props;
const [assignIntent, setAssignIntent] = useState<AssignIntent | null>(null);
const [openAssignmentId, setOpenAssignmentId] = useState<number | null>(null);
const [openMetricId, setOpenMetricId] = useState<number | null>(null);
const [personForm, setPersonForm] = useState<{ open: boolean; person: BoardPerson | null }>({ open: false, person: null });
const [objectForm, setObjectForm] = useState<{ open: boolean; object: BoardObject | null }>({ open: false, object: null });

useEffect(() => {
    const first = Object.values(errors ?? {})[0];
    if (first) toast.error(String(first));
}, [errors]);
```

Handlers passed to `SectorCard`:

```tsx
onAssignClick={(objectId) => setAssignIntent({ objectId, personId: null, moveAssignmentId: null })}
onPersonDrop={(personId, assignmentId, objectId) =>
    setAssignIntent({ objectId, personId, moveAssignmentId: assignmentId })}
onPersonClick={(assignmentId) => setOpenAssignmentId(assignmentId)}
onMetricClick={(metricId) => setOpenMetricId(metricId)}
onEditClick={(objectId) => setObjectForm({ open: true, object: board.objects.find((o) => o.id === objectId) ?? null })}
```

Header additions (right side, before reserve): two small buttons «+ персонаж» → `setPersonForm({ open: true, person: null })` and «+ территория» → `setObjectForm({ open: true, object: null })`. Render all five dialogs + `<Toaster position="bottom-right" />` at the bottom of the component. Dialogs get `key` props (`person?.id`, `object?.id`, `metricId`, etc.) so `useState` initializers reset between openings:

```tsx
<AssignDialog key={`${assignIntent?.objectId}-${assignIntent?.personId}`} intent={assignIntent} board={board} onClose={() => setAssignIntent(null)} />
<AssignmentDialog key={openAssignmentId ?? 'a'} assignmentId={openAssignmentId} board={board} onClose={() => setOpenAssignmentId(null)} />
<MetricDialog key={openMetricId ?? 'm'} metricId={openMetricId} board={board} onClose={() => setOpenMetricId(null)} />
<PersonFormDialog key={personForm.person?.id ?? 'new-p'} open={personForm.open} person={personForm.person} board={board} onClose={() => setPersonForm({ open: false, person: null })} />
<ObjectFormDialog key={objectForm.object?.id ?? 'new-o'} open={objectForm.open} object={objectForm.object} board={board} onClose={() => setObjectForm({ open: false, object: null })} />
```

Also add the metric "+" button in `SectorCard` header metric row:

```tsx
<button
    type="button"
    onClick={() => {
        const name = window.prompt('Название метрики');
        if (name?.trim()) router.post('/shtab/metrics', { object_id: object.id, name: name.trim() }, { preserveScroll: true });
    }}
    className="h-3 w-3 cursor-pointer rounded-full border border-dashed border-gray-400 text-[8px] leading-none text-gray-400"
    title="Добавить метрику"
>+</button>
```

(`import { router } from '@inertiajs/react';` in `sector-card.tsx`.)

- [ ] **Step 9.7: Build + manual smoke + commit**

```bash
npm run build
php artisan config:clear && php artisan test --compact --filter=Shtab
```
Manual smoke on `https://gleb.finance/shtab`: create person → create product → drag person onto sector → dialog → assign → chronicle entry appears; drag chip to another sector → move; metric dot → status change; archive guard toast shows when archiving busy person.

```bash
git add -A && git commit -m "feat(shtab): interactive assignments, dialogs, native dnd"
```

---

## Task 10: Люди tab + Хроника filters

**Files:**
- Create: `resources/js/pages/shtab/components/people-tab.tsx`
- Create: `resources/js/pages/shtab/components/chronicle-tab.tsx`
- Modify: `resources/js/pages/shtab/index.tsx` (use the new tab components)

- [ ] **Step 10.1: People tab with full character cards**

`resources/js/pages/shtab/components/people-tab.tsx`:

```tsx
import type { Board, BoardPerson, ChronicleEvent } from '../types';
import ChroniclePanel from './chronicle-panel';

interface Props {
    board: Board;
    events: ChronicleEvent[];
    onPersonEdit: (person: BoardPerson) => void;
    selectedPersonId: number | null;
    onSelectPerson: (id: number | null) => void;
}

function PersonCardLarge({ person, onEdit, onSelect, selected }: {
    person: BoardPerson;
    onEdit: () => void;
    onSelect: () => void;
    selected: boolean;
}) {
    return (
        <div
            className={`w-[210px] cursor-pointer rounded-xl border bg-white p-4 shadow-sm transition hover:shadow-md ${selected ? 'border-gray-900' : 'border-[#DFE4EC]'} ${person.in_reserve ? 'border-dashed border-amber-500' : ''}`}
            onClick={onSelect}
        >
            <div className="flex flex-col items-center">
                <span
                    className="flex h-12 w-12 items-center justify-center rounded-full text-sm font-extrabold text-white"
                    style={{ backgroundColor: person.color }}
                >
                    {person.initials}
                </span>
                <span className="mt-2 text-sm font-bold text-gray-900">
                    {person.name} {person.is_me && <span className="text-[10px] text-emerald-600">· ты</span>}
                </span>
                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[9px] font-bold tracking-wide text-gray-600 uppercase">
                    {person.class} {person.is_direct ? '' : '· непрямой'}
                </span>
            </div>
            <div className="mt-3 grid grid-cols-2 gap-1.5 text-center">
                <div className="rounded-lg bg-gray-50 py-1.5">
                    <div className="text-sm font-extrabold text-gray-900">{person.focus_count}</div>
                    <div className="text-[8px] font-bold text-gray-400 uppercase">Фокусы</div>
                </div>
                <div className={`rounded-lg py-1.5 ${person.is_overloaded ? 'bg-red-50' : 'bg-orange-50'}`}>
                    <div className="text-sm font-extrabold text-gray-900">🔥{person.hot_count}</div>
                    <div className="text-[8px] font-bold text-gray-400 uppercase">Ключевых</div>
                </div>
            </div>
            <div className="mt-2 space-y-1">
                {person.assignments.map((a) => (
                    <div key={a.id} className="rounded-md bg-gray-50 px-2 py-1 text-[10px] text-gray-700">
                        {a.object_emoji} <b>{a.object_name}</b> — {a.role_label} · {a.days} дн
                    </div>
                ))}
                {person.in_reserve && <div className="text-center text-[10px] font-bold text-amber-600">без фокуса!</div>}
            </div>
            <button
                type="button"
                onClick={(e) => {
                    e.stopPropagation();
                    onEdit();
                }}
                className="mt-2 w-full cursor-pointer rounded-md border border-gray-200 py-1 text-[10px] text-gray-500 hover:bg-gray-50"
            >
                Редактировать
            </button>
        </div>
    );
}

export default function PeopleTab({ board, events, onPersonEdit, selectedPersonId, onSelectPerson }: Props) {
    const direct = board.people.filter((p) => p.is_direct);
    const indirect = board.people.filter((p) => !p.is_direct);
    const personEvents = selectedPersonId ? events.filter((e) => e.person?.id === selectedPersonId) : [];

    const grid = (people: BoardPerson[]) => (
        <div className="flex flex-wrap gap-3">
            {people.map((p) => (
                <PersonCardLarge
                    key={p.id}
                    person={p}
                    selected={p.id === selectedPersonId}
                    onSelect={() => onSelectPerson(p.id === selectedPersonId ? null : p.id)}
                    onEdit={() => onPersonEdit(p)}
                />
            ))}
        </div>
    );

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_300px]">
            <div className="space-y-5">
                <div>
                    <h2 className="mb-2 text-xs font-extrabold text-gray-500 uppercase">Прямые</h2>
                    {grid(direct)}
                </div>
                {indirect.length > 0 && (
                    <div>
                        <h2 className="mb-2 text-xs font-extrabold text-gray-500 uppercase">Непрямые</h2>
                        {grid(indirect)}
                    </div>
                )}
            </div>
            <aside className="rounded-xl border border-[#E4E1D8] bg-white p-4">
                <h2 className="mb-3 text-xs font-extrabold text-gray-900">
                    {selectedPersonId ? 'ЛИЧНАЯ ХРОНИКА' : 'ХРОНИКА — выбери персонажа'}
                </h2>
                <ChroniclePanel events={selectedPersonId ? personEvents : events} limit={15} />
            </aside>
        </div>
    );
}
```

- [ ] **Step 10.2: Chronicle tab with filters**

`resources/js/pages/shtab/components/chronicle-tab.tsx`:

```tsx
import { useState } from 'react';
import type { Board, ChronicleEvent } from '../types';
import ChroniclePanel from './chronicle-panel';

type Filter = 'all' | 'assignments' | 'metrics';

export default function ChronicleTab({ board, events }: { board: Board; events: ChronicleEvent[] }) {
    const [filter, setFilter] = useState<Filter>('all');
    const [personId, setPersonId] = useState<number | null>(null);
    const [objectId, setObjectId] = useState<number | null>(null);

    const filtered = events.filter((e) => {
        if (filter === 'assignments' && !e.type.startsWith('assignment_')) return false;
        if (filter === 'metrics' && e.type !== 'metric_status_changed') return false;
        if (personId && e.person?.id !== personId) return false;
        if (objectId && e.object?.id !== objectId) return false;
        return true;
    });

    return (
        <div className="mx-auto max-w-2xl">
            <div className="mb-3 flex flex-wrap items-center gap-2">
                {(
                    [
                        ['all', 'все'],
                        ['assignments', 'назначения'],
                        ['metrics', 'метрики'],
                    ] as const
                ).map(([value, label]) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => setFilter(value)}
                        className={`cursor-pointer rounded-full px-3 py-1 text-xs font-bold ${filter === value ? 'bg-gray-900 text-white' : 'bg-white text-gray-600'}`}
                    >
                        {label}
                    </button>
                ))}
                <select
                    className="rounded-full border border-gray-300 bg-white px-2 py-1 text-xs"
                    value={personId ?? ''}
                    onChange={(e) => setPersonId(e.target.value ? Number(e.target.value) : null)}
                >
                    <option value="">любой персонаж</option>
                    {board.people.map((p) => (
                        <option key={p.id} value={p.id}>{p.name}</option>
                    ))}
                </select>
                <select
                    className="rounded-full border border-gray-300 bg-white px-2 py-1 text-xs"
                    value={objectId ?? ''}
                    onChange={(e) => setObjectId(e.target.value ? Number(e.target.value) : null)}
                >
                    <option value="">любая территория</option>
                    {board.objects.map((o) => (
                        <option key={o.id} value={o.id}>{o.emoji} {o.name}</option>
                    ))}
                </select>
            </div>
            <div className="rounded-xl border border-[#E4E1D8] bg-white p-5">
                <ChroniclePanel events={filtered} />
            </div>
        </div>
    );
}
```

- [ ] **Step 10.3: Use both tabs in `index.tsx`**

Replace the `people` and `chronicle` tab bodies:

```tsx
{tab === 'people' && (
    <main className="p-5">
        <PeopleTab
            board={board}
            events={events}
            onPersonEdit={(person) => setPersonForm({ open: true, person })}
            selectedPersonId={selectedPersonId}
            onSelectPerson={setSelectedPersonId}
        />
    </main>
)}

{tab === 'chronicle' && (
    <main className="p-5">
        <ChronicleTab board={board} events={events} />
    </main>
)}
```

with `const [selectedPersonId, setSelectedPersonId] = useState<number | null>(null);` and imports for `PeopleTab` / `ChronicleTab`. Remove the now-unused `PersonChip` import if nothing else uses it in `index.tsx`.

- [ ] **Step 10.4: Build + commit**

```bash
npm run build
php artisan config:clear && php artisan test --compact --filter=Shtab
git add -A && git commit -m "feat(shtab): people tab with character cards, chronicle filters"
```

---

## Task 11: PROJECT_MAP, full suite, final smoke

**Files:**
- Modify: `docs/PROJECT_MAP.md` (new project section + data model note)

- [ ] **Step 11.1: Add Shtab section to PROJECT_MAP.md**

Append under **Projects** (numbering follows existing sections):

```markdown
### 6. Штаб — management cockpit (`/shtab`)
Личный командный пункт Глеба: карта «кто чем занят», назначения с историей, Хроника решений. Single-user (admin gate).
- **URLs:** `/shtab` (Inertia SPA: табы Карта / Люди / Хроника); мутации `POST/PUT/PATCH/DELETE /shtab/{assignments,people,objects,metrics}/…` — все с redirect back.
- **Controllers:** `app/Http/Controllers/Shtab/{ShtabController,AssignmentsController,PeopleController,ObjectsController,MetricsController}.php`.
- **Board assembly:** `App\Actions\Shtab\BuildShtabBoard` (reserve / overload / uncovered-days flags). Порог перегруза: `config/shtab.php`.
- **Models / tables:** `ShtabPerson`→`shtab_people`, `ShtabObject`→`shtab_objects` (products/projects/enablers, `focus_level` 0-2), `ShtabAssignment`→`shtab_assignments` (история через `ended_at`), `ShtabMetric`→`shtab_metrics`, `ShtabEvent`→`shtab_events` (Хроника; пишется транзакционно при каждой мутации).
- **Frontend: Inertia + React** — `resources/js/pages/shtab/` (index + components + types).
- **Spec:** `docs/specs/2026-08-08-shtab-management-cockpit-design.md` (+ мокап в `docs/specs/assets/`).
```

- [ ] **Step 11.2: Full verification**

```bash
cd /home/gleb/gleb.finance
php artisan config:clear
php artisan test --compact
vendor/bin/pint --format agent
npm run build
```
Expected: entire suite green (not only Shtab tests), pint clean, build OK.

- [ ] **Step 11.3: Manual smoke as Gleb**

On `https://gleb.finance/shtab`: full walkthrough — add self as person (`is_me` via edit), add 2 products + 1 project, assign self, check Хроника entries, check Люди tab, check reserve, phone viewport check (tap «+» assign path works without drag).

- [ ] **Step 11.4: Final commit**

```bash
git add -A && git commit -m "docs(shtab): register project in PROJECT_MAP"
```

---

## Deviations from spec (approved during planning)

1. **Inertia instead of REST API + refetch** — repo is Inertia v3; mutations are POSTs with `redirect()->back()`, props auto-refresh. Same UX, idiomatic stack.
2. **403 for non-admins** (repo `admin` middleware convention) instead of spec's 404.
3. **No dnd library** — native HTML5 drag-and-drop (repo has no dnd dep; AGENTS.md forbids adding deps without approval). Touch devices use the «+» picker path.
4. **Events limit 200** in props (client-side filtering) — at this scale pagination is YAGNI; revisit if the chronicle outgrows it.
```
