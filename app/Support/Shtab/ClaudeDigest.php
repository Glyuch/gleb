<?php

namespace App\Support\Shtab;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ClaudeDigest
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const SYSTEM_PROMPT = <<<'PROMPT'
Ты — ассистент управленческого штаба Глеба (gleb.finance). Глеб надиктовывает свободный текст о происходящем в командах, а ты раскладываешь его в конкретные операции над штабом через инструмент propose_operations.

Модель данных штаба:
- Территории (objects): продукты, проекты и обвязка (enablers). У территории есть focus_level (0 — фоновая, 1 — 🔥, 2 — 🔥🔥), описание, метрики и задачи.
- Персонажи (people): члены команды. Назначение (assignment) связывает персонажа с территорией: role_type — тип участия (owner — владелец, lead — ведёт, helper — помогает, watcher — следит), load_percent — вовлечённость в процентах рабочего времени (полная занятость = 100). Активное назначение — без даты окончания.
- Метрики (metrics): статус green | yellow | red и текстовое значение value_text. Метрики без object_id относятся к бизнесу в целом.
- Задачи (tasks): чек-лист территории; у задачи может быть исполнитель и флаг ключевой (одна ключевая на территорию).

Типы операций и их обязательные поля (сверх type и summary):
- assign — назначить персонажа на территорию: person_id, object_id, role_type (owner | lead | helper | watcher), role_label (роль по-русски: «владелец», «аналитика», …); load_percent добавляй, если из текста ясна доля занятости («на полдня», «целиком на проекте»).
- end_assignment — снять персонажа с территории: assignment_id (id активного назначения из состояния).
- move_assignment — перевести персонажа на другую территорию: assignment_id (текущее назначение), object_id (куда), role_type, role_label; load_percent — по желанию.
- metric_status — сменить статус метрики: metric_id, status; value_text добавляй, если названо новое значение.
- focus_level — сменить уровень огня территории: object_id, focus_level (0–2).
- update_description — дополнить описание территории: object_id, description_append (краткая выжимка нового факта).
- task_add — новая задача: object_id, title; person_id добавляй, если назван исполнитель.
- task_done — задача выполнена: task_id.
- task_assign — назначить исполнителя существующей задаче: task_id, person_id.
- task_key — пометить задачу ключевой: task_id.

Правила:
- Ссылайся ТОЛЬКО на id из переданного состояния штаба. Никогда не выдумывай id.
- Если не уверен, о какой сущности речь, или намерение неоднозначно — клади фрагмент текста в unparsed с причиной («нет сущности для…», «неоднозначно…»), а НЕ придумывай операцию.
- summary — короткая человекочитаемая формулировка операции по-русски: она попадёт в превью и в Хронику.
- comment заполняй, когда в тексте есть причина или контекст решения.
- Один факт из рассказа — одна операция, без дублей.
- Всё, что к штабу не относится, тоже отправляй в unparsed с причиной.
PROMPT;

    /**
     * Разбирает свободный текст Глеба в операции над штабом: один не-стриминговый
     * запрос к Anthropic Messages API с forced tool-use. Любая ошибка (HTTP, refusal,
     * ответ без tool_use) → RuntimeException: применять нечего, фронт покажет тост.
     *
     * @param  array<string, mixed>  $context  полный стейт доски (BuildShtabBoard + задачи)
     * @return array{operations: array<int, array<string, mixed>>, unparsed: array<int, array<string, mixed>>}
     */
    public static function propose(string $text, array $context): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('shtab.anthropic_key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout(180)
                ->post(self::ENDPOINT, [
                    'model' => (string) config('shtab.ai_model'),
                    'max_tokens' => 16000,
                    // Forced tool_choice несовместим с thinking; на claude-opus-5 thinking включён
                    // по умолчанию, поэтому выключаем явно (допустимо при effort high и ниже).
                    'thinking' => ['type' => 'disabled'],
                    'system' => self::SYSTEM_PROMPT,
                    'tools' => [self::toolDefinition()],
                    'tool_choice' => ['type' => 'tool', 'name' => 'propose_operations'],
                    'messages' => [[
                        'role' => 'user',
                        'content' => "Состояние штаба (JSON):\n"
                            .json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                            ."\n\nРассказ руководителя:\n".$text,
                    ]],
                ]);
        } catch (Throwable $e) {
            Log::warning('Shtab ClaudeDigest: исключение при запросе.', ['message' => $e->getMessage()]);

            throw new RuntimeException('ИИ недоступен, попробуй позже.', 0, $e);
        }

        if (! $response->successful()) {
            Log::warning('Shtab ClaudeDigest: запрос неуспешен.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('ИИ недоступен, попробуй позже.');
        }

        if ($response->json('stop_reason') === 'refusal') {
            Log::warning('Shtab ClaudeDigest: модель отказалась разбирать текст.');

            throw new RuntimeException('ИИ отказался разбирать этот текст.');
        }

        $blocks = $response->json('content');

        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && is_array($block['input'] ?? null)) {
                    /** @var array<string, mixed> $input */
                    $input = $block['input'];

                    return [
                        'operations' => self::listOfArrays($input['operations'] ?? null),
                        'unparsed' => self::listOfArrays($input['unparsed'] ?? null),
                    ];
                }
            }
        }

        Log::warning('Shtab ClaudeDigest: в ответе нет tool_use-блока.');

        throw new RuntimeException('ИИ вернул неожиданный ответ.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toolDefinition(): array
    {
        return [
            'name' => 'propose_operations',
            'description' => 'Разложи свободный текст руководителя в конкретные операции над штабом.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'operations' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => ['assign', 'end_assignment', 'move_assignment', 'metric_status', 'focus_level', 'update_description', 'task_add', 'task_done', 'task_assign', 'task_key']],
                                'summary' => ['type' => 'string', 'description' => 'Человекочитаемая формулировка операции по-русски'],
                                'person_id' => ['type' => 'integer'],
                                'object_id' => ['type' => 'integer'],
                                'assignment_id' => ['type' => 'integer'],
                                'task_id' => ['type' => 'integer'],
                                'metric_id' => ['type' => 'integer'],
                                'role_label' => ['type' => 'string'],
                                'role_type' => ['type' => 'string', 'enum' => ['owner', 'lead', 'helper', 'watcher']],
                                'load_percent' => ['type' => 'integer', 'description' => 'Вовлечённость в процентах рабочего времени, 0–100'],
                                'title' => ['type' => 'string'],
                                'status' => ['type' => 'string', 'enum' => ['green', 'yellow', 'red']],
                                'value_text' => ['type' => 'string'],
                                'focus_level' => ['type' => 'integer'],
                                'description_append' => ['type' => 'string'],
                                'comment' => ['type' => 'string'],
                            ],
                            'required' => ['type', 'summary'],
                        ],
                    ],
                    'unparsed' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => ['type' => 'string'],
                                'reason' => ['type' => 'string'],
                            ],
                            'required' => ['text', 'reason'],
                        ],
                    ],
                ],
                'required' => ['operations', 'unparsed'],
            ],
        ];
    }
}
