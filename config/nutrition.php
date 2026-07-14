<?php

return [
    'bot_token' => env('NUTRITION_BOT_TOKEN'),
    'user_id' => env('NUTRITION_USER_ID'),
    'chat_id' => env('NUTRITION_CHAT_ID'),
    'anthropic_key' => env('ANTHROPIC_API_KEY'),
    'webhook_secret' => env('NUTRITION_WEBHOOK_SECRET'),
    'timezone' => 'Europe/Moscow',
    'models' => [
        'vision' => 'claude-haiku-4-5',
        'fast' => 'claude-haiku-4-5',
        'chat' => 'claude-sonnet-5',
    ],
    'reminders' => [
        // Пре-напоминание: за сколько минут до окна.
        'lead_minutes' => 30,
        // Шаг наджей внутри окна и грейс-периода.
        'nudge_interval' => 30,
        // Через сколько минут после window_end приём уходит в missed
        // (единый источник для Planner::markMissed и жизни слотов напоминаний).
        'missed_after' => 90,
    ],
];
