<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionTopic;
use App\Support\Nutrition\TelegramClient;
use Carbon\CarbonImmutable;

class SendTopic
{
    /**
     * Отправляет материал темы документом (если файл есть на диске) либо только intro текстом.
     * sent_at проставляется в любом случае.
     */
    public function handle(NutritionTopic $topic): void
    {
        $tg = app(TelegramClient::class);

        $path = $topic->file_path !== null
            ? storage_path('app/nutrition/materials/'.$topic->file_path)
            : null;

        if ($path !== null && is_file($path)) {
            $tg->sendDocument($path, $topic->intro);
        } else {
            $tg->send((string) $topic->intro, null, 'topic');
        }

        $topic->update(['sent_at' => CarbonImmutable::now('Europe/Moscow')]);
    }
}
