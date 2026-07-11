<?php

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
