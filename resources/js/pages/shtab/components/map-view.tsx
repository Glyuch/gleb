import type { Board, BoardObject, BoardPerson } from '../types';
import { FIRE, LOAD_TONE, ROLE_ICON, TYPE_LABEL } from '../types';
import type { MapFilters, SortMode } from './map-toolbar';
import SectorCard from './sector-card';

export interface SectorHandlers {
    onAssignClick: (objectId: number) => void;
    onPersonDrop: (
        personId: number,
        assignmentId: number | null,
        objectId: number,
    ) => void;
    onPersonClick: (assignmentId: number) => void;
    onMetricClick: (metricId: number) => void;
    onEditClick: (objectId: number) => void;
    onTasksClick: (objectId: number) => void;
}

interface Props extends SectorHandlers {
    board: Board;
    filters: MapFilters;
}

export function matchesFilters(
    object: BoardObject,
    filters: MapFilters,
): boolean {
    if (!filters.types.includes(object.type)) {
        return false;
    }

    if (filters.hotOnly && object.focus_level < 1) {
        return false;
    }

    if (filters.unownedOnly && object.has_owner) {
        return false;
    }

    if (
        filters.personId &&
        !object.assignments.some((a) => a.person_id === filters.personId)
    ) {
        return false;
    }

    const query = filters.query.trim().toLowerCase();

    if (
        query &&
        !`${object.name} ${object.description ?? ''}`
            .toLowerCase()
            .includes(query)
    ) {
        return false;
    }

    return true;
}

const TYPE_ORDER: Record<BoardObject['type'], number> = {
    product: 0,
    project: 1,
    enabler: 2,
};

/**
 * Порядок карточек внутри разделов задаётся в тулбаре. Везде, где значение
 * может совпасть, доводим сравнение фокусом и именем — чтобы порядок не прыгал
 * между рендерами.
 */
const byName = (a: BoardObject, b: BoardObject) => a.name.localeCompare(b.name);
const byFocusThenName = (a: BoardObject, b: BoardObject) =>
    b.focus_level - a.focus_level || byName(a, b);

const COMPARATORS: Record<
    SortMode,
    (a: BoardObject, b: BoardObject) => number
> = {
    focus: byFocusThenName,
    type: (a, b) =>
        TYPE_ORDER[a.type] - TYPE_ORDER[b.type] || byFocusThenName(a, b),
    load: (a, b) => b.load_total - a.load_total || byFocusThenName(a, b),
    people: (a, b) =>
        b.assignments.length - a.assignments.length || byFocusThenName(a, b),
    tasks: (a, b) => b.open_tasks - a.open_tasks || byFocusThenName(a, b),
    name: byName,
};

export const sorter = (sort: SortMode) => COMPARATORS[sort] ?? byFocusThenName;

function Section({
    title,
    meta,
    children,
}: {
    title: React.ReactNode;
    meta?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-2">
            <header className="flex flex-wrap items-center gap-2 border-b border-[#E4E1D8] pb-1">
                <h2 className="text-[12px] font-extrabold tracking-wide text-[#3B475C] uppercase">
                    {title}
                </h2>
                {meta}
            </header>
            <div className="grid auto-rows-min grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                {children}
            </div>
        </section>
    );
}

/** Сводка по группе: сколько территорий, сколько в фокусе, сколько без владельца. */
function GroupMeta({ objects }: { objects: BoardObject[] }) {
    const hot = objects.filter((o) => o.focus_level >= 1).length;
    const unowned = objects.filter((o) => !o.has_owner).length;
    const openTasks = objects.reduce((sum, o) => sum + o.open_tasks, 0);

    return (
        <span className="flex flex-wrap items-center gap-2 text-[10px] text-gray-400">
            <span>{objects.length} шт</span>
            {hot > 0 && <span className="text-orange-600">🔥 {hot}</span>}
            {unowned > 0 && (
                <span className="font-bold text-amber-700">
                    без владельца: {unowned}
                </span>
            )}
            {openTasks > 0 && <span>задач открыто: {openTasks}</span>}
        </span>
    );
}

function PersonMeta({
    person,
    capacity,
}: {
    person: BoardPerson;
    capacity: number;
}) {
    const tone = LOAD_TONE[person.load_status];

    return (
        <span className="flex items-center gap-2 text-[10px] text-gray-500">
            <span className="text-gray-400">{person.class}</span>
            <span className="h-1.5 w-24 overflow-hidden rounded-full bg-gray-200">
                <span
                    className={`block h-full ${tone.bar}`}
                    style={{
                        width: `${Math.min(100, (person.load_percent / capacity) * 100)}%`,
                    }}
                />
            </span>
            <span className={`font-extrabold ${tone.text}`}>
                {person.load_percent}%
            </span>
            {person.owner_count > 0 && (
                <span>
                    {ROLE_ICON.owner} {person.owner_count}
                </span>
            )}
        </span>
    );
}

/**
 * Карта территорий в четырёх разрезах: дерево продуктов (проекты и энейблеры
 * внутри своих родителей), по типам, по уровню фокуса и по людям.
 */
