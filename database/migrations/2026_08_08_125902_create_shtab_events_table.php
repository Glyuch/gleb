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
        Schema::create('shtab_events', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->foreignId('person_id')->nullable()->constrained('shtab_people')->nullOnDelete();
            $table->foreignId('object_id')->nullable()->constrained('shtab_objects')->nullOnDelete();
            $table->foreignId('metric_id')->nullable()->constrained('shtab_metrics')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shtab_events');
    }
};
