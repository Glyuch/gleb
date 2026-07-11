<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessNutritionUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NutritionBotController extends Controller
{
    public function webhook(Request $request): JsonResponse
    {
        abort_unless(
            hash_equals((string) config('nutrition.webhook_secret'), (string) $request->header('X-Telegram-Bot-Api-Secret-Token')),
            403
        );

        ProcessNutritionUpdate::dispatch($request->all());

        return response()->json(['ok' => true]);
    }
}
