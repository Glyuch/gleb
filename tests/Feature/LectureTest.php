<?php

use App\Models\User;
use App\Support\LectureRooms;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->withCredentials();
    $this->lectureCachePath = sys_get_temp_dir().'/gleb-lecture-test-'.bin2hex(random_bytes(8));
    config(['lecture.cache_path' => $this->lectureCachePath]);
});

afterEach(function () {
    File::deleteDirectory($this->lectureCachePath);
});

function lectureOwner(): User
{
    return User::factory()->create(['is_admin' => true]);
}

/** @return array<string, mixed> */
function openLectureRoom(User $owner, string $question = 'exchange'): array
{
    $rooms = app(LectureRooms::class);
    $room = $rooms->create($owner);
    $rooms->change($room['code'], $owner, 'question', $question);

    return $rooms->change($room['code'], $owner, 'open', null);
}

/** @return array<string, int|string> */
function lectureVote(array $room, int $option = 0): array
{
    return ['questionId' => $room['questionId'], 'revision' => $room['revision'], 'option' => $option];
}

it('requires admin authentication for every presenter action', function () {
    $this->get('/admin/lecture')->assertRedirect('/login');
    $this->postJson('/admin/lecture/rooms')->assertUnauthorized();
    $this->patchJson('/admin/lecture/rooms/ABC234', ['action' => 'open'])->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    $this->get('/admin/lecture')->assertForbidden();
    $this->postJson('/admin/lecture/rooms')->assertForbidden();
    $this->patchJson('/admin/lecture/rooms/ABC234', ['action' => 'open'])->assertForbidden();
});

it('creates a room and restores it only for its owning presenter', function () {
    $owner = lectureOwner();
    $created = $this->actingAs($owner)->postJson('/admin/lecture/rooms')->assertCreated();
    $code = $created->json('code');
    $created->assertJsonPath('canManage', true)
        ->assertJsonPath('question', null)
        ->assertJsonPath('open', false)
        ->assertJsonPath('totalVotes', 0)
        ->assertJsonMissingPath('ownerId')
        ->assertJsonMissingPath('polls')
        ->assertJsonPath('qrUrl', route('lecture.qr', ['code' => $code]));

    $this->get('/admin/lecture')->assertInertia(fn (Assert $page) => $page
        ->component('lecture/index', false)
        ->where('isPresenter', true)
        ->where('room.code', $code)
        ->missing('questions.0.correctIndex')
        ->missing('questions.0.explanation'));

    $this->actingAs(lectureOwner())->patchJson('/admin/lecture/rooms/'.$code, ['action' => 'end'])->assertForbidden();
});

it('does not publish answer keys in the public presentation props', function () {
    $this->get('/lecture')->assertInertia(fn (Assert $page) => $page
        ->component('lecture/index', false)
        ->where('isPresenter', false)
        ->where('room', null)
        ->where('createRoomUrl', null)
        ->has('questions', 9)
        ->missing('questions.1.correctIndex')
        ->missing('questions.1.explanation'));
});

it('issues a persistent encrypted participant cookie without registration and renders the QR locally', function () {
    $room = openLectureRoom(lectureOwner());
    $response = $this->get('https://localhost/lecture/join/'.$room['code'])
        ->assertSuccessful()
        ->assertCookie('lecture_participant')
        ->assertInertia(fn (Assert $page) => $page
            ->component('lecture/join', false)
            ->where('room.canManage', false)
            ->where('room.counts', null)
            ->missing('room.question.correctIndex'));
    $cookie = $response->getCookie('lecture_participant');
    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getPath())->toBe('/lecture')
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getValue())->toMatch('/\A[a-f0-9]{64}\z/');

    $this->get('/lecture/rooms/'.$room['code'].'/qr.svg')
        ->assertSuccessful()->assertHeader('Content-Type', 'image/svg+xml')
        ->assertSee('<svg', false);
});

