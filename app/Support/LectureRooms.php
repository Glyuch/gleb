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
use LogicException;

/**
 * @phpstan-type Poll array{votes: array<string, int>, revealed: bool}
 * @phpstan-type Room array{code: string, ownerId: int, expiresAt: int, questionId: ?string, revision: int, open: bool, ended: bool, polls: array<string, Poll>, commands?: array<string, string>}
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
        } while (! $this->cache->add('room:'.$code, $room, Carbon::createFromTimestamp($room['expiresAt'])));

        return $room;
    }

    /** @return Room */
    public function get(string $code): array
    {
        /** @var Room|null $room */
        $room = $this->cache->get('room:'.$code);
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
        $this->cache->put('room:'.$room['code'], $room, Carbon::createFromTimestamp($room['expiresAt']));
    }

    /**
     * @param  Closure(): Room  $callback
     * @return Room
     */
    private function locked(string $code, Closure $callback): array
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
