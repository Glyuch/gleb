<?php

use App\Models\NutritionProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Осиротевшие строки (profile_id IS NULL), записанные старым кодом до
        // переключения на профиль-осознанную обработку, привязываем к admin-профилю.
        // Иначе после снятия [date,type] они станут дублями к профильным строкам.
        $admin = NutritionProfile::admin();
        if ($admin !== null) {
            DB::table('nutrition_meals')->whereNull('profile_id')->update(['profile_id' => $admin->id]);
            DB::table('nutrition_metrics')->whereNull('profile_id')->update(['profile_id' => $admin->id]);
        }

        Schema::table('nutrition_meals', function (Blueprint $table) {
            $table->dropUnique(['date', 'type']);
            $table->unique(['profile_id', 'date', 'type']);
        });

        Schema::table('nutrition_metrics', function (Blueprint $table) {
            $table->dropUnique(['date', 'type']);
            $table->unique(['profile_id', 'date', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_meals', function (Blueprint $table) {
            $table->dropUnique(['profile_id', 'date', 'type']);
            $table->unique(['date', 'type']);
        });

        Schema::table('nutrition_metrics', function (Blueprint $table) {
            $table->dropUnique(['profile_id', 'date', 'type']);
            $table->unique(['date', 'type']);
        });
    }
};
