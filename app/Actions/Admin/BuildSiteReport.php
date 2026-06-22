<?php

namespace App\Actions\Admin;

use App\Models\GameEvent;
use App\Models\GameResult;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Site-wide admin metrics: users, registrations over time, active sessions,
 * and a per-project breakdown (the game is project #1). Read-only.
 */
class BuildSiteReport
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $now = Carbon::now();
        $since = $now->copy()->subDays(29)->startOfDay();

        $byDay = User::query()
            ->where('created_at', '>=', $since)
            ->get(['created_at'])
            ->groupBy(fn ($u) => $u->created_at->format('Y-m-d'))
            ->map->count();

        $regLabels = [];
        $regCounts = [];
        for ($d = 0; $d < 30; $d++) {
            $day = $since->copy()->addDays($d);
            $regLabels[] = $day->format('d.m');
            $regCounts[] = (int) ($byDay[$day->format('Y-m-d')] ?? 0);
        }

        return [
            'users_total' => User::count(),
            'users_verified' => User::whereNotNull('email_verified_at')->count(),
            'users_admin' => User::where('is_admin', true)->count(),
            'reg_labels' => $regLabels,
            'reg_counts' => $regCounts,
            'reg_today' => User::where('created_at', '>=', $now->copy()->startOfDay())->count(),
            'reg_7d' => User::where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'reg_30d' => User::where('created_at', '>=', $now->copy()->subDays(30))->count(),
            'sessions_active' => $this->sessions($now->copy()->subMinutes(15)->timestamp),
            'sessions_24h' => $this->sessions($now->copy()->subDay()->timestamp),
            'projects' => [
                [
                    'key' => 'game',
                    'title' => 'ФондыКвест',
                    'href' => Route::has('admin.dashboards.gameresults') ? route('admin.dashboards.gameresults') : '#',
                    'players' => GameResult::query()->distinct()->count('user_id'),
                    'games' => GameResult::count(),
                    'events' => GameEvent::count(),
                ],
            ],
        ];
    }

    private function sessions(int $sinceTs): int
    {
        if (! DB::getSchemaBuilder()->hasTable('sessions')) {
            return 0;
        }

        return DB::table('sessions')->where('last_activity', '>=', $sinceTs)->count();
    }
}
