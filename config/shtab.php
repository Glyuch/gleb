<?php

return [
    // Больше скольких активных 🔥-назначений человек считается перегруженным.
    'overload_threshold' => (int) env('SHTAB_OVERLOAD_THRESHOLD', 2),

    // ИИ-разбор («Рассказать штабу»): тот же ключ Anthropic, что и у Nutrition.
    'anthropic_key' => env('ANTHROPIC_API_KEY'),
    'ai_model' => 'claude-opus-5',
];
