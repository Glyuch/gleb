<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Аддитивно: у каждого профиля свой пояс. Дефолт Europe/Moscow сохраняет
        // текущую семантику времени (все данные — наивное локальное = московское).
        Schema::table('nutrition_profiles', function (Blueprint $table) {
            $table->string('timezone')->default('Europe/Moscow')->after('phase');
        });

        // Бэкфилл существующих строк (на случай СУБД, не проставляющей DDL-дефолт).
        DB::table('nutrition_profiles')
            ->where(function ($q) {
                $q->whereNull('timezone')->orWhere('timezone', '');
            })
            ->update(['timezone' => 'Europe/Moscow']);
    }

    public function down(): void
    {
        Schema::table('nutrition_profiles', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
