<?php

use App\Models\ShtabAssignment;
use App\Models\ShtabEvent;
use App\Models\ShtabMetric;
use App\Models\ShtabObject;
use App\Models\ShtabPerson;
use App\Models\ShtabTask;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function aiAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function aiToolResponse(array $input): array
{
    return [
        'id' => 'msg_test',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5',
        'stop_reason' => 'tool_use',
        'content' => [
            ['type' => 'text', 'text' => 'Разложил рассказ по операциям.'],
            ['type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'propose_operations', 'input' => $input],
        ],
    ];
}

it('digest returns operations and unparsed and sends model, tool_choice and text', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.anthropic.com/*' => Http::response(aiToolResponse([
            'operations' => [[
                'type' => 'assign',
                'summary' => 'Вика → KYC владельцем',
                'person_id' => 1,
                'object_id' => 2,
                'role_label' => 'владелец',
            ]],
            'unparsed' => [['text' => 'у Димы завал', 'reason' => 'непонятно, к какой территории относится']],
        ])),
    ]);

    $object = ShtabObject::factory()->create(['name' => 'Обмен']);

    $this->actingAs(aiAdmin())
        ->postJson('/shtab/ai/digest', ['text' => 'Вика уходит с Обмена на KYC'])
        ->assertOk()
        ->assertJsonPath('operations.0.type', 'assign')
        ->assertJsonPath('operations.0.summary', 'Вика → KYC владельцем')
        ->assertJsonPath('unparsed.0.text', 'у Димы завал');

    Http::assertSent(function (Request $request) use ($object): bool {
        $body = $request->data();
        $content = $body['messages'][0]['content'] ?? '';

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key')
            && $request->header('anthropic-version') === ['2023-06-01']
            && ($body['model'] ?? null) === 'claude-opus-5'
            && ($body['max_tokens'] ?? null) === 16000
            && ($body['thinking'] ?? null) === ['type' => 'disabled']
            && ($body['tool_choice'] ?? null) === ['type' => 'tool', 'name' => 'propose_operations']
            && ($body['tools'][0]['name'] ?? null) === 'propose_operations'
            && str_contains($content, 'Вика уходит с Обмена на KYC')
            && str_contains($content, $object->name);
    });
});

it('digest responds 503 when the API fails', function () {
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error'], 500)]);

    $this->actingAs(aiAdmin())
        ->postJson('/shtab/ai/digest', ['text' => 'маржа просела'])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

it('digest responds 503 when the model refuses', function () {
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_refusal',
        'type' => 'message',
        'role' => 'assistant',
        'stop_reason' => 'refusal',
        'content' => [],
    ])]);

    $this->actingAs(aiAdmin())
        ->postJson('/shtab/ai/digest', ['text' => 'что-то странное'])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

it('apply executes assign, task_add and metric_status with prefixed chronicle comments', function () {
    $person = ShtabPerson::factory()->create();
    $object = ShtabObject::factory()->create();
    $metric = ShtabMetric::factory()->create(['object_id' => $object->id, 'status' => 'green']);

    $this->actingAs(aiAdmin())
        ->postJson('/shtab/ai/apply', [
            'text' => 'Вика на KYC, маржа просела, добавить задачу',
            'operations' => [
                ['type' => 'assign', 'summary' => 'Назначить на территорию', 'person_id' => $person->id, 'object_id' => $object->id, 'role_label' => 'владелец'],
                ['type' => 'task_add', 'summary' => 'Задача: собрать метрики', 'object_id' => $object->id, 'title' => 'Собрать метрики', 'person_id' => $person->id],
                ['type' => 'metric_status', 'summary' => 'Метрика в красное', 'metric_id' => $metric->id, 'status' => 'red', 'value_text' => '8%', 'comment' => 'просели после релиза'],
            ],
            'unparsed' => [['text' => 'у Димы завал', 'reason' => 'нет сущности']],
        ])
        ->assertOk()
        ->assertJsonPath('applied', [0, 1, 2])
        ->assertJsonPath('failed', []);

    $assignment = ShtabAssignment::sole();
    expect($assignment->person_id)->toBe($person->id)
        ->and($assignment->object_id)->toBe($object->id)
        ->and($assignment->comment)->toBe('ИИ-разбор: Назначить на территорию');

    $task = ShtabTask::sole();
    expect($task->title)->toBe('Собрать метрики')
        ->and($task->assignee_person_id)->toBe($person->id);

    expect($metric->refresh()->status)->toBe('red')
        ->and($metric->value_text)->toBe('8%');

    $types = ShtabEvent::query()->orderBy('id')->pluck('type')->all();
    expect($types)->toBe(['assignment_started', 'task_assigned', 'metric_status_changed', 'ai_digest']);

    $metricEvent = ShtabEvent::query()->where('type', 'metric_status_changed')->sole();
    expect($metricEvent->payload)->toBe(['from' => 'green', 'to' => 'red', 'value_text' => '8%'])
        ->and($metricEvent->comment)->toBe('ИИ-разбор: просели после релиза');

    $startEvent = ShtabEvent::query()->where('type', 'assignment_started')->sole();
    expect($startEvent->comment)->toBe('ИИ-разбор: Назначить на территорию');
});

it('apply continues past a failing operation and reports it', function () {
    $existing = ShtabAssignment::factory()->create();
    $object = ShtabObject::factory()->create();

    $response = $this->actingAs(aiAdmin())
        ->postJson('/shtab/ai/apply', [
            'text' => 'дубль и задача',
            'operations' => [
                ['type' => 'assign', 'summary' => 'Дубль назначения', 'person_id' => $existing->person_id, 'object_id' => $existing->object_id, 'role_label' => 'дубль'],
                ['type' => 'task_add', 'summary' => 'Новая задача', 'object_id' => $object->id, 'title' => 'Довезти лендинг'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('applied', [1])
        ->assertJsonPath('failed.0.index', 0)
        ->assertJsonPath('failed.0.summary', 'Дубль назначения');

    expect($response->json('failed.0.reason'))->toContain('уже назначен')
        ->and(ShtabAssignment::count())->toBe(1)
        ->and(ShtabTask::sole()->title)->toBe('Довезти лендинг');
});

it('apply writes an ai_digest chronicle event with counts and unparsed', function () {
    $object = ShtabObject::factory()->create();

    $this->actingAs(aiAdmin())
        ->postJson('/shtab/ai/apply', [
            'text' => 'исходный рассказ Глеба',
            'operations' => [
                ['type' => 'task_add', 'summary' => 'Задача', 'object_id' => $object->id, 'title' => 'Задача'],
                ['type' => 'task_done', 'summary' => 'Закрыть несуществующую', 'task_id' => 999999],
            ],
            'unparsed' => [['text' => 'непонятный кусок', 'reason' => 'нет сущности']],
        ])
        ->assertOk()
        ->assertJsonPath('applied', [0])
        ->assertJsonPath('failed.0.index', 1);

    $event = ShtabEvent::query()->where('type', 'ai_digest')->sole();
    expect($event->payload['applied'])->toBe(1)
        ->and($event->payload['failed'])->toBe(1)
        ->and($event->payload['unparsed'])->toBe([['text' => 'непонятный кусок', 'reason' => 'нет сущности']])
        ->and($event->comment)->toBe('исходный рассказ Глеба');
});

it('forbids non-admins from ai endpoints', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/shtab/ai/digest', ['text' => 'x'])->assertForbidden();
    $this->actingAs($user)->postJson('/shtab/ai/apply', ['operations' => []])->assertForbidden();
});
