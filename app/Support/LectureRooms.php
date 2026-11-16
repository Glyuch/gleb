<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use LogicException;

/**
 * @phpstan-type Poll array{votes: array<string, int>, revealed: bool}
 * @phpstan-type Room array{code: string, ownerId: int, expiresAt: int, questionId: ?string, revision: int, open: bool, ended: bool, polls: array<string, Poll>, commands?: array<string, string>, questions?: array<string, Question>}
 * @phpstan-type Question array{text: string, options: list<string>, correctIndex: ?int, explanation: string}
 */
class LectureRooms
{
    private const PORTRAIT_QUESTION_IDS = ['profession', 'savings_rate', 'financial_level'];

    private Repository $cache;

    public function __construct()
    {
        $this->cache = Cache::build([
            'driver' => 'file',
            'path' => config('lecture.cache_path'),
            'lock_path' => config('lecture.cache_path'),
        ]);
    }

    /** @return array<string, Question> */
    public function questions(): array
    {
        return config('lecture.questions');
    }

    /** @return list<array{id: string, text: string, options: list<string>}> */
    public function publicQuestions(): array
    {
        $questions = [];
        foreach ($this->questions() as $id => $question) {
            $questions[] = ['id' => $id, 'text' => $question['text'], 'options' => $question['options']];
        }

        return $questions;
    }

    /** @return Room */
    public function create(User $owner): array
    {
        $polls = [];
        foreach ($this->questions() as $id => $question) {
            $polls[$id] = ['votes' => [], 'revealed' => false];
        }

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $room = [
                'code' => $code,
                'ownerId' => (int) $owner->id,
                'expiresAt' => now()->addHours((int) config('lecture.lifetime_hours'))->getTimestamp(),
                'questionId' => null,
                'revision' => 0,
                'open' => false,
                'ended' => false,
                'polls' => $polls,
            ];
        } while (! $this->insert($room));

