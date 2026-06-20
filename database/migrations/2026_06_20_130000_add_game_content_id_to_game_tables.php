<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_results', function (Blueprint $table) {
            $table->foreignId('game_content_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('game_events', function (Blueprint $table) {
            $table->foreignId('game_content_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('game_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('game_content_id');
        });

        Schema::table('game_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('game_content_id');
        });
    }
};
