<?php

namespace Database\Seeders;

use App\Models\GameContent;
use Illuminate\Database\Seeder;

class GameContentSeeder extends Seeder
{
    /**
     * Seed the initial active game content (DCA model: 5 instruments incl. mix,
     * per-quarter returns, contribution-based scenario). Content lives in
     * database/seeders/game_content.json so it stays in sync with admin edits when re-exported.
     * Idempotent: skips if an active version already exists, so it never clobbers admin edits.
     */
    public function run(): void
    {
        if (GameContent::current() !== null) {
            $this->command->info('Game content already present — skipping seed.');

            return;
        }

        GameContent::publish($this->defaultContent());
        $this->command->info('Seeded initial game content.');
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultContent(): array
    {
        $json = file_get_contents(__DIR__.'/game_content.json');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json ?: '{}', true);

        return $decoded;
    }
}
