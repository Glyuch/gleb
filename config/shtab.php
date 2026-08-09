<?php

return [
    // Больше скольких активных 🔥-назначений человек считается перегруженным.
    'overload_threshold' => (int) env('SHTAB_OVERLOAD_THRESHOLD', 2),

    // ИИ-разбор («Рассказать штабу»): свой ключ Anthropic, с откатом на общий ключ Nutrition.
    'anthropic_key' => env('SHTAB_ANTHROPIC_API_KEY') ?: env('ANTHROPIC_API_KEY'),
    'ai_model' => 'claude-opus-5',
];
