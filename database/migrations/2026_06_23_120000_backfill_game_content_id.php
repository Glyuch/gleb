<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * game_content_id was added nullable with no backfill, so rows created before that
     * migration are NULL. The dashboard now scopes analytics to the active scenario
     * version, so stamp those legacy rows with the current active version — they were
     * all played under it — to keep them in the report.
     */
    public function up(): void
    {
        $activeId = DB::table('game_contents')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->value('id');

        if ($activeId === null) {
            return;
        }

        DB::table('game_results')->whereNull('game_content_id')->update(['game_content_id' => $activeId]);
        DB::table('game_events')->whereNull('game_content_id')->update(['game_content_id' => $activeId]);
    }

    public function down(): void
    {
        // Irreversible: backfilled rows are indistinguishable from natively-stamped ones.
    }
};
