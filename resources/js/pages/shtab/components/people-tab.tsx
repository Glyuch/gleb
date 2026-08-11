import { useState } from 'react';
import type { Board, BoardPerson, ChronicleEvent, LoadStatus } from '../types';
import { FIRE, LOAD_TONE, ROLE_ICON } from '../types';
import ChroniclePanel from './chronicle-panel';

interface Props {
    board: Board;
    events: ChronicleEvent[];
    onPersonEdit: (person: BoardPerson) => void;
    selectedPersonId: number | null;
    onSelectPerson: (id: number | null) => void;
}

type PeopleGroup = 'load' | 'class' | 'direct';

const GROUPS: Array<[PeopleGroup, string]> = [
    ['load', 'По нагрузке'],
    ['class', 'По роли-классу'],
    ['direct', 'Прямые / смежники'],
];

const LOAD_TITLE: Record<LoadStatus, string> = {
    over: 'Перегруз',
    full: 'Под завязку',
    ok: 'В работе',
    free: 'Есть запас',
};

function LoadBar({
    person,
    capacity,
}: {
    person: BoardPerson;
    capacity: number;
}) {
    const tone = LOAD_TONE[person.load_status];

    return (
        <div className="mt-2">
            <div className="flex items-baseline justify-between text-[9px] font-bold text-gray-400 uppercase">
                <span>Нагрузка</span>
                <span className={tone.text}>
                    {person.load_percent}% / {capacity}%
                </span>
            </div>
            <div className="mt-1 h-2 overflow-hidden rounded-full bg-gray-200">
                <div
                    className={`h-full ${tone.bar}`}
                    style={{
                        width: `${Math.min(100, (person.load_percent / capacity) * 100)}%`,
                    }}
                />
            </div>
        </div>
    );
}

