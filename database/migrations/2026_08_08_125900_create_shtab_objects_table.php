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
        Schema::create('shtab_objects', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // product | project | enabler
            $table->foreignId('parent_id')->nullable()->constrained('shtab_objects')->nullOnDelete();
            $table->string('name');
            $table->string('emoji', 16)->nullable();
            $table->unsignedTinyInteger('focus_level')->default(0); // 0 фоновый | 1 🔥 | 2 🔥🔥
            $table->string('color', 7)->default('#5B6EE8');
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
        Schema::dropIfExists('shtab_objects');
    }
};
