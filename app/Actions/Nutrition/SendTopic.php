<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionProfile;
use App\Models\NutritionTopic;
use App\Support\Nutrition\TelegramClient;
use Carbon\CarbonImmutable;

class SendTopic
{
    /**
     * Отправляет материалы темы документами. file_path может содержать несколько
     * имён файлов через «|» — отправляется каждый существующий файл (intro —
     * caption только у первого; отсутствующие файлы пропускаются). Если ни одного
     * файла нет на диске — intro уходит текстом. sent_at проставляется в любом случае.
     */
    public function handle(NutritionProfile $profile, NutritionTopic $topic): void
    {
        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;

        $paths = collect(explode('|', (string) $topic->file_path))
            ->filter(fn (string $name): bool => $name !== '')
            ->map(fn (string $name): string => storage_path('app/nutrition/materials/'.$name))
            ->filter(fn (string $path): bool => is_file($path))
            ->values();

        if ($paths->isEmpty()) {
            $tg->send((string) $topic->intro, null, 'topic');
        } else {
            foreach ($paths as $i => $path) {
                $tg->sendDocument($path, $i === 0 ? $topic->intro : null);
            }
        }

        $topic->update(['sent_at' => CarbonImmutable::now('Europe/Moscow')]);
    }
}
