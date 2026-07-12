interface Point {
    date: string;
    value: number;
}

interface LineChartProps {
    data: Point[];
    unit?: string;
    className?: string;
}

const W = 320;
const H = 120;
const PAD = 8;

/** Простая линия по точкам без библиотек. Значения подписаны у min/max. */
export default function LineChart({ data, unit = '', className }: LineChartProps) {
    if (data.length === 0) {
        return <p className="text-muted-foreground text-sm">Нет данных</p>;
    }

    const values = data.map((d) => d.value);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;

    const x = (i: number) => (data.length === 1 ? W / 2 : PAD + (i * (W - 2 * PAD)) / (data.length - 1));
    const y = (v: number) => H - PAD - ((v - min) / span) * (H - 2 * PAD);

    const points = data.map((d, i) => `${x(i)},${y(d.value)}`).join(' ');

    return (
        <div className={className}>
            <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label="График веса">
                {data.length > 1 && (
                    <polyline
                        points={points}
                        fill="none"
                        className="stroke-primary"
                        strokeWidth={2}
                        strokeLinejoin="round"
                        strokeLinecap="round"
                    />
                )}
                {data.map((d, i) => (
                    <circle key={i} cx={x(i)} cy={y(d.value)} r={2.5} className="fill-primary" />
                ))}
                <text x={PAD} y={y(max) - 4} className="fill-muted-foreground text-[9px]">
                    {max}
                    {unit}
                </text>
                <text x={PAD} y={y(min) + 10} className="fill-muted-foreground text-[9px]">
                    {min}
                    {unit}
                </text>
            </svg>
        </div>
    );
}
