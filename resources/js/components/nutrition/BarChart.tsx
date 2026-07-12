interface Bar {
    date: string;
    value: number;
}

interface BarChartProps {
    data: Bar[];
    max?: number;
    className?: string;
}

const W = 320;
const H = 120;
const PAD = 8;

/** Столбики значений (баллы по дням, 0..max). */
export default function BarChart({ data, max = 10, className }: BarChartProps) {
    if (data.length === 0) {
        return <p className="text-muted-foreground text-sm">Нет данных</p>;
    }

    const inner = W - 2 * PAD;
    const gap = data.length > 1 ? 3 : 0;
    const barW = Math.max(2, (inner - gap * (data.length - 1)) / data.length);
    const scaleH = (v: number) => Math.max(1, (Math.min(v, max) / max) * (H - 2 * PAD));

    return (
        <div className={className}>
            <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label="Баллы по дням">
                {data.map((d, i) => {
                    const h = scaleH(d.value);
                    const x = PAD + i * (barW + gap);
                    return (
                        <rect
                            key={i}
                            x={x}
                            y={H - PAD - h}
                            width={barW}
                            height={h}
                            rx={1.5}
                            className="fill-primary/80"
                        />
                    );
                })}
            </svg>
        </div>
    );
}
