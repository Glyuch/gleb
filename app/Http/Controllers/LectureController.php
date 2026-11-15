<?php

namespace App\Http\Controllers;

use App\Support\LectureRooms;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LectureController extends Controller
{
    private const PARTICIPANT_COOKIE = 'lecture_participant';

    public function __construct(private LectureRooms $rooms) {}

    public function show(): Response
    {
        return Inertia::render('lecture/index', [
            'isPresenter' => false,
            'questions' => $this->rooms->publicQuestions(),
            'room' => null,
            'createRoomUrl' => null,
        ]);
    }

    public function presenter(Request $request): Response
    {
        $room = null;
        $code = $request->session()->get('lecture_room');
        if (is_string($code)) {
            try {
                $stored = $this->rooms->get($code);
                if (! $stored['ended'] && $stored['ownerId'] === (int) $request->user()->id) {
                    $room = $this->rooms->state($stored, null, $request->user());
                }
            } catch (NotFoundHttpException) {
                $request->session()->forget('lecture_room');
            }
        }

        return Inertia::render('lecture/index', [
            'isPresenter' => true,
            'questions' => $this->rooms->publicQuestions(),
            'room' => $room,
            'createRoomUrl' => route('lecture.rooms.store'),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->rooms->limit('create:'.$request->user()->id, 10);
        $room = $this->rooms->create($request->user());
        $request->session()->put('lecture_room', $room['code']);

        return $this->json($this->rooms->state($room, null, $request->user()), 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['question', 'open', 'close', 'reveal', 'end'])],
            'questionId' => ['required_if:action,question', 'nullable', Rule::in(array_keys($this->rooms->questions()))],
        ]);
        $this->rooms->limit('manage:'.$request->user()->id, 120);
        $room = $this->rooms->change($code, $request->user(), $data['action'], $data['questionId'] ?? null);

        return $this->json($this->rooms->state($room, null, $request->user()));
    }

    public function join(Request $request, string $code): Response
    {
        $this->rooms->limit('join:'.hash('sha256', (string) $request->ip()), 1500);
        $room = $this->rooms->get($code);
        $participant = $this->participant($request) ?? $this->rooms->newParticipant();
        Cookie::queue(Cookie::make(
            name: self::PARTICIPANT_COOKIE,
            value: $participant,
            minutes: 60 * 12,
            path: '/lecture',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));

        return Inertia::render('lecture/join', ['room' => $this->rooms->state($room, $participant)]);
    }

    public function state(Request $request, string $code): JsonResponse
    {
        $participant = $this->participant($request);
        if ($participant !== null) {
            $this->rooms->limit('state:'.hash('sha256', $participant), 120);
        } else {
            $this->rooms->limit('state-ip:'.hash('sha256', (string) $request->ip()), 20000);
        }

        return $this->json($this->rooms->state($this->rooms->get($code), $participant, $request->user()));
    }

    public function vote(Request $request, string $code): JsonResponse
    {
        $participant = $this->participant($request);
        abort_if($participant === null, 403, 'Сначала откройте ссылку подключения и разрешите cookie.');
        $this->rooms->limit('vote:'.hash('sha256', $participant), 12);
        $this->rooms->limit('vote-ip:'.hash('sha256', (string) $request->ip()), 3000);
        $data = $request->validate([
            'questionId' => ['required', 'string', Rule::in(array_keys($this->rooms->questions()))],
            'revision' => ['required', 'integer', 'min:0'],
            'option' => ['required', 'integer', 'min:0', 'max:3'],
        ]);
        $room = $this->rooms->vote($code, $participant, $data['questionId'], (int) $data['revision'], (int) $data['option']);

        return $this->json($this->rooms->state($room, $participant));
    }

    public function qr(string $code): HttpResponse
    {
        $this->rooms->get($code);
        $writer = new Writer(new ImageRenderer(new RendererStyle(480, 4), new SvgImageBackEnd));

        return response($writer->writeString(route('lecture.join', ['code' => $code])), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function participant(Request $request): ?string
    {
        $cookie = $request->cookie(self::PARTICIPANT_COOKIE);

        return $this->rooms->participant(is_string($cookie) ? $cookie : null);
    }

    /** @param array<string, mixed> $state */
    private function json(array $state, int $status = 200): JsonResponse
    {
        return response()->json($state, $status, ['Cache-Control' => 'private, no-store']);
    }
}