it('accepts a single browser vote and hides results and the answer until reveal', function () {
    $owner = lectureOwner();
    $room = openLectureRoom($owner);
    $token = str_repeat('a', 64);
    $url = '/lecture/rooms/'.$room['code'];

    $this->withCookie('lecture_participant', $token)
        ->postJson($url.'/vote', lectureVote($room))
        ->assertSuccessful()->assertJsonPath('myVote', 0)->assertJsonPath('totalVotes', 1)
        ->assertJsonPath('counts', null)->assertJsonMissingPath('question.correctIndex');
    $this->postJson($url.'/vote', lectureVote($room, 1))->assertConflict();
    $this->getJson($url.'/state')->assertJsonPath('totalVotes', 1)->assertJsonPath('myVote', 0);

    $this->actingAs($owner)->getJson($url.'/state')
        ->assertJsonPath('counts', [1, 0, 0, 0])->assertJsonMissingPath('question.correctIndex');

    $this->patchJson('/admin/lecture/rooms/'.$room['code'], ['action' => 'reveal'])
        ->assertSuccessful()->assertJsonPath('open', false)->assertJsonPath('revealed', true);
    auth()->logout();

    $this->getJson($url.'/state')
        ->assertJsonPath('counts', [1, 0, 0, 0])
        ->assertJsonPath('question.correctIndex', 0)
        ->assertJsonStructure(['question' => ['explanation']]);
});

it('keeps votes isolated across browsers questions and rooms', function () {
    $owner = lectureOwner();
    $first = openLectureRoom($owner);
    $second = openLectureRoom($owner);
    $this->withCookie('lecture_participant', str_repeat('b', 64))
        ->postJson('/lecture/rooms/'.$first['code'].'/vote', lectureVote($first, 1))->assertSuccessful();
    $this->withCookie('lecture_participant', str_repeat('c', 64))
        ->postJson('/lecture/rooms/'.$first['code'].'/vote', lectureVote($first, 2))->assertJsonPath('totalVotes', 2);
    $this->postJson('/lecture/rooms/'.$second['code'].'/vote', lectureVote($second, 3))->assertJsonPath('totalVotes', 1);

    $rooms = app(LectureRooms::class);
    $rooms->change($first['code'], $owner, 'question', 'experience');
    $changed = $rooms->change($first['code'], $owner, 'open', null);
    $this->postJson('/lecture/rooms/'.$first['code'].'/vote', lectureVote($changed, 0))->assertJsonPath('totalVotes', 1);
    $rooms->change($first['code'], $owner, 'question', 'exchange');
    $this->getJson('/lecture/rooms/'.$first['code'].'/state')->assertJsonPath('myVote', 2)->assertJsonPath('totalVotes', 2);
});

it('rejects missing forged and malformed participant cookies', function (?string $token) {
    $room = openLectureRoom(lectureOwner());
    if ($token !== null) {
        $this->withCookie('lecture_participant', $token);
    }
    $this->postJson('/lecture/rooms/'.$room['code'].'/vote', lectureVote($room))->assertForbidden();
})->with([null, 'chosen-name', 'abc', str_repeat('z', 64)]);

it('rejects out of range options and unknown questions without recording a vote', function (array $invalid) {
    $room = openLectureRoom(lectureOwner(), 'cashflow');
    $this->withCookie('lecture_participant', str_repeat('d', 64))
        ->postJson('/lecture/rooms/'.$room['code'].'/vote', array_merge(lectureVote($room), $invalid))
        ->assertUnprocessable();
    $this->getJson('/lecture/rooms/'.$room['code'].'/state')->assertJsonPath('totalVotes', 0);
})->with([
    [['option' => -1]],
    [['option' => 3]],
    [['option' => 9]],
    [['option' => 'free text']],
    [['questionId' => 'invented']],
    [['revision' => -1]],
]);

