import { Head } from '@inertiajs/react';
import { useState } from 'react';
import ChroniclePanel from './components/chronicle-panel';
import PersonChip from './components/person-chip';
import SectorCard from './components/sector-card';
import type { Board, ChronicleEvent } from './types';
import { STATUS_DOT } from './types';

interface Props {
    board: Board;
    events: ChronicleEvent[];
}

type Tab = 'map' | 'people' | 'chronicle';

export default function ShtabIndex({ board, events }: Props) {
    const [tab, setTab] = useState<Tab>('map');
    const reserve = board.people.filter((p) => p.in_reserve);

    // Обработчики диалогов подключаются в Задаче 9; пока — заглушки.
    const noop = () => undefined;

    return (
        <div className="min-h-screen bg-[#F2F0EA]">
            <Head title="Штаб" />
            <header className="flex items-center gap-6 border-b border-[#E4E1D8] bg-white px-5 py-2.5">
                <span className="text-sm font-extrabold text-gray-900">⌘ ШТАБ</span>
                <nav className="flex gap-1">
                    {(
                        [
                            ['map', 'Карта'],
                            ['people', 'Люди'],
                            ['chronicle', 'Хроника'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setTab(key)}
                            className={`cursor-pointer rounded-full px-3 py-1 text-xs font-bold ${tab === key ? 'bg-[#EDEAE0] text-gray-900' : 'text-gray-500 hover:text-gray-800'}`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
                <div className="ml-auto flex items-center gap-2">
                    {board.business_metrics.map((m) => (
                        <span key={m.id} className="flex items-center gap-1 text-[10px] text-gray-500">
                            <span className={`h-2.5 w-2.5 rounded-full ${STATUS_DOT[m.status]}`} />
                            {m.name}
                        </span>
                    ))}
                    <span className="ml-3 text-[10px] text-gray-400">Резерв:</span>
                    <div className="flex gap-1">
                        {reserve.map((p) => (
                            <span
                                key={p.id}
                                title={p.name}
                                draggable
                                onDragStart={(e) => e.dataTransfer.setData('personId', String(p.id))}
                                className="flex h-6 w-6 cursor-grab items-center justify-center rounded-full text-[9px] font-extrabold text-white ring-1 ring-white"
                                style={{ backgroundColor: p.color }}
                            >
                                {p.initials}
                            </span>
                        ))}
                        {reserve.length === 0 && <span className="text-[10px] text-gray-300">пуст</span>}
                    </div>
                </div>
            </header>

            {tab === 'map' && (
                <main className="grid grid-cols-1 gap-4 p-5 lg:grid-cols-[1fr_300px]">
                    <div className="grid auto-rows-min grid-cols-1 gap-4 md:grid-cols-2">
                        {board.objects.map((object) => (
                            <SectorCard
                                key={object.id}
                                object={object}
                                board={board}
                                onAssignClick={noop}
                                onPersonDrop={noop}
                                onPersonClick={noop}
                                onMetricClick={noop}
                                onEditClick={noop}
                            />
                        ))}
                        {board.objects.length === 0 && (
                            <div className="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-400">
                                Территорий пока нет — добавь первый продукт или проект.
                            </div>
                        )}
                    </div>
                    <aside className="rounded-xl border border-[#E4E1D8] bg-white p-4">
                        <h2 className="mb-3 text-xs font-extrabold text-gray-900">ХРОНИКА</h2>
                        <ChroniclePanel events={events} limit={12} />
                    </aside>
                </main>
            )}

            {tab === 'people' && (
                <main className="p-5">
                    <div className="flex flex-wrap gap-3">
                        {board.people.map((p) => (
                            <PersonChip key={p.id} person={p} />
                        ))}
                    </div>
                </main>
            )}

            {tab === 'chronicle' && (
                <main className="mx-auto max-w-2xl p-5">
                    <div className="rounded-xl border border-[#E4E1D8] bg-white p-5">
                        <ChroniclePanel events={events} />
                    </div>
                </main>
            )}
        </div>
    );
}
