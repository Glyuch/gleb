<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('nutrition_meals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('type'); // breakfast|lunch|snack|dinner
            $table->dateTime('window_start')->nullable();
            $table->dateTime('window_end')->nullable();
            $table->dateTime('eaten_at')->nullable();
            $table->string('photo_file_id')->nullable();
            $table->text('ai_feedback')->nullable();
            $table->string('status')->default('pending'); // pending|eaten|skipped|missed
            $table->timestamps();
            $table->unique(['date', 'type']);
        });

        Schema::create('nutrition_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('type'); // weight|steps|water
            $table->decimal('value', 8, 2);
            $table->timestamps();
            $table->unique(['date', 'type']);
        });

        Schema::create('nutrition_messages', function (Blueprint $table) {
            $table->id();
            $table->string('direction'); // in|out
            $table->string('kind')->nullable(); // text|photo|command|reminder|summary|checkup|topic|...
            $table->text('content')->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('nutrition_topics', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('file_path')->nullable();
            $table->text('intro')->nullable();
            $table->unsignedInteger('position');
            $table->date('scheduled_on')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nutrition_sent_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->dateTime('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_sent_events');
        Schema::dropIfExists('nutrition_topics');
        Schema::dropIfExists('nutrition_messages');
        Schema::dropIfExists('nutrition_metrics');
        Schema::dropIfExists('nutrition_meals');
        Schema::dropIfExists('nutrition_settings');
    }
};