it('rejects stale closed and ended votes and does not reopen a revealed question', function () {
    $owner = lectureOwner();
    $room = openLectureRoom($owner);
    $url = '/lecture/rooms/'.$room['code'];
    $rooms = app(LectureRooms::class);
    $this->withCookie('lecture_participant', str_repeat('e', 64));

    $rooms->change($room['code'], $owner, 'close', null);
    $this->postJson($url.'/vote', lectureVote($room))->assertConflict();
    $reopened = $rooms->change($room['code'], $owner, 'open', null);
    $this->postJson($url.'/vote', lectureVote($room))->assertConflict();
    $this->postJson($url.'/vote', lectureVote($reopened))->assertSuccessful();

    $rooms->change($room['code'], $owner, 'question', 'secondary');
    $next = $rooms->change($room['code'], $owner, 'open', null);
    $this->postJson($url.'/vote', lectureVote($reopened))->assertConflict();

    $this->actingAs($owner)->patchJson('/admin/lecture/rooms/'.$room['code'], ['action' => 'reveal'])->assertSuccessful();
    $this->patchJson('/admin/lecture/rooms/'.$room['code'], ['action' => 'open'])->assertConflict();
    $this->patchJson('/admin/lecture/rooms/'.$room['code'], ['action' => 'end'])->assertSuccessful();
    $this->patchJson('/admin/lecture/rooms/'.$room['code'], ['action' => 'open'])->assertConflict();
    auth()->logout();
    $this->postJson($url.'/vote', lectureVote($next))->assertConflict();
    $this->getJson($url.'/state')->assertJsonPath('ended', true);
});

it('preserves the fixed expiration while rooms are active', function () {
    $owner = lectureOwner();
    $room = openLectureRoom($owner);
    $this->travel(11)->hours();
    $rooms = app(LectureRooms::class);
    $changed = $rooms->change($room['code'], $owner, 'close', null);
    expect($changed['expiresAt'])->toBe($room['expiresAt']);
    $this->travel(2)->hours();

    $this->getJson('/lecture/rooms/'.$room['code'].'/state')->assertNotFound();
    $this->get('/lecture/join/'.$room['code'])->assertNotFound();
    $this->get('/lecture/rooms/'.$room['code'].'/qr.svg')->assertNotFound();
    $this->withCookie('lecture_participant', str_repeat('f', 64))
        ->postJson('/lecture/rooms/'.$room['code'].'/vote', lectureVote($room))->assertNotFound();
    $this->actingAs($owner)->patchJson('/admin/lecture/rooms/'.$room['code'], ['action' => 'open'])->assertNotFound();
});

it('rate limits repeated voting attempts per browser without blocking another audience browser', function () {
    $room = openLectureRoom(lectureOwner());
    $url = '/lecture/rooms/'.$room['code'].'/vote';
    $this->withCookie('lecture_participant', str_repeat('1', 64));
    $this->postJson($url, lectureVote($room))->assertSuccessful();
    for ($attempt = 0; $attempt < 11; $attempt++) {
        $this->postJson($url, lectureVote($room))->assertConflict();
    }
    $this->postJson($url, lectureVote($room))->assertTooManyRequests()->assertHeader('Retry-After');
    $this->withCookie('lecture_participant', str_repeat('2', 64))
        ->postJson($url, lectureVote($room))->assertSuccessful()->assertJsonPath('totalVotes', 2);
});

it('validates moderator commands and only opens a selected question', function () {
    $owner = lectureOwner();
    $room = app(LectureRooms::class)->create($owner);
    $url = '/admin/lecture/rooms/'.$room['code'];
    $this->actingAs($owner)->patchJson($url, ['action' => 'open'])->assertConflict();
    $this->patchJson($url, ['action' => 'question'])->assertUnprocessable();
    $this->patchJson($url, ['action' => 'question', 'questionId' => 'anything'])->assertUnprocessable();
    $this->patchJson($url, ['action' => 'delete'])->assertUnprocessable();
    $this->patchJson($url, ['action' => 'question', 'questionId' => 'experience'])
        ->assertSuccessful()->assertJsonPath('question.id', 'experience')->assertJsonPath('open', false);
    $this->patchJson($url, ['action' => 'open'])->assertSuccessful()->assertJsonPath('open', true);
});

it('rejects an unencrypted forged cookie and preserves duplicate protection when reopening', function () {
    $owner = lectureOwner();
    $room = openLectureRoom($owner);
    $url = '/lecture/rooms/'.$room['code'].'/vote';
    $token = str_repeat('3', 64);
    $this->withUnencryptedCookie('lecture_participant', $token)
        ->postJson($url, lectureVote($room))->assertForbidden();
    $this->unencryptedCookies = [];
    $this->withCookie('lecture_participant', $token)
        ->postJson($url, lectureVote($room))->assertSuccessful();
    $rooms = app(LectureRooms::class);
    $rooms->change($room['code'], $owner, 'close', null);
    $reopened = $rooms->change($room['code'], $owner, 'open', null);
    $this->postJson($url, lectureVote($reopened, 2))->assertConflict();
});

