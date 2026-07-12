interface StreakProps {
    days: number;
    className?: string;
}

/** Компактный бейдж стрика дней без пропусков. */
export default function Streak({ days, className }: StreakProps) {
    return (
        <div className={className}>
            <div className="flex items-baseline gap-1">
                <span className="text-2xl font-semibold tabular-nums">{days}</span>
                <span aria-hidden>🔥</span>
            </div>
            <p className="text-muted-foreground text-xs">
                {days === 0 ? 'нет серии' : 'дней без пропусков'}
            </p>
        </div>
    );
}