export default function MapView({ board, filters, ...handlers }: Props) {
    const visible = board.objects.filter((o) => matchesFilters(o, filters));
    const compare = sorter(filters.sort);
    const visibleIds = new Set(visible.map((o) => o.id));

    const card = (object: BoardObject) => (
        <SectorCard
            key={object.id}
            object={object}
            board={board}
            {...handlers}
        />
    );

    if (visible.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-400">
                Под фильтры ничего не попало — ослабь условия сверху.
            </div>
        );
    }

    if (filters.group === 'type') {
        return (
            <div className="space-y-6">
                {(['product', 'project', 'enabler'] as const).map((type) => {
                    const group = visible
                        .filter((o) => o.type === type)
                        .sort(compare);

                    return group.length === 0 ? null : (
                        <Section
                            key={type}
                            title={`${TYPE_LABEL[type]}ы`}
                            meta={<GroupMeta objects={group} />}
                        >
                            {group.map(card)}
                        </Section>
                    );
                })}
            </div>
        );
    }

    if (filters.group === 'focus') {
        return (
            <div className="space-y-6">
                {([2, 1, 0] as const).map((level) => {
                    const group = visible
                        .filter((o) => o.focus_level === level)
                        .sort(compare);

                    return group.length === 0 ? null : (
                        <Section
                            key={level}
                            title={
                                level === 0 ? 'Фоновые' : `${FIRE[level]} фокус`
                            }
                            meta={<GroupMeta objects={group} />}
                        >
                            {group.map(card)}
                        </Section>
                    );
                })}
            </div>
        );
    }

    if (filters.group === 'person') {
        const people = [...board.people]
            .filter((p) => !filters.personId || p.id === filters.personId)
            .sort((a, b) => b.load_percent - a.load_percent);
        const orphans = visible.filter((o) => o.assignments.length === 0);

        return (
            <div className="space-y-6">
                {people.map((person) => {
                    const group = visible
                        .filter((o) =>
                            o.assignments.some(
                                (a) => a.person_id === person.id,
                            ),
                        )
                        .sort(compare);

                    return group.length === 0 ? null : (
                        <Section
                            key={person.id}
                            title={
                                <span className="flex items-center gap-2">
                                    <span
                                        className="flex h-5 w-5 items-center justify-center rounded-full text-[8px] font-extrabold text-white"
                                        style={{
                                            backgroundColor: person.color,
                                        }}
                                    >
                                        {person.initials}
                                    </span>
                                    {person.name}
                                </span>
                            }
                            meta={
                                <PersonMeta
                                    person={person}
                                    capacity={board.capacity_percent}
                                />
                            }
                        >
                            {group.map(card)}
                        </Section>
                    );
                })}
                {orphans.length > 0 && (
                    <Section
                        title="Без людей"
                        meta={<GroupMeta objects={orphans} />}
                    >
                        {orphans.map(card)}
                    </Section>
                )}
            </div>
        );
    }

    // tree: продукты и корневые энейблеры как разделы, внутри — их проекты.
    const childrenOf = (parentId: number) =>
        visible.filter((o) => o.parent_id === parentId).sort(compare);
    // Проекты могут висеть на энейблере, который сам вложен в продукт — берём два уровня.
    const descendantsOf = (parentId: number) => {
        const direct = childrenOf(parentId);

        return [
            ...direct,
            ...direct.flatMap((child) => childrenOf(child.id)),
        ].filter(
            (object, index, all) =>
                all.findIndex((o) => o.id === object.id) === index,
        );
    };
    const roots = board.objects
        .filter(
            (o) =>
                o.parent_id === null &&
                (o.type === 'product' || o.type === 'enabler'),
        )
        // Тип на порядок не влияет: горячий энейблер стоит выше спокойного
        // продукта — сортировка сквозная, как и внутри разделов.
        .sort(compare);
    const rendered = new Set<number>();

    const sections = roots
        .map((root) => {
            const kids = descendantsOf(root.id);
            const rootVisible = visibleIds.has(root.id);

            if (!rootVisible && kids.length === 0) {
                return null;
            }

            rendered.add(root.id);
            kids.forEach((k) => rendered.add(k.id));

            return (
                <Section
                    key={root.id}
                    title={
                        <button
                            type="button"
                            onClick={() => handlers.onEditClick(root.id)}
                            className="cursor-pointer"
                        >
                            {FIRE[root.focus_level]} {root.emoji} {root.name}
                            <span className="ml-1 font-semibold text-gray-400 normal-case">
                                · {TYPE_LABEL[root.type]}
                            </span>
                        </button>
                    }
                    meta={
                        <GroupMeta
                            objects={rootVisible ? [root, ...kids] : kids}
                        />
                    }
                >
                    {rootVisible && card(root)}
                    {kids.map(card)}
                </Section>
            );
        })
        .filter(Boolean);

    const rest = visible.filter((o) => !rendered.has(o.id)).sort(compare);

    return (
        <div className="space-y-6">
            {sections}
            {rest.length > 0 && (
                <Section
                    title="Самостоятельные"
                    meta={<GroupMeta objects={rest} />}
                >
                    {rest.map(card)}
                </Section>
            )}
        </div>
    );
}