it('returns a retry response while another vote owns the room lock', function () {
    $room = openLectureRoom(lectureOwner());
    $cache = Cache::build([
        'driver' => 'file',
        'path' => config('lecture.cache_path'),
        'lock_path' => config('lecture.cache_path'),
    ]);
    $lock = $cache->lock('room-lock:'.$room['code'], 10);
    expect($lock->get())->toBeTrue();

    try {
        $this->withCookie('lecture_participant', str_repeat('4', 64))
            ->postJson('/lecture/rooms/'.$room['code'].'/vote', lectureVote($room))
            ->assertTooManyRequests()->assertHeader('Retry-After', '3');
    } finally {
        $lock->release();
    }
    $this->getJson('/lecture/rooms/'.$room['code'].'/state')->assertJsonPath('totalVotes', 0);
    $this->postJson('/lecture/rooms/'.$room['code'].'/vote', lectureVote($room))->assertSuccessful();
});

/** @return array<string, string|null> */
function lecturePresent(?string $questionId = 'exchange', string $phase = 'open'): array
{
    return [
        'action' => 'present',
        'commandId' => (string) Str::uuid(),
        'questionId' => $questionId,
        'phase' => $phase,
    ];
}

it('reuses an active owned session room on repeated start requests', function () {
    $owner = lectureOwner();
    $first = $this->actingAs($owner)->postJson('/admin/lecture/rooms', ['reuse' => true])->assertCreated();
    $code = $first->json('code');
    $first->assertJsonPath('question', null)->assertJsonPath('revision', 0);
    $this->postJson('/admin/lecture/rooms', ['reuse' => true])
        ->assertSuccessful()->assertJsonPath('code', $code)->assertJsonPath('question', null);
    $route = app('router')->getRoutes()->getByName('lecture.rooms.store');
    expect($route->locksFor())->toBe(10)->and($route->waitsFor())->toBe(3);

    $this->patchJson('/admin/lecture/rooms/'.$code, ['action' => 'end'])->assertSuccessful();
    $next = $this->postJson('/admin/lecture/rooms', ['reuse' => true])->assertCreated()->json('code');
    expect($next)->not->toBe($code);

    $other = lectureOwner();
    $this->actingAs($other)->postJson('/admin/lecture/rooms', ['reuse' => true])
        ->assertCreated()->assertJsonPath('canManage', true)->assertJsonMissing(['code' => $next]);
    $manual = $this->postJson('/admin/lecture/rooms')->assertCreated()->json('code');
    expect($manual)->not->toBe($next);
});

it('presents a question and its phase atomically with stable retries and revisions', function () {
    $owner = lectureOwner();
    $room = app(LectureRooms::class)->create($owner);
    $url = '/admin/lecture/rooms/'.$room['code'];
    $command = lecturePresent();

    $this->actingAs($owner)->patchJson($url, $command)->assertSuccessful()
        ->assertJsonPath('question.id', 'exchange')->assertJsonPath('open', true)
        ->assertJsonPath('revision', 1)->assertJsonMissingPath('question.correctIndex');
    $this->patchJson($url, $command)->assertSuccessful()->assertJsonPath('revision', 1);
    $this->patchJson($url, lecturePresent())->assertSuccessful()->assertJsonPath('revision', 1);

    $this->patchJson($url, lecturePresent(null, 'waiting'))->assertSuccessful()
        ->assertJsonPath('question', null)->assertJsonPath('open', false)->assertJsonPath('revision', 2);
    $this->patchJson($url, $command)->assertSuccessful()
        ->assertJsonPath('question', null)->assertJsonPath('revision', 2);
    $this->patchJson($url, [...$command, 'phase' => 'results'])->assertConflict();
    $this->patchJson($url, lecturePresent('secondary', 'waiting'))->assertSuccessful()
        ->assertJsonPath('question.id', 'secondary')->assertJsonPath('open', false)->assertJsonPath('revision', 3);
    $this->patchJson($url, lecturePresent('secondary', 'closed'))->assertSuccessful()->assertJsonPath('revision', 3);
});

