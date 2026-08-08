<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shtab_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('object_id')->constrained('shtab_objects')->cascadeOnDelete();
            $table->string('title', 500);
            $table->boolean('is_done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('assignee_person_id')->nullable()->constrained('shtab_people')->nullOnDelete();
            $table->boolean('is_key')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['object_id', 'is_done']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shtab_tasks');
    }
};
