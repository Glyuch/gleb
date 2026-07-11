<?php

namespace App\Console\Commands;

use App\Actions\Nutrition\StartProgram;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class NutritionStart extends Command
{
    protected $signature = 'nutrition:start-program {date? : Дата старта (Y-m-d, Europe/Moscow); по умолчанию сегодня}';

    protected $description = 'Стартует программу нутрициолога: фиксирует дату старта и раскладывает даты выдачи тем';

    public function handle(): int
    {
        $date = $this->argument('date');

        // Дефолт «сегодня, Europe/Moscow» живёт в самом Action.
        $summary = app(StartProgram::class)->handle(
            $date !== null ? CarbonImmutable::parse((string) $date, 'Europe/Moscow') : null,
        );

        $this->info($summary);

        return self::SUCCESS;
    }
}
