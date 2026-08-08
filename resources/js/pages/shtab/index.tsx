import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Toaster, toast } from 'sonner';
import AssignDialog from './components/assign-dialog';
import type { AssignIntent } from './components/assign-dialog';
import AssignmentDialog from './components/assignment-dialog';
import ChroniclePanel from './components/chronicle-panel';
import ChronicleTab from './components/chronicle-tab';
import MetricDialog from './components/metric-dialog';
import ObjectFormDialog from './components/object-form-dialog';
import PeopleTab from './components/people-tab';
import PersonFormDialog from './components/person-form-dialog';
import SectorCard from './components/sector-card';
import type { Board, BoardObject, BoardPerson, ChronicleEvent } from './types';
import { STATUS_DOT } from './types';

interface Props {
    board: Board;
    events: ChronicleEvent[];
}

type Tab = 'map' | 'people' | 'chronicle';

function orderSectors(objects: BoardObject[]): BoardObject[] {
    const products = [...objects].filter((o) => o.type === 'product').sort((a, b) => b.focus_level - a.focus_level);
    const ordered: BoardObject[] = [];
    const used = new Set<number>();

    for (const product of products) {
        ordered.push(product);
        used.add(product.id);

        for (const child of objects) {
            if (child.parent_id === product.id && !used.has(child.id)) {
                ordered.push(child);
                used.add(child.id);
            }
        }
    }

    for (const object of objects) {
        if (!used.has(object.id)) {
            ordered.push(object);
        }
    }

    return ordered;
}

export default function ShtabIndex({ board, events }: Props) {
    const { errors } = usePage().props;
    const [tab, setTab] = useState<Tab>('map');
    const [assignIntent, setAssignIntent] = useState<AssignIntent | null>(null);
    const [openAssignmentId, setOpenAssignmentId] = useState<number | null>(null);
    const [openMetricId, setOpenMetricId] = useState<number | null>(null);
    const [personForm, setPersonForm] = useState<{ open: boolean; person: BoardPerson | null }>({ open: false, person: null });
    const [objectForm, setObjectForm] = useState<{ open: boolean; object: BoardObject | null }>({ open: false, object: null });
    const [selectedPersonId, setSelectedPersonId] = useState<number | null>(null);
    const reserve = board.people.filter((p) => p.in_reserve);

    useEffect(() => {
        const first = Object.values(errors ?? {})[0];

        if (first) {
            toast.error(String(first));
        }
    }, [errors]);

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
                            aria-current={tab === key ? 'page' : undefined}
                            onClick={() => setTab(key)}
                            className={`cursor-pointer rounded-full px-3 py-1 text-xs font-bold ${tab === key ? 'bg-[#EDEAE0] text-gray-900' : 'text-gray-500 hover:text-gray-800'}`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
                <div className="ml-auto flex items-center gap-2">
                    {board.business_metrics.map((m) => (
                        <button
                            key={m.id}
                            type="button"
                            onClick={() => setOpenMetricId(m.id)}
                            className="flex cursor-pointer items-center gap-1 text-[10px] text-gray-500 hover:text-gray-800"
                        >
                            <span className={`h-2.5 w-2.5 rounded-full ${STATUS_DOT[m.status]}`} />
                            {m.name}
                        </button>
                    ))}
                    <button
                        type="button"
                        aria-label="Добавить бизнес-метрику"
                        title="Добавить бизнес-метрику"
                        onClick={() => {
                            const name = window.prompt('Название бизнес-метрики');

                            if (name?.trim()) {
                                router.post('/shtab/metrics', { object_id: null, name: name.trim() }, { preserveScroll: true });
                            }
                        }}
                        className="cursor-pointer rounded-full border border-dashed border-gray-400 px-1.5 py-0.5 text-[10px] font-semibold text-gray-400 transition hover:border-gray-600 hover:text-gray-600"
                    >
                        +
                    </button>
                    <button
                        type="button"
                        onClick={() => setPersonForm({ open: true, person: null })}
                        className="ml-3 cursor-pointer rounded-full border border-gray-300 px-2 py-0.5 text-[10px] font-bold text-gray-600 hover:border-gray-500 hover:text-gray-900"
                    >
                        + персонаж
                    </button>
                    <button
                        type="button"
                        onClick={() => setObjectForm({ open: true, object: null })}
                        className="cursor-pointer rounded-full border border-gray-300 px-2 py-0.5 text-[10px] font-bold text-gray-600 hover:border-gray-500 hover:text-gray-900"
                    >
                        + территория
                    </button>
                    <span className="ml-3 text-[10px] text-gray-400">Резерв:</span>
                    <div className="flex gap-1">
                        {reserve.map((p) => (
                            <span
                                key={p.id}
                                title={p.name}
                                role="img"
                                aria-label={p.name}
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
                        {orderSectors(board.objects).map((object) => (
                            <SectorCard
                                key={object.id}
                                object={object}
                                board={board}
                                onAssignClick={(objectId) => setAssignIntent({ objectId, personId: null, moveAssignmentId: null })}
                                onPersonDrop={(personId, assignmentId, objectId) =>
                                    setAssignIntent({ objectId, personId, moveAssignmentId: assignmentId })
                                }
                                onPersonClick={(assignmentId) => setOpenAssignmentId(assignmentId)}
                                onMetricClick={(metricId) => setOpenMetricId(metricId)}
                                onEditClick={(objectId) => setObjectForm({ open: true, object: board.objects.find((o) => o.id === objectId) ?? null })}
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
                    <PeopleTab
                        board={board}
                        events={events}
                        onPersonEdit={(person) => setPersonForm({ open: true, person })}
                        selectedPersonId={selectedPersonId}
                        onSelectPerson={setSelectedPersonId}
                    />
                </main>
            )}

            {tab === 'chronicle' && (
                <main className="p-5">
                    <ChronicleTab board={board} events={events} />
                </main>
            )}

            <AssignDialog
                key={`${assignIntent?.objectId ?? 'x'}-${assignIntent?.personId ?? 'x'}-${assignIntent?.moveAssignmentId ?? 'x'}`}
                intent={assignIntent}
                board={board}
                onClose={() => setAssignIntent(null)}
            />
            <AssignmentDialog key={openAssignmentId ?? 'a'} assignmentId={openAssignmentId} board={board} onClose={() => setOpenAssignmentId(null)} />
            <MetricDialog key={openMetricId ?? 'm'} metricId={openMetricId} board={board} onClose={() => setOpenMetricId(null)} />
            <PersonFormDialog
                key={personForm.person?.id ?? 'new-p'}
                open={personForm.open}
                person={personForm.person}
                board={board}
                onClose={() => setPersonForm({ open: false, person: null })}
            />
            <ObjectFormDialog
                key={objectForm.object?.id ?? 'new-o'}
                open={objectForm.open}
                object={objectForm.object}
                board={board}
                onClose={() => setObjectForm({ open: false, object: null })}
            />
            <Toaster position="bottom-right" />
        </div>
    );
}