        return $room;
    }

    /** @return Room */
    public function get(string $code): array
    {
        $state = DB::table('lecture_rooms')->where('code', $code)->value('state');
        if ($state === null) {
            /** @var Room|null $legacy */
            $legacy = $this->cache->get('room:'.$code);
            if (is_array($legacy)) {
                $this->insert($legacy);
                $state = DB::table('lecture_rooms')->where('code', $code)->value('state');
            }
        }
        /** @var Room|null $room */
        $room = $state !== null ? json_decode($state, true, flags: JSON_THROW_ON_ERROR) : null;
        abort_unless(is_array($room) && $room['expiresAt'] > now()->timestamp, 404, 'Комната не найдена или срок ее действия истек.');

        foreach (array_keys($this->questions()) as $id) {
            $room['polls'][$id] ??= ['votes' => [], 'revealed' => false];
        }

        return $room;
    }

    /** @return Room */
    public function change(string $code, User $owner, string $action, ?string $questionId): array
    {
        return $this->locked($code, function () use ($code, $owner, $action, $questionId): array {
            $room = $this->get($code);
            abort_unless($room['ownerId'] === (int) $owner->id, 403, 'Этой комнатой управляет другой ведущий.');
            abort_if($room['ended'], 409, 'Выступление завершено. Создайте новую комнату.');

            $before = $room;
            if ($action === 'question') {
                abort_unless($questionId !== null && isset($room['polls'][$questionId]), 422, 'Выберите вопрос из списка.');
                $room['questionId'] = $questionId;
                $room['open'] = false;
            } elseif ($action === 'end') {
                $room['ended'] = true;
                $room['open'] = false;
            } else {
                $id = $room['questionId'];
                abort_if($id === null, 409, 'Сначала выберите вопрос.');
                if ($action === 'open') {
                    abort_if($room['polls'][$id]['revealed'], 409, 'Ответ уже показан. Для нового опроса создайте новую комнату.');
                    $room['open'] = true;
                } elseif ($action === 'close') {
                    $room['open'] = false;
                } elseif ($action === 'reveal') {
                    $room['open'] = false;
                    $poll = $room['polls'][$id];
                    $poll['revealed'] = true;
                    $room['polls'][$id] = $poll;
                } else {
                    abort(422, 'Неизвестное действие.');
                }
            }

            if ($room !== $before) {
                $room['revision']++;
                $this->save($room);
            }

            return $room;
        });
    }

    /** @return Room */
    public function present(string $code, User $owner, string $commandId, ?string $questionId, string $phase): array
    {
        return $this->locked($code, function () use ($code, $owner, $commandId, $questionId, $phase): array {
            $room = $this->get($code);
            abort_unless($room['ownerId'] === (int) $owner->id, 403, 'Этой комнатой управляет другой ведущий.');
            $fingerprint = hash('sha256', json_encode([$questionId, $phase], JSON_THROW_ON_ERROR));
            $commandId = strtolower($commandId);
            $commands = $room['commands'] ?? [];
            if (isset($commands[$commandId])) {
                abort_unless(hash_equals($commands[$commandId], $fingerprint), 409, 'Идентификатор команды уже использован для другого шага.');

                return $room;
            }

            abort_if($room['ended'], 409, 'Выступление завершено. Создайте новую комнату.');
            abort_unless(in_array($phase, ['waiting', 'open', 'closed', 'results', 'portrait'], true), 422, 'Неизвестная фаза вопроса.');
            abort_unless($questionId !== null || in_array($phase, ['waiting', 'portrait'], true), 422, 'Для этой фазы нужен вопрос.');
            abort_if($phase === 'portrait' && $questionId !== null, 422, 'Общий портрет раскрывается без выбора отдельного вопроса.');
            abort_unless($questionId === null || isset($room['polls'][$questionId]), 422, 'Выберите вопрос из списка.');
            abort_if(count($commands) >= 4096, 409, 'Достигнут лимит шагов. Создайте новую комнату.');

            $before = $room;
            $room['questionId'] = $questionId;
            $room['open'] = false;
            if ($phase === 'portrait') {
                foreach (self::PORTRAIT_QUESTION_IDS as $id) {
                    $poll = $room['polls'][$id];
                    $poll['revealed'] = true;
                    $room['polls'][$id] = $poll;
                }
            } elseif ($questionId !== null) {
                $poll = $room['polls'][$questionId];
                if ($phase === 'results') {
                    $poll['revealed'] = true;
                }
                $room['open'] = $phase === 'open' && ! $poll['revealed'];
                $room['polls'][$questionId] = $poll;
            }

            if ($room !== $before) {
                $room['revision']++;
            }
            $commands[$commandId] = $fingerprint;
            $room['commands'] = $commands;
            $this->save($room);

            return $room;
        });
    }

    /** @return Room */
    public function vote(string $code, string $participant, string $questionId, int $revision, int $option): array
    {
        return $this->locked($code, function () use ($code, $participant, $questionId, $revision, $option): array {
            $room = $this->get($code);
            abort_if($room['ended'] || ! $room['open'], 409, 'Прием ответов закрыт.');
            abort_unless($room['questionId'] === $questionId && $room['revision'] === $revision, 409, 'Вопрос изменился. Обновите страницу.');
            $question = $this->questions()[$questionId];
            abort_unless(array_key_exists($option, $question['options']), 422, 'Выберите один из вариантов ответа.');
            $key = hash('sha256', $participant);
            abort_if(isset($room['polls'][$questionId]['votes'][$key]), 409, 'Ваш ответ уже принят.');
            abort_if(count($room['polls'][$questionId]['votes']) >= config('lecture.max_votes_per_question'), 409, 'Достигнут лимит ответов.');

            $poll = $room['polls'][$questionId];
            $poll['votes'][$key] = $option;
            $room['polls'][$questionId] = $poll;
            $this->save($room);

            return $room;
        });
    }

    /**
     * @param  Room  $room
     * @return array<string, mixed>
     */
    public function state(array $room, ?string $participant = null, ?User $viewer = null): array
    {
        $canManage = $viewer?->is_admin && $room['ownerId'] === (int) $viewer->id;
        $id = $room['questionId'];
        $question = null;
        $counts = null;
        $votes = [];
        $revealed = false;
        if ($id !== null) {
            $source = $this->questions()[$id];
            $question = ['id' => $id, 'text' => $source['text'], 'options' => $source['options']];
            $revealed = $room['polls'][$id]['revealed'];
            $votes = $room['polls'][$id]['votes'];
            if ($revealed) {
                $question['correctIndex'] = $source['correctIndex'];
                $question['explanation'] = $source['explanation'];
            }
            if ($revealed || $canManage) {
                $counts = array_fill(0, count($source['options']), 0);
                foreach ($votes as $option) {
                    $counts[$option]++;
                }
            }
        }

        $portrait = [];
        foreach (self::PORTRAIT_QUESTION_IDS as $portraitId) {
            $poll = $room['polls'][$portraitId] ?? null;
            if ($poll === null || ! $poll['revealed']) {
                continue;
            }
            $portraitCounts = array_fill(0, count($this->questions()[$portraitId]['options']), 0);
            foreach ($poll['votes'] as $option) {
                $portraitCounts[$option]++;
            }
            $portrait[] = ['questionId' => $portraitId, 'counts' => $portraitCounts, 'totalVotes' => count($poll['votes'])];
        }

        return [
            'portrait' => $portrait,
            'code' => $room['code'],
            'expiresAt' => Carbon::createFromTimestamp($room['expiresAt'])->toIso8601String(),
            'joinUrl' => route('lecture.join', ['code' => $room['code']]),
            'stateUrl' => route('lecture.state', ['code' => $room['code']]),
            'voteUrl' => route('lecture.vote', ['code' => $room['code']]),
            'qrUrl' => route('lecture.qr', ['code' => $room['code']]),
            'manageUrl' => $canManage ? route('lecture.rooms.update', ['code' => $room['code']]) : null,
            'canManage' => (bool) $canManage,
            'question' => $question,
            'revision' => $room['revision'],
            'open' => $room['open'],
            'revealed' => $revealed,
            'ended' => $room['ended'],
            'totalVotes' => count($votes),
            'counts' => $counts,
            'myVote' => $participant !== null ? ($votes[hash('sha256', $participant)] ?? null) : null,
        ];
    }

    public function limit(string $key, int $maximum): void
    {
        $limiter = new RateLimiter($this->cache);
        if ($limiter->tooManyAttempts($key, $maximum)) {
            abort(429, 'Слишком много запросов. Попробуйте через минуту.', ['Retry-After' => (string) $limiter->availableIn($key)]);
        }
        $limiter->hit($key, 60);
    }

    public function participant(?string $cookie): ?string
    {
        return $cookie !== null && preg_match('/\A[a-f0-9]{64}\z/', $cookie) === 1 ? $cookie : null;
    }

    public function newParticipant(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @param Room $room */
    private function save(array $room): void
    {
        $room['questions'] = array_replace($this->questions(), $room['questions'] ?? []);
        DB::table('lecture_rooms')->where('code', $room['code'])->update([
            'state' => json_encode($room, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /** @param Room $room */
    private function insert(array $room): bool
    {
        $room['questions'] ??= $this->questions();

        return DB::table('lecture_rooms')->insertOrIgnore([
            'code' => $room['code'],
            'owner_id' => $room['ownerId'],
            'state' => json_encode($room, JSON_THROW_ON_ERROR),
            'expires_at' => Carbon::createFromTimestamp($room['expiresAt']),
            'created_at' => Carbon::createFromTimestamp($room['expiresAt'])->subHours((int) config('lecture.lifetime_hours')),
            'updated_at' => now(),
        ]) === 1;
    }

    public function archiveCachedRooms(): int
    {
        $path = config('lecture.cache_path');
        if (! File::isDirectory($path)) {
            return 0;
        }
        $count = 0;
        foreach (File::allFiles($path) as $file) {
            $room = $this->readCachedRoom($file->getPathname());
            if ($room === null || $file->getFilename() !== sha1('room:'.$room['code'])) {
                continue;
            }
            $inserted = $this->locked($room['code'], function () use ($file): bool {
                $latest = $this->readCachedRoom($file->getPathname());

                return $latest !== null && $this->insert($latest);
            });
            $count += $inserted ? 1 : 0;
        }

        return $count;
    }

    /** @return Room|null */
    private function readCachedRoom(string $path): ?array
    {
        if (! File::isFile($path)) {
            return null;
        }
        $room = @unserialize(substr(File::get($path), 10), ['allowed_classes' => false]);
        if (! is_array($room)
            || ! isset($room['code'], $room['ownerId'], $room['expiresAt'], $room['revision'], $room['open'], $room['ended'], $room['polls'])
            || ! is_string($room['code'])
            || preg_match('/\A[A-HJ-NP-Z2-9]{6}\z/', $room['code']) !== 1
            || ! is_int($room['ownerId']) || ! is_int($room['expiresAt']) || ! is_int($room['revision'])
            || ! is_bool($room['open']) || ! is_bool($room['ended']) || ! is_array($room['polls'])
            || ! array_key_exists('questionId', $room)
            || ($room['questionId'] !== null && ! is_string($room['questionId']))) {
            return null;
        }

        return $room;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(string $code, Closure $callback): mixed
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new LogicException('Lecture storage requires atomic locks.');
        }
        try {
            return $store->lock('room-lock:'.$code, 10)->block(3, $callback);
        } catch (LockTimeoutException) {
            abort(429, 'Принимаем много ответов одновременно. Повторите через несколько секунд.', ['Retry-After' => '3']);
        }
    }
}
