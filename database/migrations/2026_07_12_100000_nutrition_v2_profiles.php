<?php

use App\Models\NutritionProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->string('name');
            $table->string('username')->nullable();
            $table->bigInteger('main_chat_id')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->string('status')->default('onboarding'); // onboarding|active|paused
            $table->string('phase')->default('program'); // program|maintenance
            $table->date('program_started_on')->nullable();
            $table->text('ai_profile')->nullable();
            $table->json('settings')->nullable();
            $table->json('awaiting')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nutrition_invites', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('created_by_profile_id')->constrained('nutrition_profiles');
            $table->foreignId('used_by_profile_id')->nullable()->constrained('nutrition_profiles');
            $table->dateTime('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('nutrition_topic_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('nutrition_profiles');
            $table->foreignId('topic_id')->constrained('nutrition_topics');
            $table->date('scheduled_on')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['profile_id', 'topic_id']);
        });

        // Аддитивные колонки на существующих таблицах. Старые unique-индексы не трогаем.
        Schema::table('nutrition_meals', function (Blueprint $table) {
            $table->foreignId('profile_id')->nullable()->constrained('nutrition_profiles');
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('rating')->nullable();
        });

        Schema::table('nutrition_metrics', function (Blueprint $table) {
            $table->foreignId('profile_id')->nullable()->constrained('nutrition_profiles');
        });

        Schema::table('nutrition_messages', function (Blueprint $table) {
            $table->foreignId('profile_id')->nullable()->constrained('nutrition_profiles');
            $table->index('profile_id');
        });

        NutritionProfile::backfillFromLegacy();
    }

    public function down(): void
    {
        Schema::table('nutrition_messages', function (Blueprint $table) {
            $table->dropForeign(['profile_id']);
            $table->dropIndex(['profile_id']);
            $table->dropColumn('profile_id');
        });

        Schema::table('nutrition_metrics', function (Blueprint $table) {
            $table->dropForeign(['profile_id']);
            $table->dropColumn('profile_id');
        });

        Schema::table('nutrition_meals', function (Blueprint $table) {
            $table->dropForeign(['profile_id']);
            $table->dropColumn(['profile_id', 'score', 'rating']);
        });

        Schema::dropIfExists('nutrition_topic_sends');
        Schema::dropIfExists('nutrition_invites');
        Schema::dropIfExists('nutrition_profiles');
    }
};
