<?php

use App\Models\NutritionMessage;
use App\Support\Nutrition\TelegramClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['nutrition.bot_token' => 'test-token', 'nutrition.chat_id' => 123]);
    Http::preventStrayRequests();
});

it('sends a message with HTML parse mode and logs it outbound', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]]),
    ]);

    (new TelegramClient)->send('Привет <b>мир</b>');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/bottest-token/sendMessage')
            && $request['chat_id'] == 123
            && $request['text'] === 'Привет <b>мир</b>'
            && $request['parse_mode'] === 'HTML';
    });

    $msg = NutritionMessage::first();
    expect($msg->direction)->toBe('out')
        ->and($msg->kind)->toBe('text')
        ->and($msg->content)->toBe('Привет <b>мир</b>')
        ->and($msg->telegram_message_id)->toBe(5);
});

it('sends inline keyboard as reply_markup json and honours the kind', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]]),
    ]);

    $keyboard = [[['text' => 'Да', 'callback_data' => 'yes']]];
    (new TelegramClient)->send('Вопрос?', $keyboard, 'reminder');

    Http::assertSent(function ($request) {
        return $request['reply_markup'] === json_encode(['inline_keyboard' => [[['text' => 'Да', 'callback_data' => 'yes']]]]);
    });

    expect(NutritionMessage::first()->kind)->toBe('reminder');
});

it('does not perform any HTTP call when chat_id is empty', function () {
    config(['nutrition.chat_id' => null]);
    Http::fake();
    Log::spy();

    (new TelegramClient)->send('нет чата');

    Http::assertNothingSent();
    expect(NutritionMessage::count())->toBe(0);
    Log::shouldHaveReceived('info')->once();
});

it('sends a document via multipart and logs it as topic', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]]),
    ]);

    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, 'PDFDATA');

    (new TelegramClient)->sendDocument($path, 'Тема дня');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/bottest-token/sendDocument')
            && $request->hasFile('document');
    });

    $msg = NutritionMessage::first();
    expect($msg->direction)->toBe('out')
        ->and($msg->kind)->toBe('topic')
        ->and($msg->telegram_message_id)->toBe(9);

    @unlink($path);
});

it('answers a callback query', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    (new TelegramClient)->answerCallback('CBID');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/bottest-token/answerCallbackQuery')
            && $request['callback_query_id'] === 'CBID';
    });
});

it('downloads a photo and returns base64 payload', function () {
    Http::fake([
        'api.telegram.org/bottest-token/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_1.jpg']]),
        'api.telegram.org/file/bottest-token/photos/file_1.jpg' => Http::response('BINARYDATA', 200),
    ]);

    $result = (new TelegramClient)->downloadPhotoBase64('FILEID');

    expect($result)->toBe([
        'media_type' => 'image/jpeg',
        'data' => base64_encode('BINARYDATA'),
    ]);
});

it('returns null from downloadPhotoBase64 when getFile fails', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false], 400),
    ]);

    expect((new TelegramClient)->downloadPhotoBase64('FILEID'))->toBeNull();
});

it('returns null and logs a warning when the api call fails', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad'], 400),
    ]);
    Log::spy();

    $result = (new TelegramClient)->api('sendMessage', ['chat_id' => 1]);

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

it('never throws when the transport raises a connection error', function () {
    Http::fake(function () {
        throw new ConnectionException('boom');
    });
    Log::spy();

    $result = (new TelegramClient)->api('sendMessage', ['chat_id' => 1]);

    expect($result)->toBeNull();
});
