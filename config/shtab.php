<?php

return [
    // Больше скольких активных 🔥-назначений человек считается перегруженным.
    'overload_threshold' => (int) env('SHTAB_OVERLOAD_THRESHOLD', 2),

    // Типы участия в территории и вовлечённость по умолчанию (% рабочего времени).
    'roles' => [
        'owner' => ['label' => 'Владелец', 'short' => 'влад', 'default_load' => 50],
        'lead' => ['label' => 'Ведёт', 'short' => 'ведёт', 'default_load' => 40],
        'helper' => ['label' => 'Помогает', 'short' => 'помог', 'default_load' => 25],
        'watcher' => ['label' => 'Следит', 'short' => 'следит', 'default_load' => 10],
    ],

    // 100% = полная занятость человека. Выше — перегруз.
    'capacity_percent' => (int) env('SHTAB_CAPACITY_PERCENT', 100),

    // ИИ-разбор («Рассказать штабу»): свой ключ Anthropic, с откатом на общий ключ Nutrition.
    'anthropic_key' => env('SHTAB_ANTHROPIC_API_KEY') ?: env('ANTHROPIC_API_KEY'),
    'ai_model' => 'claude-opus-5',
];
