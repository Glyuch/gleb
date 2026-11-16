<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecture_rooms', function (Blueprint $table) {
            $table->char('code', 6)->primary();
            $table->unsignedBigInteger('owner_id')->index();
            $table->json('state');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecture_rooms');
    }
};
