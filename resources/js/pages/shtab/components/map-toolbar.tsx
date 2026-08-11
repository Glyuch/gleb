import type { Board, BoardObject } from '../types';
import { LOAD_TONE, TYPE_LABEL } from '../types';

export type GroupMode = 'tree' | 'type' | 'focus' | 'person';

export interface MapFilters {
    group: GroupMode;
    types: BoardObject['type'][];
    hotOnly: boolean;
    unownedOnly: boolean;
    personId: number | null;
    query: string;
}

export const DEFAULT_FILTERS: MapFilters = {
    group: 'tree',
    types: ['product', 'project', 'enabler'],
    hotOnly: false,
    unownedOnly: false,
    personId: null,
    query: '',
};

const GROUPS: Array<[GroupMode, string]> = [
    ['tree', 'По продуктам'],
    ['type', 'По типам'],
    ['focus', 'По фокусу'],
    ['person', 'По людям'],
];

interface Props {
    board: Board;
    filters: MapFilters;
    onChange: (filters: MapFilters) => void;
    shown: number;
    total: number;
}

function Pill({
    active,
    onClick,
    children,
    title,
}: {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
    title?: string;
}) {
    return (
        <button
            type="button"
            title={title}
            onClick={onClick}
            className={`cursor-pointer rounded-full px-2.5 py-1 text-[11px] font-bold transition ${active ? 'bg-gray-900 text-white' : 'bg-white text-gray-500 ring-1 ring-[#E4E1D8] hover:text-gray-900'}`}
        >
            {children}
        </button>
    );
}

/**
 * Панель разрезов карты: группировка, фильтры по типу/фокусу/человеку и поиск.
 * Всё состояние живёт в index и просто прокидывается сюда.
 */
export default function MapToolbar({
    board,
    filters,
    onChange,
    shown,
    total,
}: Props) {
    const set = (patch: Partial<MapFilters>) =>
        onChange({ ...filters, ...patch });
    const toggleType = (type: BoardObject['type']) =>
        set({
            types: filters.types.includes(type)
                ? filters.types.filter((t) => t !== type)
                : [...filters.types, type],
        });
    const person = board.people.find((p) => p.id === filters.personId);

    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-[#E4E1D8] bg-[#F8F7F3] px-5 py-2">
            <div className="flex gap-1">
                {GROUPS.map(([key, label]) => (
                    <Pill
                        key={key}
                        active={filters.group === key}
                        onClick={() => set({ group: key })}
                    >
                        {label}
                    </Pill>
                ))}
            </div>

            <div className="flex gap-1">
                {(['product', 'project', 'enabler'] as const).map((type) => (
                    <Pill
                        key={type}
                        active={filters.types.includes(type)}
                        onClick={() => toggleType(type)}
                    >
                        {TYPE_LABEL[type]}
                    </Pill>
                ))}
            </div>

            <div className="flex gap-1">
                <Pill
                    active={filters.hotOnly}
                    onClick={() => set({ hotOnly: !filters.hotOnly })}
                    title="Только территории в фокусе"
                >
                    🔥 в фокусе
                </Pill>
                <Pill
                    active={filters.unownedOnly}
                    onClick={() => set({ unownedOnly: !filters.unownedOnly })}
                    title="Территории без владельца"
                >
                    без владельца
                </Pill>
            </div>

            <select
                aria-label="Фильтр по человеку"
                value={filters.personId ?? ''}
                onChange={(e) =>
                    set({
                        personId: e.target.value
                            ? Number(e.target.value)
                            : null,
                    })
                }
                className="rounded-full bg-white px-2 py-1 text-[11px] font-bold text-gray-600 ring-1 ring-[#E4E1D8]"
            >
                <option value="">все люди</option>
                {board.people.map((p) => (
                    <option key={p.id} value={p.id}>
                        {p.name} — {p.load_percent}%
                    </option>
                ))}
            </select>

            <input
                type="search"
                value={filters.query}
                onChange={(e) => set({ query: e.target.value })}
                placeholder="поиск по территориям…"
                className="w-44 rounded-full bg-white px-3 py-1 text-[11px] text-gray-700 ring-1 ring-[#E4E1D8] outline-none focus:ring-gray-400"
            />

            <span className="ml-auto flex items-center gap-2 text-[10px] text-gray-400">
                {person && (
                    <span
                        className={`rounded-full px-2 py-0.5 font-bold ${LOAD_TONE[person.load_status].bg} ${LOAD_TONE[person.load_status].text}`}
                    >
                        {person.name}: {person.load_percent}% из{' '}
                        {board.capacity_percent}%
                    </span>
                )}
                показано {shown} из {total}
                {(shown !== total || filters.group !== 'tree') && (
                    <button
                        type="button"
                        onClick={() => onChange(DEFAULT_FILTERS)}
                        className="cursor-pointer font-bold text-gray-500 underline"
                    >
                        сбросить
                    </button>
                )}
            </span>
        </div>
    );
}
