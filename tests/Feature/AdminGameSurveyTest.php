<?php

use App\Models\GameContent;
use Database\Seeders\GameContentSeeder;

beforeEach(function () {
    $this->seed(GameContentSeeder::class);
});

it('updates the survey without touching the scenario', function () {
    $admin = makeAdmin();
    $before = GameContent::current();
    $yearsBefore = $before->data['years'];

    $this->actingAs($admin)->put('/admin/game/survey', [
        'questions' => [
            ['id' => 'experience', 'question' => 'Новый текст вопроса?', 'options' => ['Да', 'Нет']],
            ['id' => '', 'question' => 'Совсем новый вопрос?', 'options' => ['A', 'B', 'C']],
        ],
    ])->assertRedirect();

    $after = GameContent::current();
    expect($after->data['survey'])->toHaveCount(2);
    expect($after->data['survey'][0]['question'])->toBe('Новый текст вопроса?');
    expect($after->data['survey'][0]['id'])->toBe('experience');
    expect($after->data['survey'][1]['id'])->not->toBe('');
    expect($after->data['years'])->toEqual($yearsBefore);
});

it('trims empty options and rejects questions with fewer than two', function () {
    $admin = makeAdmin();
    $before = GameContent::current();

    $this->actingAs($admin)->put('/admin/game/survey', [
        'questions' => [
            ['id' => 'x', 'question' => 'Вопрос', 'options' => ['только один']],
        ],
    ])->assertSessionHasErrors();

    expect(GameContent::current()->id)->toBe($before->id);
});
