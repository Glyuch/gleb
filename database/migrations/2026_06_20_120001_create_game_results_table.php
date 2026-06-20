<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('score_you');
            $table->integer('score_bank');
            $table->integer('score_max');
            $table->unsignedSmallInteger('ratio')->default(0);
            $table->json('choices')->nullable();
            $table->json('survey_answers')->nullable();
            $table->string('promo_code')->default('GAME1');
            $table->timestamps();

            $table->index(['user_id', 'score_you']);
            $table->index('score_you');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_results');
    }
};