it('preserves votes when leaving a poll and never conceals or reopens revealed results', function (string $phase) {
    $owner = lectureOwner();
    $rooms = app(LectureRooms::class);
    $room = openLectureRoom($owner);
    $rooms->vote($room['code'], str_repeat('a', 64), 'exchange', $room['revision'], 0);
    $url = '/admin/lecture/rooms/'.$room['code'];

    $this->actingAs($owner)->patchJson($url, lecturePresent('exchange', 'results'))
        ->assertSuccessful()->assertJsonPath('totalVotes', 1)->assertJsonPath('revealed', true)
        ->assertJsonPath('counts', [1, 0, 0, 0])->assertJsonPath('open', false);
    $this->patchJson($url, lecturePresent(null, 'waiting'))->assertSuccessful()->assertJsonPath('question', null);
    $returned = $this->patchJson($url, lecturePresent('exchange', $phase))->assertSuccessful()
        ->assertJsonPath('question.id', 'exchange')->assertJsonPath('open', false)
        ->assertJsonPath('revealed', true)->assertJsonPath('question.correctIndex', 0)
        ->assertJsonPath('totalVotes', 1)->assertJsonPath('counts', [1, 0, 0, 0]);
    $this->patchJson($url, lecturePresent('exchange', 'open'))
        ->assertSuccessful()->assertJsonPath('open', false)->assertJsonPath('revision', $returned->json('revision'));
})->with(['waiting', 'open', 'closed', 'results']);

it('preserves unrevealed votes across content steps and rejects stale audience requests', function () {
    $owner = lectureOwner();
    $rooms = app(LectureRooms::class);
    $room = openLectureRoom($owner);
    $rooms->vote($room['code'], str_repeat('b', 64), 'exchange', $room['revision'], 2);
    $url = '/admin/lecture/rooms/'.$room['code'];
    $this->actingAs($owner)->patchJson($url, lecturePresent(null, 'waiting'))->assertSuccessful();
    auth()->logout();
    $this->withCookie('lecture_participant', str_repeat('c', 64))
        ->postJson('/lecture/rooms/'.$room['code'].'/vote', lectureVote($room))->assertConflict();

    $this->actingAs($owner)->patchJson($url, lecturePresent())->assertSuccessful()
        ->assertJsonPath('totalVotes', 1)->assertJsonPath('open', true)->assertJsonPath('revealed', false);
    auth()->logout();
    $this->getJson('/lecture/rooms/'.$room['code'].'/state')->assertJsonPath('counts', null)->assertJsonMissingPath('question.correctIndex');
    $this->postJson('/lecture/rooms/'.$room['code'].'/vote', lectureVote($room))->assertConflict();
});

it('returns current ended state for an exact retry and denies new commands after ending', function () {
    $owner = lectureOwner();
    $room = app(LectureRooms::class)->create($owner);
    $url = '/admin/lecture/rooms/'.$room['code'];
    $command = lecturePresent();
    $this->actingAs($owner)->patchJson($url, $command)->assertSuccessful();
    $ended = $this->patchJson($url, ['action' => 'end'])->assertSuccessful();
    $this->patchJson($url, $command)->assertSuccessful()
        ->assertJsonPath('ended', true)->assertJsonPath('revision', $ended->json('revision'));
    $this->patchJson($url, lecturePresent())->assertConflict();
    $this->actingAs(lectureOwner())->patchJson($url, $command)->assertForbidden();
    auth()->logout();
    $this->patchJson($url, $command)->assertUnauthorized();
});

it('validates complete presentation commands', function (array $changes) {
    $room = app(LectureRooms::class)->create($owner = lectureOwner());
    $this->actingAs($owner)->patchJson('/admin/lecture/rooms/'.$room['code'], array_merge(lecturePresent(), $changes))
        ->assertUnprocessable();
    expect(app(LectureRooms::class)->get($room['code'])['revision'])->toBe(0);
})->with([
    [['commandId' => null]],
    [['commandId' => 'not-a-uuid']],
    [['phase' => null]],
    [['phase' => 'anything']],
    [['questionId' => 'unknown']],
    [['questionId' => null, 'phase' => 'open']],
    [['questionId' => null, 'phase' => 'results']],
    [['questionId' => 'exchange', 'phase' => 'portrait']],
    [['questionId' => 'profession', 'phase' => 'portrait']],
]);

