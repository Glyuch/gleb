<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $telegram_user_id
 * @property string $name
 * @property string|null $username
 * @property int|null $main_chat_id
 * @property bool $is_admin
 * @property string $status
 * @property string $phase
 * @property string $timezone
 * @property Carbon|null $program_started_on
 * @property string|null $ai_profile
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $awaiting
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NutritionProfile extends Model
{
    /** Дефолты профиля программы TriDaily (зеркало Settings::DEFAULTS до Task 2). */
    public const DEFAULT_SETTINGS = [
        'wake_time' => '07:00',
        'sleep_time' => '23:00',
        'default_windows' => [
            'breakfast' => ['start' => '07:30', 'end' => '08:30'],
            'lunch' => ['start' => '11:00', 'end' => '12:30'],
            'snack' => ['start' => '14:40', 'end' => '16:10'],
            'dinner' => ['start' => '19:00', 'end' => '20:00'],
        ],
        'steps_target' => 7000,
        'portion_adjustment' => 0,
    ];

    protected $fillable = [
        'telegram_user_id',
        'name',
        'username',
        'main_chat_id',
        'is_admin',
        'status',
        'phase',
        'timezone',
        'program_started_on',
        'ai_profile',
        'settings',
        'awaiting',
        'last_seen_at',
    ];

    protected $attributes = [
        'is_admin' => false,
        'status' => 'onboarding',
        'phase' => 'program',
        'timezone' => 'Europe/Moscow',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'main_chat_id' => 'integer',
            'is_admin' => 'boolean',
            'program_started_on' => 'date',
            'settings' => 'array',
            'awaiting' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Имя клиента для обращений в промптах; при пустом — «клиент». */
    public function displayName(): string
    {
        return filled($this->name) ? (string) $this->name : 'клиент';
    }

    /** Часовой пояс профиля (IANA или «+HH:MM»); при пустом — Europe/Moscow. */
    public function tz(): string
    {
        return filled($this->timezone) ? (string) $this->timezone : 'Europe/Moscow';
    }

    /** «Сейчас» в местном времени профиля. Единый источник времени profile-scoped расчётов. */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->tz());
    }

    /** Первый профиль-администратор (владелец инстанса). */
    public static function admin(): ?self
    {
        return static::query()->where('is_admin', true)->first();
    }

    public function invitesCreated(): HasMany
    {
        return $this->hasMany(NutritionInvite::class, 'created_by_profile_id');
    }

    public function topicSends(): HasMany
    {
        return $this->hasMany(NutritionTopicSend::class, 'profile_id');
    }

    public function meals(): HasMany
    {
        return $this->hasMany(NutritionMeal::class, 'profile_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(NutritionMetric::class, 'profile_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(NutritionMessage::class, 'profile_id');
    }

    /** Настройка профиля с откатом на DEFAULT_SETTINGS, затем на $default. */
    public function setting(string $key, mixed $default = null): mixed
    {
        $settings = $this->settings ?? [];

        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        return $default ?? (self::DEFAULT_SETTINGS[$key] ?? null);
    }

    public function setSetting(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->settings = $settings;
        $this->save();
    }

    /** Значение awaiting-флага (ожидание ввода) по ключу. */
    public function waiting(string $key): mixed
    {
        return ($this->awaiting ?? [])[$key] ?? null;
    }

    public function setWaiting(string $key, mixed $value): void
    {
        $awaiting = $this->awaiting ?? [];
        $awaiting[$key] = $value;
        $this->awaiting = $awaiting;
        $this->save();
    }

    public function clearWaiting(string $key): void
    {
        $awaiting = $this->awaiting ?? [];
        unset($awaiting[$key]);
        $this->awaiting = $awaiting ?: null;
        $this->save();
    }

    /**
     * Идемпотентный бэкфилл профиля владельца (Глеба) из legacy-состояния
     * (nutrition_settings + knowledge-файл) и привязка осиротевших строк.
     * Вызывается миграцией v2 и безопасен к повторному запуску.
     */
    public static function backfillFromLegacy(): void
    {
        $userId = config('nutrition.user_id');
        if ($userId === null || $userId === '') {
            return;
        }

        $userId = (int) $userId;
        if (static::query()->where('telegram_user_id', $userId)->exists()) {
            return;
        }

        $legacy = NutritionSetting::query()->pluck('value', 'key');

        $settings = [];
        foreach (['wake_time', 'sleep_time', 'default_windows', 'steps_target', 'portion_adjustment'] as $key) {
            $settings[$key] = $legacy[$key] ?? self::DEFAULT_SETTINGS[$key];
        }

        $awaiting = [];
        foreach ($legacy as $key => $value) {
            if (str_starts_with((string) $key, 'awaiting_')) {
                $awaiting[substr((string) $key, strlen('awaiting_'))] = $value;
            }
        }

        $profilePath = resource_path('nutrition/knowledge/04-profile.md');
        $aiProfile = is_file($profilePath) ? file_get_contents($profilePath) : null;

        $chatId = config('nutrition.chat_id');

        $profile = static::query()->create([
            'telegram_user_id' => $userId,
            'name' => 'Глеб',
            'main_chat_id' => ($chatId === null || $chatId === '') ? null : (int) $chatId,
            'is_admin' => true,
            'status' => 'active',
            'phase' => $legacy['phase'] ?? 'program',
            'program_started_on' => $legacy['program_started_on'] ?? null,
            'ai_profile' => $aiProfile,
            'settings' => $settings,
            'awaiting' => $awaiting ?: null,
        ]);

        NutritionMeal::query()->whereNull('profile_id')->update(['profile_id' => $profile->id]);
        NutritionMetric::query()->whereNull('profile_id')->update(['profile_id' => $profile->id]);
        NutritionMessage::query()->whereNull('profile_id')->update(['profile_id' => $profile->id]);
    }
}
