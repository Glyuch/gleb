<?php

namespace App\Actions\Shtab;

class BuildShtabBoard
{
    /**
     * @return array{people: array<int, mixed>, objects: array<int, mixed>, business_metrics: array<int, mixed>}
     */
    public function handle(): array
    {
        return [
            'people' => [],
            'objects' => [],
            'business_metrics' => [],
        ];
    }
}
