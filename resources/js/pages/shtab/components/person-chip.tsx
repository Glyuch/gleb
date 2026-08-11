import type { BoardPerson, ObjectAssignment } from '../types';
import { LOAD_TONE, ROLE_ICON } from '../types';

interface Props {
    person: BoardPerson;
    assignment?: ObjectAssignment; // задан, когда чип живёт внутри сектора
    onClick?: () => void;
    draggable?: boolean;
    onDragStart?: (e: React.DragEvent) => void;
}

// Компактная карточка-персонаж: медальон, имя, роль/класс, гемы-статы, дата.
export default function PersonChip({
    person,
    assignment,
    onClick,
    draggable,
    onDragStart,
}: Props) {
    return (
        <button
            type="button"
            onClick={onClick}
            draggable={draggable}
            onDragStart={onDragStart}
            className="flex w-[116px] cursor-pointer flex-col items-center rounded-lg border border-[#DFE4EC] bg-white px-2 py-2 text-center shadow-sm transition hover:shadow-md"
        >
            <span
                className="flex h-9 w-9 items-center justify-center rounded-full text-xs font-extrabold text-white ring-2 ring-offset-1"
                style={{
                    backgroundColor: person.color,
                    ['--tw-ring-color' as string]: `${person.color}55`,
                }}
            >
                {person.initials}
            </span>
            <span className="mt-1 text-[11px] font-bold text-gray-900">
                {person.name}
            </span>
            <span className="text-[9px] font-semibold tracking-wide text-gray-500 uppercase">
                {assignment
                    ? `${ROLE_ICON[assignment.role_type]} ${assignment.role_label}`
                    : person.class}
            </span>
            <span className="mt-1 flex flex-wrap justify-center gap-1">
                {assignment && (
                    <span
                        className="rounded bg-gray-900/85 px-1.5 py-0.5 text-[9px] font-extrabold text-white"
                        title="Вовлечённость в эту территорию"
                    >
                        {assignment.load_percent}%
                    </span>
                )}
                <span
                    className={`rounded px-1.5 py-0.5 text-[9px] font-extrabold ${LOAD_TONE[person.load_status].bg} ${LOAD_TONE[person.load_status].text}`}
                    title="Суммарная нагрузка человека"
                >
                    Σ{person.load_percent}%
                </span>
                <span
                    className={`rounded px-1.5 py-0.5 text-[9px] font-extrabold ${person.is_overloaded ? 'bg-red-100 text-red-700' : 'bg-orange-50 text-orange-600'}`}
                >
                    🔥{person.hot_count}
                </span>
            </span>
            {assignment && (
                <span className="mt-1 text-[9px] text-gray-400">
                    с {assignment.started_at.slice(8, 10)}.
                    {assignment.started_at.slice(5, 7)} · {assignment.days} дн
                </span>
            )}
        </button>
    );
}
