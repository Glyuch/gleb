<?php

use App\Support\Nutrition\Claude;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'nutrition.anthropic_key' => 'test-key',
        'nutrition.models.vision' => 'claude-haiku-4-5',
        'nutrition.models.chat' => 'claude-sonnet-5',
    ]);
    Http::preventStrayRequests();
});

it('returns the concatenation of all text blocks on success', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => 'Привет'],
                ['type' => 'text', 'text' => '!'],
            ],
        ]),
    ]);

    $result = Claude::text([['type' => 'text', 'text' => 'Хай']], 'claude-sonnet-5');

    expect($result)->toBe('Привет!');
});

it('sends the anthropic headers and the model in the body, without sampling params', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]]),
    ]);

    Claude::text([['type' => 'text', 'text' => 'Хай']], 'claude-sonnet-5');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.anthropic.com/v1/messages')
            && $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === 'claude-sonnet-5'
            && $request['max_tokens'] === 1024
            && $request['messages'][0]['role'] === 'user'
            && $request['messages'][0]['content'][0]['text'] === 'Хай'
            && is_string($request['system'])
            && ! isset($request['temperature'])
            && ! isset($request['top_p'])
            && ! isset($request['top_k'])
            && ! isset($request['thinking']);
    });
});

it('returns null without throwing on an HTTP 500 response', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([], 500),
    ]);
    Log::spy();

    $result = Claude::text([['type' => 'text', 'text' => 'Хай']], 'claude-sonnet-5');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('returns null when the response has no text content', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => []]),
    ]);
    Log::spy();

    expect(Claude::text([['type' => 'text', 'text' => 'Хай']], 'claude-sonnet-5'))->toBeNull();
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('never throws when the transport raises a connection error', function () {
    Http::fake(function () {
        throw new ConnectionException('boom');
    });
    Log::spy();

    expect(Claude::text([['type' => 'text', 'text' => 'Хай']], 'claude-sonnet-5'))->toBeNull();
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('vision wraps the image and prompt and uses the vision model', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Хорошая тарелка']]]),
    ]);

    $image = ['media_type' => 'image/jpeg', 'data' => 'BASE64DATA'];
    $result = Claude::vision($image, 'Оцени приём');

    expect($result)->toBe('Хорошая тарелка');

    Http::assertSent(function ($request) {
        $content = $request['messages'][0]['content'];

        return $request['model'] === 'claude-haiku-4-5'
            && $request['max_tokens'] === 400
            && $content[0]['type'] === 'image'
            && $content[0]['source']['type'] === 'base64'
            && $content[0]['source']['media_type'] === 'image/jpeg'
            && $content[0]['source']['data'] === 'BASE64DATA'
            && $content[1] === ['type' => 'text', 'text' => 'Оцени приём'];
    });
});
