import { useState } from 'react';
import type { Board, ChronicleEvent } from '../types';
import ChroniclePanel from './chronicle-panel';

type Filter = 'all' | 'assignments' | 'metrics';

export default function ChronicleTab({ board, events }: { board: Board; events: ChronicleEvent[] }) {
    const [filter, setFilter] = useState<Filter>('all');
    const [personId, setPersonId] = useState<number | null>(null);
    const [objectId, setObjectId] = useState<number | null>(null);

    const filtered = events.filter((e) => {
        if (filter === 'assignments' && !e.type.startsWith('assignment_')) {
            return false;
        }

        if (filter === 'metrics' && e.type !== 'metric_status_changed') {
            return false;
        }

        if (personId && e.person?.id !== personId) {
            return false;
        }

        if (objectId && e.object?.id !== objectId) {
            return false;
        }

        return true;
    });

    return (
        <div className="mx-auto max-w-2xl">
            <div className="mb-3 flex flex-wrap items-center gap-2">
                {(
                    [
                        ['all', 'все'],
                        ['assignments', 'назначения'],
                        ['metrics', 'метрики'],
                    ] as const
                ).map(([value, label]) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => setFilter(value)}
                        className={`cursor-pointer rounded-full px-3 py-1 text-xs font-bold ${filter === value ? 'bg-gray-900 text-white' : 'bg-white text-gray-600'}`}
                    >
                        {label}
                    </button>
                ))}
                <select
                    className="rounded-full border border-gray-300 bg-white px-2 py-1 text-xs"
                    value={personId ?? ''}
                    onChange={(e) => setPersonId(e.target.value ? Number(e.target.value) : null)}
                >
                    <option value="">любой персонаж</option>
                    {board.people.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name}
                        </option>
                    ))}
                </select>
                <select
                    className="rounded-full border border-gray-300 bg-white px-2 py-1 text-xs"
                    value={objectId ?? ''}
                    onChange={(e) => setObjectId(e.target.value ? Number(e.target.value) : null)}
                >
                    <option value="">любая территория</option>
                    {board.objects.map((o) => (
                        <option key={o.id} value={o.id}>
                            {o.emoji} {o.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="rounded-xl border border-[#E4E1D8] bg-white p-5">
                <ChroniclePanel events={filtered} />
            </div>
        </div>
    );
}
