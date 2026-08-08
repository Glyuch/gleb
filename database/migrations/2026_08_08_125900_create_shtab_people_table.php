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
        Schema::create('shtab_people', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('initials', 8);
            $table->string('class'); // роль-класс: Аналитик, Маркетолог, Разраб…
            $table->string('color', 7)->default('#64748B');
            $table->boolean('is_direct')->default(true);
            $table->foreignId('manager_id')->nullable()->constrained('shtab_people')->nullOnDelete();
            $table->boolean('is_me')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shtab_people');
    }
};
