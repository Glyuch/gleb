<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Раскладка/выдача тем переведена на per-profile nutrition_topic_sends
     * (Task 3). Глобальные колонки nutrition_topics.scheduled_on/sent_at больше
     * не читаются — дропаем.
     */
    public function up(): void
    {
        Schema::table('nutrition_topics', function (Blueprint $table) {
            $table->dropColumn(['scheduled_on', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_topics', function (Blueprint $table) {
            $table->date('scheduled_on')->nullable();
            $table->dateTime('sent_at')->nullable();
        });
    }
};
