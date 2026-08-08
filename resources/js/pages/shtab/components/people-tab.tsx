import type { Board, BoardPerson, ChronicleEvent } from '../types';
import ChroniclePanel from './chronicle-panel';

interface Props {
    board: Board;
    events: ChronicleEvent[];
    onPersonEdit: (person: BoardPerson) => void;
    selectedPersonId: number | null;
    onSelectPerson: (id: number | null) => void;
}

function PersonCardLarge({
    person,
    onEdit,
    onSelect,
    selected,
}: {
    person: BoardPerson;
    onEdit: () => void;
    onSelect: () => void;
    selected: boolean;
}) {
    return (
        <div
            className={`w-[210px] cursor-pointer rounded-xl border bg-white p-4 shadow-sm transition hover:shadow-md ${selected ? 'border-gray-900' : 'border-[#DFE4EC]'} ${person.in_reserve ? 'border-dashed border-amber-500' : ''}`}
            onClick={onSelect}
        >
            <div className="flex flex-col items-center">
                <span
                    className="flex h-12 w-12 items-center justify-center rounded-full text-sm font-extrabold text-white"
                    style={{ backgroundColor: person.color }}
                >
                    {person.initials}
                </span>
                <span className="mt-2 text-sm font-bold text-gray-900">
                    {person.name} {person.is_me && <span className="text-[10px] text-emerald-600">· ты</span>}
                </span>
                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[9px] font-bold tracking-wide text-gray-600 uppercase">
                    {person.class} {person.is_direct ? '' : '· непрямой'}
                </span>
            </div>
            <div className="mt-3 grid grid-cols-2 gap-1.5 text-center">
                <div className="rounded-lg bg-gray-50 py-1.5">
                    <div className="text-sm font-extrabold text-gray-900">{person.focus_count}</div>
                    <div className="text-[8px] font-bold text-gray-400 uppercase">Фокусы</div>
                </div>
                <div className={`rounded-lg py-1.5 ${person.is_overloaded ? 'bg-red-50' : 'bg-orange-50'}`}>
                    <div className="text-sm font-extrabold text-gray-900">🔥{person.hot_count}</div>
                    <div className="text-[8px] font-bold text-gray-400 uppercase">Ключевых</div>
                </div>
            </div>
            <div className="mt-2 space-y-1">
                {person.assignments.map((a) => (
                    <div key={a.id} className="rounded-md bg-gray-50 px-2 py-1 text-[10px] text-gray-700">
                        {a.object_emoji} <b>{a.object_name}</b> — {a.role_label} · {a.days} дн
                    </div>
                ))}
                {person.key_tasks.map((t) => (
                    <div key={`${t.object_name ?? '—'}:${t.title}`} className="rounded-md bg-amber-50 px-2 py-1 text-[10px] text-gray-700">
                        ⭐ {t.object_emoji} <b>{t.object_name ?? '—'}</b>: {t.title}
                    </div>
                ))}
                {person.in_reserve && <div className="text-center text-[10px] font-bold text-amber-600">без фокуса!</div>}
            </div>
            <button
                type="button"
                onClick={(e) => {
                    e.stopPropagation();
                    onEdit();
                }}
                className="mt-2 w-full cursor-pointer rounded-md border border-gray-200 py-1 text-[10px] text-gray-500 hover:bg-gray-50"
            >
                Редактировать
            </button>
        </div>
    );
}

export default function PeopleTab({ board, events, onPersonEdit, selectedPersonId, onSelectPerson }: Props) {
    const direct = board.people.filter((p) => p.is_direct);
    const indirect = board.people.filter((p) => !p.is_direct);
    const personEvents = selectedPersonId ? events.filter((e) => e.person?.id === selectedPersonId) : [];

    const grid = (people: BoardPerson[]) => (
        <div className="flex flex-wrap gap-3">
            {people.map((p) => (
                <PersonCardLarge
                    key={p.id}
                    person={p}
                    selected={p.id === selectedPersonId}
                    onSelect={() => onSelectPerson(p.id === selectedPersonId ? null : p.id)}
                    onEdit={() => onPersonEdit(p)}
                />
            ))}
        </div>
    );

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_300px]">
            <div className="space-y-5">
                <div>
                    <h2 className="mb-2 text-xs font-extrabold text-gray-500 uppercase">Прямые</h2>
                    {grid(direct)}
                </div>
                {indirect.length > 0 && (
                    <div>
                        <h2 className="mb-2 text-xs font-extrabold text-gray-500 uppercase">Непрямые</h2>
                        {grid(indirect)}
                    </div>
                )}
            </div>
            <aside className="rounded-xl border border-[#E4E1D8] bg-white p-4">
                <h2 className="mb-3 text-xs font-extrabold text-gray-900">
                    {selectedPersonId ? 'ЛИЧНАЯ ХРОНИКА' : 'ХРОНИКА — выбери персонажа'}
                </h2>
                <ChroniclePanel events={selectedPersonId ? personEvents : events} limit={15} />
            </aside>
        </div>
    );
}