it('only changes revision when manual controls change observable state', function () {
    $room = openLectureRoom($owner = lectureOwner());
    $url = '/admin/lecture/rooms/'.$room['code'];
    $this->actingAs($owner)->patchJson($url, ['action' => 'open'])
        ->assertSuccessful()->assertJsonPath('revision', $room['revision']);
    $closed = $this->patchJson($url, ['action' => 'close'])->assertSuccessful()->json('revision');
    $this->patchJson($url, ['action' => 'close'])->assertSuccessful()->assertJsonPath('revision', $closed);
});

it('accepts the final option of each audience survey and reveals only anonymous counts', function (string $id, int $option) {
    $owner = lectureOwner();
    $room = openLectureRoom($owner, $id);
    $url = '/lecture/rooms/'.$room['code'];
    $this->withCookie('lecture_participant', str_repeat('5', 64))
        ->postJson($url.'/vote', lectureVote($room, $option))
        ->assertSuccessful()->assertJsonPath('myVote', $option)->assertJsonPath('totalVotes', 1)
        ->assertJsonPath('counts', null)->assertJsonMissingPath('question.correctIndex');
    $this->postJson($url.'/vote', lectureVote($room, 0))->assertConflict();
    $this->getJson($url.'/state')->assertJsonPath('myVote', $option)->assertJsonPath('counts', null);

    $counts = array_fill(0, $option + 1, 0);
    $counts[$option] = 1;
    $this->actingAs($owner)->patchJson('/admin/lecture/rooms/'.$room['code'], lecturePresent($id, 'results'))
        ->assertSuccessful()->assertJsonPath('counts', $counts)
        ->assertJsonPath('question.correctIndex', null)->assertJsonPath('totalVotes', 1);
    auth()->logout();
    $this->getJson($url.'/state')->assertJsonPath('counts', $counts)
        ->assertJsonPath('question.correctIndex', null)->assertJsonMissingPath('polls')
        ->assertJsonMissingPath('votes')->assertJsonStructure(['question' => ['explanation']]);
})->with([
    ['profession', 11],
    ['savings_rate', 5],
    ['financial_level', 3],
]);

it('validates the option against each question instead of a global maximum', function (string $id, int $option) {
    $room = openLectureRoom(lectureOwner(), $id);
    $this->withCookie('lecture_participant', str_repeat('6', 64))
        ->postJson('/lecture/rooms/'.$room['code'].'/vote', lectureVote($room, $option))
        ->assertUnprocessable();
    $this->getJson('/lecture/rooms/'.$room['code'].'/state')->assertJsonPath('totalVotes', 0);
})->with([
    ['profession', 12],
    ['savings_rate', 6],
    ['financial_level', 4],
    ['exchange', 4],
]);

it('adds new audience questions to legacy rooms without resetting votes expiry or revisions', function () {
    $allQuestions = config('lecture.questions');
    $newIds = ['profession', 'savings_rate', 'financial_level'];
    config(['lecture.questions' => array_diff_key($allQuestions, array_flip($newIds))]);
    $owner = lectureOwner();
    $rooms = app(LectureRooms::class);
    $legacy = openLectureRoom($owner, 'experience');
    $rooms->vote($legacy['code'], str_repeat('7', 64), 'experience', $legacy['revision'], 2);
    $legacy = $rooms->change($legacy['code'], $owner, 'reveal', null);
    config(['lecture.questions' => $allQuestions]);

    $upgraded = $rooms->get($legacy['code']);
    expect($upgraded['revision'])->toBe($legacy['revision'])
        ->and($upgraded['expiresAt'])->toBe($legacy['expiresAt'])
        ->and($upgraded['questionId'])->toBe('experience')
        ->and($upgraded['polls']['experience'])->toBe($legacy['polls']['experience']);
    foreach ($newIds as $id) {
        expect($upgraded['polls'][$id])->toBe(['votes' => [], 'revealed' => false]);
    }

    $selected = $this->actingAs($owner)
        ->patchJson('/admin/lecture/rooms/'.$legacy['code'], lecturePresent('profession'))
        ->assertSuccessful()->assertJsonPath('question.id', 'profession')->assertJsonPath('open', true)
        ->json();
    auth()->logout();
    $this->withCookie('lecture_participant', str_repeat('7', 64))
        ->postJson('/lecture/rooms/'.$legacy['code'].'/vote', [
            'questionId' => 'profession',
            'revision' => $selected['revision'],
            'option' => 11,
        ])->assertSuccessful()->assertJsonPath('myVote', 11);
    $saved = $rooms->get($legacy['code']);
    expect($saved['polls']['experience'])->toBe($legacy['polls']['experience'])
        ->and($saved['polls']['profession']['votes'][hash('sha256', str_repeat('7', 64))])->toBe(11)
        ->and($saved['expiresAt'])->toBe($legacy['expiresAt']);
});

