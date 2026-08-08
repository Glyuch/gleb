<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shtab_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('shtab_people')->cascadeOnDelete();
            $table->foreignId('object_id')->constrained('shtab_objects')->cascadeOnDelete();
            $table->string('role_label'); // «владелец», «аналитика», …
            $table->text('comment')->nullable();
            $table->date('started_at');
            $table->date('ended_at')->nullable(); // NULL = активно
            $table->timestamps();
            $table->index(['person_id', 'ended_at']);
            $table->index(['object_id', 'ended_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shtab_assignments');
    }
};
