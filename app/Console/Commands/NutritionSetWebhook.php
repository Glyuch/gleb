<?php

namespace App\Console\Commands;

use App\Support\Nutrition\TelegramClient;
use Illuminate\Console\Command;

class NutritionSetWebhook extends Command
{
    protected $signature = 'nutrition:set-webhook';

    protected $description = 'Регистрирует вебхук Telegram-бота нутрициолога (setWebhook)';

    public function handle(): int
    {
        $token = config('nutrition.bot_token');
        $secret = config('nutrition.webhook_secret');

        if (blank($token) || blank($secret)) {
            $this->error('Не заданы nutrition.bot_token и/или nutrition.webhook_secret в .env. Заполни секреты и повтори.');

            return self::FAILURE;
        }

        $url = url('/nutrition-bot/webhook');

        $result = app(TelegramClient::class)->api('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ]);

        if ($result === null) {
            $this->error("setWebhook не удался (см. лог). URL: {$url}");

            return self::FAILURE;
        }

        $this->info("Вебхук зарегистрирован: {$url}");
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
