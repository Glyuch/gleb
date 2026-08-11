<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shtab_assignments', function (Blueprint $table): void {
            // Тип участия: owner | lead | helper | watcher (см. config('shtab.roles')).
            $table->string('role_type', 20)->default('owner')->after('role_label');
            // Доля вовлечённости в процентах от рабочего времени — база расчёта нагрузки.
            $table->unsignedTinyInteger('load_percent')->default(50)->after('role_type');
        });

        // Разносим уже заполненные текстовые роли по типам, чтобы старые данные
        // сразу попали в разрезы и в расчёт нагрузки.
        $map = [
            'owner' => ['владел'],
            'lead' => ['вед', 'ответств'],
            'watcher' => ['контрол', 'следит', 'наблюд'],
        ];

        foreach (DB::table('shtab_assignments')->select('id', 'role_label')->get() as $row) {
            $label = mb_strtolower((string) $row->role_label);
            $type = 'helper';

            foreach ($map as $candidate => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($label, $needle)) {
                        $type = $candidate;

                        break 2;
                    }
                }
            }

            DB::table('shtab_assignments')->where('id', $row->id)->update([
                'role_type' => $type,
                'load_percent' => ['owner' => 50, 'lead' => 40, 'helper' => 25, 'watcher' => 10][$type],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shtab_assignments', function (Blueprint $table): void {
            $table->dropColumn(['role_type', 'load_percent']);
        });
    }
};