it('publishes only revealed audience portrait totals through content transitions', function () {
    $owner = lectureOwner();
    $rooms = app(LectureRooms::class);
    $room = openLectureRoom($owner, 'profession');
    $code = $room['code'];
    $url = '/lecture/rooms/'.$code.'/state';
    $participant = str_repeat('8', 64);
    $rooms->vote($code, $participant, 'profession', $room['revision'], 11);

    $this->getJson($url)->assertJsonPath('portrait', []);
    $this->actingAs($owner)->getJson($url)
        ->assertJsonPath('totalVotes', 1)->assertJsonPath('portrait', []);
    auth()->logout();

    $professionCounts = array_fill(0, 12, 0);
    $professionCounts[11] = 1;
    $expected = [['questionId' => 'profession', 'counts' => $professionCounts, 'totalVotes' => 1]];
    $rooms->present($code, $owner, (string) Str::uuid(), 'profession', 'results');
    $this->getJson($url)->assertJsonPath('portrait', $expected);
    $rooms->present($code, $owner, (string) Str::uuid(), null, 'waiting');
    $this->getJson($url)->assertJsonPath('question', null)->assertJsonPath('portrait', $expected);

    $room = $rooms->present($code, $owner, (string) Str::uuid(), 'savings_rate', 'open');
    $rooms->vote($code, $participant, 'savings_rate', $room['revision'], 1);
    $rooms->vote($code, str_repeat('9', 64), 'savings_rate', $room['revision'], 5);
    $this->getJson($url)->assertJsonPath('portrait', $expected)->assertJsonPath('counts', null);
    $this->actingAs($owner)->getJson($url)->assertJsonPath('portrait', $expected);
    auth()->logout();
    $rooms->present($code, $owner, (string) Str::uuid(), 'savings_rate', 'results');
    $expected[] = ['questionId' => 'savings_rate', 'counts' => [0, 1, 0, 0, 0, 1], 'totalVotes' => 2];

    $room = $rooms->present($code, $owner, (string) Str::uuid(), 'financial_level', 'open');
    $rooms->vote($code, $participant, 'financial_level', $room['revision'], 3);
    $rooms->present($code, $owner, (string) Str::uuid(), 'financial_level', 'results');
    $expected[] = ['questionId' => 'financial_level', 'counts' => [0, 0, 0, 1], 'totalVotes' => 1];

    $rooms->present($code, $owner, (string) Str::uuid(), 'exchange', 'results');
    $rooms->present($code, $owner, (string) Str::uuid(), null, 'waiting');
    $this->getJson($url)->assertJsonPath('question', null)->assertJsonPath('portrait', $expected)
        ->assertJsonMissingPath('polls')->assertJsonMissingPath('votes');
    $this->actingAs($owner)->getJson($url)->assertJsonPath('portrait', $expected);
    auth()->logout();

    $other = $rooms->create($owner);
    $this->getJson('/lecture/rooms/'.$other['code'].'/state')->assertJsonPath('portrait', []);
});