function PersonCardLarge({
    person,
    capacity,
    onEdit,
    onSelect,
    selected,
}: {
    person: BoardPerson;
    capacity: number;
    onEdit: () => void;
    onSelect: () => void;
    selected: boolean;
}) {
    return (
        <div
            className={`w-[230px] cursor-pointer rounded-xl border bg-white p-4 shadow-sm transition hover:shadow-md ${selected ? 'border-gray-900' : 'border-[#DFE4EC]'} ${person.in_reserve ? 'border-dashed border-amber-500' : ''}`}
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
                    {person.name}{' '}
                    {person.is_me && (
                        <span className="text-[10px] text-emerald-600">
                            · ты
                        </span>
                    )}
                </span>
                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[9px] font-bold tracking-wide text-gray-600 uppercase">
                    {person.class} {person.is_direct ? '' : '· непрямой'}
                </span>
            </div>

            <LoadBar person={person} capacity={capacity} />

            <div className="mt-2 grid grid-cols-3 gap-1.5 text-center">
                <div className="rounded-lg bg-gray-50 py-1.5">
                    <div className="text-sm font-extrabold text-gray-900">
                        {person.focus_count}
                    </div>
                    <div className="text-[8px] font-bold text-gray-400 uppercase">
                        Территорий
                    </div>
                </div>
                <div className="rounded-lg bg-gray-50 py-1.5">
                    <div className="text-sm font-extrabold text-gray-900">
                        {person.owner_count}
                    </div>
                    <div className="text-[8px] font-bold text-gray-400 uppercase">
                        Владеет
                    </div>
                </div>
                <div
                    className={`rounded-lg py-1.5 ${person.is_overloaded ? 'bg-red-50' : 'bg-orange-50'}`}
                >
                    <div className="text-sm font-extrabold text-gray-900">
                        🔥{person.hot_count}
                    </div>
                    <div className="text-[8px] font-bold text-gray-400 uppercase">
                        В фокусе
                    </div>
                </div>
            </div>

            <div className="mt-2 space-y-1">
                {person.assignments.map((a) => (
                    <div
                        key={a.id}
                        className="flex items-center gap-1 rounded-md bg-gray-50 px-2 py-1 text-[10px] text-gray-700"
                    >
                        <span>{ROLE_ICON[a.role_type]}</span>
                        <span className="truncate">
                            {FIRE[a.object_focus_level]} {a.object_emoji}{' '}
                            <b>{a.object_name}</b>
                        </span>
                        <span className="ml-auto shrink-0 font-extrabold text-gray-500">
                            {a.load_percent}%
                        </span>
                    </div>
                ))}
                {person.key_tasks.map((t) => (
                    <div
                        key={t.id}
                        className="rounded-md bg-amber-50 px-2 py-1 text-[10px] text-gray-700"
                    >
                        ⭐ {t.object_emoji} <b>{t.object_name ?? '—'}</b>:{' '}
                        {t.title}
                    </div>
                ))}
                {person.in_reserve && (
                    <div className="text-center text-[10px] font-bold text-amber-600">
                        без территорий!
                    </div>
                )}
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

export default function PeopleTab({
    board,
    events,
    onPersonEdit,
    selectedPersonId,
    onSelectPerson,
}: Props) {
    const [group, setGroup] = useState<PeopleGroup>('load');
    const personEvents = selectedPersonId
        ? events.filter((e) => e.person?.id === selectedPersonId)
        : [];
    const totalLoad = board.people.reduce((sum, p) => sum + p.load_percent, 0);
    const overloaded = board.people.filter((p) => p.load_status === 'over');
    const free = board.people.filter((p) => p.load_status === 'free');

    const grid = (people: BoardPerson[]) => (
        <div className="flex flex-wrap gap-3">
            {people.map((p) => (
                <PersonCardLarge
                    key={p.id}
                    person={p}
                    capacity={board.capacity_percent}
                    selected={p.id === selectedPersonId}
                    onSelect={() =>
                        onSelectPerson(p.id === selectedPersonId ? null : p.id)
                    }
                    onEdit={() => onPersonEdit(p)}
                />
            ))}
        </div>
    );

    const sections: Array<[string, BoardPerson[]]> =
        group === 'load'
            ? (['over', 'full', 'ok', 'free'] as const).map((status) => [
                  LOAD_TITLE[status],
                  board.people
                      .filter((p) => p.load_status === status)
                      .sort((a, b) => b.load_percent - a.load_percent),
              ])
            : group === 'class'
              ? [...new Set(board.people.map((p) => p.class))]
                    .sort()
                    .map((klass) => [
                        klass,
                        board.people
                            .filter((p) => p.class === klass)
                            .sort((a, b) => b.load_percent - a.load_percent),
                    ])
              : [
                    [
                        'Прямые',
                        board.people
                            .filter((p) => p.is_direct)
                            .sort((a, b) => b.load_percent - a.load_percent),
                    ],
                    [
                        'Смежники',
                        board.people
                            .filter((p) => !p.is_direct)
                            .sort((a, b) => b.load_percent - a.load_percent),
                    ],
                ];

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_300px]">
            <div className="space-y-5">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <div className="flex gap-1">
                        {GROUPS.map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setGroup(key)}
                                className={`cursor-pointer rounded-full px-2.5 py-1 text-[11px] font-bold transition ${group === key ? 'bg-gray-900 text-white' : 'bg-white text-gray-500 ring-1 ring-[#E4E1D8] hover:text-gray-900'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <span className="text-[10px] text-gray-500">
                        суммарно занято <b>{totalLoad}%</b> из{' '}
                        {board.people.length * board.capacity_percent}% ·{' '}
                        <span className={LOAD_TONE.over.text}>
                            перегруз: {overloaded.length}
                        </span>{' '}
                        ·{' '}
                        <span className={LOAD_TONE.free.text}>
                            есть запас: {free.length}
                        </span>
                    </span>
                </div>

                {sections.map(([title, people]) =>
                    people.length === 0 ? null : (
                        <div key={title}>
                            <h2 className="mb-2 text-xs font-extrabold text-gray-500 uppercase">
                                {title}{' '}
                                <span className="text-gray-300">
                                    · {people.length}
                                </span>
                            </h2>
                            {grid(people)}
                        </div>
                    ),
                )}
            </div>
            <aside className="rounded-xl border border-[#E4E1D8] bg-white p-4 lg:sticky lg:top-4 lg:self-start">
                <h2 className="mb-3 text-xs font-extrabold text-gray-900">
                    {selectedPersonId
                        ? 'ЛИЧНАЯ ХРОНИКА'
                        : 'ХРОНИКА — выбери персонажа'}
                </h2>
                <ChroniclePanel
                    events={selectedPersonId ? personEvents : events}
                    limit={15}
                />
            </aside>
        </div>
    );
}
