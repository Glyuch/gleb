import { Head } from '@inertiajs/react';
import BarChart from '@/components/nutrition/BarChart';
import LineChart from '@/components/nutrition/LineChart';
import Streak from '@/components/nutrition/Streak';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface Weight {
    date: string;
    value: number;
}
interface Score {
    date: string;
    avg: number;
    count: number;
}
interface Adherence {
    date: string;
    eaten: number;
    missed: number;
    skipped: number;
}
interface RecentMeal {
    date: string;
    type: string;
    label: string;
    time: string | null;
    score: number | null;
    forbidden: string[];
    window_ok: boolean | null;
    interval_ok: boolean | null;
}

interface StatsProps {
    profile: { name: string; phase: string; day: number };
    weights: Weight[];
    scores: Score[];
    adherence: Adherence[];
    steps: { date: string; value: number; target: number }[];
    water: { date: string; value: number }[];
    recentMeals: RecentMeal[];
}

const PHASE_LABELS: Record<string, string> = {
    program: 'Программа',
    maintenance: 'Поддержка',
};

/** Число с одним знаком, без хвостовых нулей: 82.3, 2, -1.5. */
function num(value: number): string {
    return String(Math.round(value * 10) / 10);
}

function shortDate(iso: string): string {
    const [, m, d] = iso.split('-');
    return `${d}.${m}`;
}

/** Стрик самых свежих подряд дней без пропущенных приёмов. */
function computeStreak(adherence: Adherence[]): number {
    let streak = 0;
    for (let i = adherence.length - 1; i >= 0; i--) {
        if (adherence[i].missed > 0) break;
        streak++;
    }
    return streak;
}

export default function Stats({ profile, weights, scores, adherence, steps, recentMeals }: StatsProps) {
    const currentWeight = weights.length > 0 ? weights[weights.length - 1].value : null;
    const weightDelta = weights.length > 1 ? weights[weights.length - 1].value - weights[0].value : null;

    const last7Scores = scores.slice(-7);
    const scoreCount = last7Scores.reduce((s, x) => s + x.count, 0);
    const avgScore7d =
        scoreCount > 0 ? last7Scores.reduce((s, x) => s + x.avg * x.count, 0) / scoreCount : null;

    const last7Steps = steps.slice(-7);
    const avgSteps7d =
        last7Steps.length > 0
            ? Math.round(last7Steps.reduce((s, x) => s + x.value, 0) / last7Steps.length)
            : null;
    const stepsTarget = steps.length > 0 ? steps[steps.length - 1].target : 0;

    const streak = computeStreak(adherence);
    const phaseLabel = PHASE_LABELS[profile.phase] ?? profile.phase;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <Head title={`Статистика — ${profile.name}`} />

            <div className="mx-auto flex max-w-xl flex-col gap-4 p-4">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Статистика — {profile.name}</h1>
                    <p className="text-muted-foreground text-sm">
                        {phaseLabel}
                        {profile.day > 0 ? ` · день ${profile.day}` : ''}
                    </p>
                </header>

                <div className="grid grid-cols-2 gap-3">
                    <Card>
                        <CardHeader className="pb-1">
                            <CardTitle className="text-muted-foreground text-xs font-medium">Вес</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold tabular-nums">
                                {currentWeight !== null ? `${num(currentWeight)} кг` : '—'}
                            </div>
                            {weightDelta !== null && (
                                <p className="text-muted-foreground text-xs">
                                    {weightDelta > 0 ? '+' : ''}
                                    {num(weightDelta)} кг за период
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-1">
                            <CardTitle className="text-muted-foreground text-xs font-medium">
                                Средний балл, 7 дней
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold tabular-nums">
                                {avgScore7d !== null ? num(avgScore7d) : '—'}
                            </div>
                            <p className="text-muted-foreground text-xs">из 10</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-1">
                            <CardTitle className="text-muted-foreground text-xs font-medium">Серия</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Streak days={streak} />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-1">
                            <CardTitle className="text-muted-foreground text-xs font-medium">
                                Шаги, 7 дней
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold tabular-nums">
                                {avgSteps7d !== null ? avgSteps7d.toLocaleString('ru-RU') : '—'}
                            </div>
                            {stepsTarget > 0 && (
                                <p className="text-muted-foreground text-xs">
                                    цель {stepsTarget.toLocaleString('ru-RU')}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Вес</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <LineChart data={weights} unit=" кг" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Баллы по дням</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BarChart data={scores.map((s) => ({ date: s.date, value: s.avg }))} max={10} />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Последние приёмы</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {recentMeals.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Нет данных</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-b text-left text-xs">
                                            <th className="py-1.5 pr-2 font-medium">Дата</th>
                                            <th className="py-1.5 pr-2 font-medium">Приём</th>
                                            <th className="py-1.5 pr-2 font-medium">Время</th>
                                            <th className="py-1.5 pr-2 text-right font-medium">Балл</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recentMeals.map((m, i) => (
                                            <tr key={i} className="border-b last:border-0">
                                                <td className="py-1.5 pr-2 whitespace-nowrap tabular-nums">
                                                    {shortDate(m.date)}
                                                </td>
                                                <td className="py-1.5 pr-2">
                                                    {m.label}
                                                    {m.forbidden.length > 0 && (
                                                        <span className="text-destructive ml-1" title={m.forbidden.join(', ')}>
                                                            ⚠
                                                        </span>
                                                    )}
                                                    {m.window_ok === false && (
                                                        <span className="text-muted-foreground ml-1" title="вне окна">
                                                            ⏰
                                                        </span>
                                                    )}
                                                    {m.interval_ok === false && (
                                                        <span className="text-muted-foreground ml-1" title="короткий интервал">
                                                            ⏱
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-1.5 pr-2 whitespace-nowrap tabular-nums">
                                                    {m.time ?? '—'}
                                                </td>
                                                <td className="py-1.5 pr-2 text-right tabular-nums">
                                                    {m.score ?? '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