it('collects three opening answers before atomically revealing the complete portrait', function () {
    $owner = lectureOwner();
    $rooms = app(LectureRooms::class);
    $room = $rooms->create($owner);
    $code = $room['code'];
    $manageUrl = '/admin/lecture/rooms/'.$code;
    $stateUrl = '/lecture/rooms/'.$code.'/state';
    $voteUrl = '/lecture/rooms/'.$code.'/vote';
    $previousVote = null;
    $expected = [];

    foreach (['profession' => 11, 'savings_rate' => 5, 'financial_level' => 3] as $id => $option) {
        $selected = $this->actingAs($owner)->patchJson($manageUrl, lecturePresent($id, 'open'))
            ->assertSuccessful()->assertJsonPath('question.id', $id)
            ->assertJsonPath('open', true)->assertJsonPath('revealed', false)
            ->assertJsonPath('portrait', [])->json();
        auth()->logout();

        if ($previousVote !== null) {
            $this->withCookie('lecture_participant', str_repeat('d', 64))
                ->postJson($voteUrl, $previousVote)->assertConflict();
        }
        $previousVote = ['questionId' => $id, 'revision' => $selected['revision'], 'option' => $option];
        $this->withCookie('lecture_participant', str_repeat('e', 64))
            ->postJson($voteUrl, $previousVote)->assertSuccessful()
            ->assertJsonPath('myVote', $option)->assertJsonPath('portrait', [])
            ->assertJsonPath('counts', null)->assertJsonMissingPath('question.correctIndex');
        $this->getJson($stateUrl)->assertJsonPath('portrait', [])->assertJsonPath('counts', null);
        $this->actingAs($owner)->getJson($stateUrl)->assertJsonPath('portrait', []);
        auth()->logout();

        $counts = array_fill(0, count(config('lecture.questions.'.$id.'.options')), 0);
        $counts[$option] = 1;
        $expected[] = ['questionId' => $id, 'counts' => $counts, 'totalVotes' => 1];
    }

    $command = lecturePresent(null, 'portrait');
    $summary = $this->actingAs($owner)->patchJson($manageUrl, $command)->assertSuccessful()
        ->assertJsonPath('question', null)->assertJsonPath('open', false)
        ->assertJsonPath('portrait', $expected)
        ->assertJsonPath('revision', $selected['revision'] + 1)->json();
    $this->patchJson($manageUrl, $command)->assertSuccessful()
        ->assertJsonPath('portrait', $expected)->assertJsonPath('revision', $summary['revision']);
    $this->patchJson($manageUrl, lecturePresent(null, 'portrait'))->assertSuccessful()
        ->assertJsonPath('portrait', $expected)->assertJsonPath('revision', $summary['revision']);
    auth()->logout();

    $this->getJson($stateUrl)->assertJsonPath('question', null)->assertJsonPath('portrait', $expected);
    $this->withCookie('lecture_participant', str_repeat('f', 64))
        ->postJson($voteUrl, $previousVote)->assertConflict();
    $saved = $rooms->get($code);
    expect($saved['expiresAt'])->toBe($room['expiresAt']);
    foreach ($saved['polls'] as $id => $poll) {
        expect($poll['revealed'])->toBe(in_array($id, ['profession', 'savings_rate', 'financial_level'], true));
    }

    $quiz = $this->actingAs($owner)->patchJson($manageUrl, lecturePresent('exchange', 'open'))
        ->assertSuccessful()->assertJsonPath('open', true)->assertJsonPath('revealed', false)
        ->assertJsonMissingPath('question.correctIndex')->json();
    $this->patchJson($manageUrl, $command)->assertSuccessful()
        ->assertJsonPath('question.id', 'exchange')->assertJsonPath('open', true)
        ->assertJsonPath('revision', $quiz['revision'])->assertJsonPath('portrait', $expected);
});

it('only allows the owning admin to reveal the combined portrait', function () {
    $room = app(LectureRooms::class)->create($owner = lectureOwner());
    $url = '/admin/lecture/rooms/'.$room['code'];
    $command = lecturePresent(null, 'portrait');

    $this->patchJson($url, $command)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->patchJson($url, $command)->assertForbidden();
    $this->actingAs(lectureOwner())->patchJson($url, $command)->assertForbidden();
    expect(app(LectureRooms::class)->get($room['code'])['revision'])->toBe(0);

    $this->actingAs($owner)->patchJson($url, ['action' => 'end'])->assertSuccessful();
    $this->patchJson($url, $command)->assertConflict();
});
