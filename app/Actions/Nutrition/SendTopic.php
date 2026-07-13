<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionProfile;
use App\Models\NutritionTopicSend;
use App\Support\Nutrition\TelegramClient;

class SendTopic
{
    /**
     * Отправляет материалы темы документами в чат профиля и помечает
     * per-profile строку выдачи (NutritionTopicSend) отправленной.
     *
     * file_path темы может содержать несколько имён файлов через «|» —
     * отправляется каждый существующий файл (intro — caption только у первого;
     * отсутствующие файлы пропускаются). Если ни одного файла нет на диске —
     * intro уходит текстом. sent_at на строке выдачи проставляется в любом случае.
     */
    public function handle(NutritionProfile $profile, NutritionTopicSend $send, ?int $chatId = null): void
    {
        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;

        $topic = $send->topic;

        $paths = collect(explode('|', (string) $topic?->file_path))
            ->filter(fn (string $name): bool => $name !== '')
            ->map(fn (string $name): string => storage_path('app/nutrition/materials/'.$name))
            ->filter(fn (string $path): bool => is_file($path))
            ->values();

        if ($paths->isEmpty()) {
            $tg->send((string) $topic?->intro, null, 'topic', $chatId);
        } else {
            foreach ($paths as $i => $path) {
                $tg->sendDocument($path, $i === 0 ? $topic?->intro : null, $chatId);
            }
        }

        $send->update(['sent_at' => $profile->now()]);
    }
}
