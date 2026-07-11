<?php

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
