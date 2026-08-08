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
        Schema::create('shtab_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('object_id')->nullable()->constrained('shtab_objects')->cascadeOnDelete(); // NULL = бизнес в целом
            $table->string('name');
            $table->string('status')->default('green'); // green | yellow | red
            $table->string('value_text')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shtab_metrics');
    }
};
