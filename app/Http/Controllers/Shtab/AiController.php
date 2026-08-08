<?php

namespace App\Http\Controllers\Shtab;

use App\Actions\Shtab\BuildShtabBoard;
use App\Http\Controllers\Controller;
use App\Support\Shtab\ApplyOperations;
use App\Support\Shtab\ClaudeDigest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AiController extends Controller
{
    /**
     * «Рассказать штабу»: свободный текст → предложенные операции + unparsed.
     * Ничего не применяет — только превью; ошибка ИИ → честный 503 для тоста.
     */
    public function digest(Request $request, BuildShtabBoard $board): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:8000'],
        ]);

        try {
            $result = ClaudeDigest::propose($data['text'], $board->handle());
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json($result);
    }

    /**
     * Применяет выбранные Глебом операции; ответ — applied/failed для экрана итогов.
     */
    public function apply(Request $request, ApplyOperations $applier): JsonResponse
    {
        $data = $request->validate([
            'operations' => ['present', 'array'],
            'operations.*' => ['array'],
            'unparsed' => ['sometimes', 'array'],
            'unparsed.*' => ['array'],
            'text' => ['sometimes', 'nullable', 'string', 'max:8000'],
        ]);

        /** @var array<int, array<string, mixed>> $operations */
        $operations = $data['operations'];

        /** @var array<int, array<string, mixed>> $unparsed */
        $unparsed = $data['unparsed'] ?? [];

        $text = isset($data['text']) && is_string($data['text']) ? $data['text'] : null;

        return response()->json($applier->apply($operations, $unparsed, $text));
    }
}
