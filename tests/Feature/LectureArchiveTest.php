<?php

use App\Models\User;
use App\Support\LectureRooms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->withoutVite();
    $this->withCredentials();
    $this->lectureCachePath = sys_get_temp_dir().'/gleb-lecture-archive-test-'.bin2hex(random_bytes(8));
    config(['lecture.cache_path' => $this->lectureCachePath]);
    $this->lectureCache = Cache::build([
        'driver' => 'file',
        'path' => $this->lectureCachePath,
        'lock_path' => $this->lectureCachePath,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->lectureCachePath);
});

/** @return array<string, mixed> */
function archivedLectureState(string $code): array
{
    return json_decode(DB::table('lecture_rooms')->where('code', $code)->value('state'), true, flags: JSON_THROW_ON_ERROR);
}

it('keeps accepted votes and question definitions after cache loss expiration and owner deletion', function () {
    $rooms = app(LectureRooms::class);
    $owner = User::factory()->create(['is_admin' => true]);
    $room = $rooms->create($owner);
    $code = $room['code'];
    $rooms->change($code, $owner, 'question', 'exchange');
    $room = $rooms->change($code, $owner, 'open', null);
    $token = str_repeat('a', 64);
    $vote = ['questionId' => 'exchange', 'revision' => $room['revision'], 'option' => 0];
    $this->withCookie('lecture_participant', $token)
        ->postJson('/lecture/rooms/'.$code.'/vote', $vote)->assertSuccessful();
    $before = archivedLectureState($code);
    expect($before['polls']['exchange']['votes'])->toBe([hash('sha256', $token) => 0])
        ->and($before['questions'])->toBe(config('lecture.questions'));

    $this->lectureCache->flush();
    $this->travel(13)->hours();
    $this->getJson('/lecture/rooms/'.$code.'/state')->assertNotFound();
    $this->get('/lecture/join/'.$code)->assertNotFound();
    $this->postJson('/lecture/rooms/'.$code.'/vote', $vote)->assertNotFound();
    $owner->delete();
    expect(archivedLectureState($code))->toBe($before);
});

it('continues an active room from durable storage and preserves duplicate protection after cache loss', function () {
    $rooms = app(LectureRooms::class);
    $owner = User::factory()->create(['is_admin' => true]);
    $room = $rooms->create($owner);
    $code = $room['code'];
    $rooms->change($code, $owner, 'question', 'exchange');
    $room = $rooms->change($code, $owner, 'open', null);
    $vote = ['questionId' => 'exchange', 'revision' => $room['revision'], 'option' => 0];
    $this->withCookie('lecture_participant', str_repeat('b', 64))
        ->postJson('/lecture/rooms/'.$code.'/vote', $vote)->assertSuccessful();
    $this->lectureCache->flush();
    $this->postJson('/lecture/rooms/'.$code.'/vote', $vote)->assertConflict();
    $this->withCookie('lecture_participant', str_repeat('c', 64))
        ->postJson('/lecture/rooms/'.$code.'/vote', $vote)->assertSuccessful()->assertJsonPath('totalVotes', 2);
    expect(archivedLectureState($code)['polls']['exchange']['votes'])->toHaveCount(2);
});

it('does not rewrite the historical question definitions when configuration changes', function () {
    $rooms = app(LectureRooms::class);
    $owner = User::factory()->create(['is_admin' => true]);
    $room = $rooms->create($owner);
    $original = config('lecture.questions.exchange');
    config(['lecture.questions.exchange.text' => 'New wording', 'lecture.questions.exchange.options' => ['Different options']]);
    $rooms->change($room['code'], $owner, 'question', 'exchange');
    expect(archivedLectureState($room['code'])['questions']['exchange'])->toBe($original);
});

it('imports legacy cache files including expired ones without reopening or deleting them', function (int $hours) {
    $rooms = app(LectureRooms::class);
    $owner = User::factory()->create(['is_admin' => true]);
    $room = $rooms->create($owner);
    $room['polls']['exchange']['votes'][hash('sha256', 'legacy-participant')] = 2;
    DB::table('lecture_rooms')->where('code', $room['code'])->delete();
    $this->lectureCache->put('room:'.$room['code'], $room, Carbon::createFromTimestamp($room['expiresAt']));
    $hash = sha1('room:'.$room['code']);
    $path = $this->lectureCachePath.'/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;
    $originalFile = File::get($path);
    File::put($this->lectureCachePath.'/broken', 'not a serialized room');
    $this->lectureCache->put('rate-limit', 4, 60);
    $this->travel($hours)->hours();

    $this->artisan('lecture:archive')->expectsOutputToContain('Archived 1 lecture rooms.')->assertSuccessful();
    $saved = archivedLectureState($room['code']);
    expect($saved['polls'])->toBe($room['polls'])
        ->and($saved['expiresAt'])->toBe($room['expiresAt'])
        ->and($saved['questions'])->toBe(config('lecture.questions'))
        ->and(File::get($path))->toBe($originalFile);
    $this->artisan('lecture:archive')->expectsOutputToContain('Archived 0 lecture rooms.')->assertSuccessful();
    expect(archivedLectureState($room['code']))->toBe($saved);
    if ($hours > 12) {
        $this->getJson('/lecture/rooms/'.$room['code'].'/state')->assertNotFound();
        expect(archivedLectureState($room['code']))->toBe($saved);
    }
})->with([0, 13]);

it('recovers a live legacy room on first read and never replaces it with a stale cache snapshot', function () {
    $rooms = app(LectureRooms::class);
    $owner = User::factory()->create(['is_admin' => true]);
    $room = $rooms->create($owner);
    DB::table('lecture_rooms')->where('code', $room['code'])->delete();
    $this->lectureCache->put('room:'.$room['code'], $room, Carbon::createFromTimestamp($room['expiresAt']));
    $restored = $rooms->get($room['code']);
    expect($restored['code'])->toBe($room['code']);
    $rooms->change($room['code'], $owner, 'question', 'exchange');
    $opened = $rooms->change($room['code'], $owner, 'open', null);
    $rooms->vote($room['code'], str_repeat('d', 64), 'exchange', $opened['revision'], 1);
    $latest = archivedLectureState($room['code']);
    $this->artisan('lecture:archive')->expectsOutputToContain('Archived 0 lecture rooms.')->assertSuccessful();
    expect(archivedLectureState($room['code']))->toBe($latest)
        ->and($latest['polls']['exchange']['votes'])->toHaveCount(1);
});
