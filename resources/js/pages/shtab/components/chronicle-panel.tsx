import type { ChronicleEvent } from '../types';

const TYPE_META: Record<string, { dot: string; label: (e: ChronicleEvent) => string }> = {
    assignment_started: {
        dot: 'bg-emerald-500',
        label: (e) => `${e.person?.name ?? '—'} → ${e.object?.name ?? '—'}, ${String(e.payload?.role_label ?? '')}`,
    },
    assignment_ended: {
        dot: 'bg-slate-400',
        label: (e) => `${e.person?.name ?? '—'} снят с ${e.object?.name ?? '—'} (${String(e.payload?.days ?? '?')} дн)`,
    },
    metric_status_changed: {
        dot: 'bg-red-500',
        label: (e) => `${e.metric?.name ?? '—'}: ${String(e.payload?.from ?? '?')} → ${String(e.payload?.to ?? '?')}`,
    },
    focus_level_changed: {
        dot: 'bg-orange-500',
        label: (e) => `Фокус ${e.object?.name ?? '—'}: ${String(e.payload?.from ?? '?')} → ${String(e.payload?.to ?? '?')}`,
    },
    task_done: {
        dot: 'bg-emerald-500',
        label: (e) => `✅ Задача закрыта: „${String(e.payload?.title ?? '?')}" — ${e.object?.name ?? '—'}`,
    },
    task_assigned: {
        dot: 'bg-blue-400',
        label: (e) => `${e.person?.name ?? '—'} ← задача „${String(e.payload?.title ?? '?')}"`,
    },
    person_created: { dot: 'bg-blue-400', label: (e) => `Добавлен ${e.person?.name ?? '—'}` },
    person_archived: { dot: 'bg-slate-400', label: (e) => `В архив: ${e.person?.name ?? '—'}` },
    object_created: { dot: 'bg-blue-400', label: (e) => `Новая территория: ${e.object?.name ?? '—'}` },
    object_archived: { dot: 'bg-slate-400', label: (e) => `Территория в архиве: ${e.object?.name ?? '—'}` },
    ai_digest: {
        dot: 'bg-violet-500',
        label: (e) => {
            const unparsed = e.payload?.unparsed;
            const unparsedCount = Array.isArray(unparsed) ? unparsed.length : 0;

            return `🤖 ИИ-разбор: применено ${String(e.payload?.applied ?? '?')}, не разобрано ${String(unparsedCount)}`;
        },
    },
};

function formatWhen(iso: string): string {
    const d = new Date(iso);
    const days = Math.floor((Date.now() - d.getTime()) / 86_400_000);

    if (days === 0) {
        return `сегодня, ${d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`;
    }

    if (days === 1) {
        return 'вчера';
    }

    return `${d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })} · ${days} дн назад`;
}

export default function ChroniclePanel({ events, limit }: { events: ChronicleEvent[]; limit?: number }) {
    const shown = limit ? events.slice(0, limit) : events;

    return (
        <div className="space-y-3">
            {shown.length === 0 && <p className="text-xs text-gray-400">Пока пусто — первое назначение появится здесь.</p>}
            {shown.map((e) => {
                const meta = TYPE_META[e.type] ?? { dot: 'bg-gray-300', label: () => e.type };

                return (
                    <div key={e.id} className="flex gap-2">
                        <span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${meta.dot}`} />
                        <div>
                            <p className="text-xs font-semibold text-gray-700">{meta.label(e)}</p>
                            <p className="text-[10px] text-gray-400">
                                {formatWhen(e.created_at)}
                                {e.comment && <span> · «{e.comment}»</span>}
                            </p>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
