import type { BoardPerson, ObjectAssignment } from '../types';

interface Props {
    person: BoardPerson;
    assignment?: ObjectAssignment; // задан, когда чип живёт внутри сектора
    onClick?: () => void;
    draggable?: boolean;
    onDragStart?: (e: React.DragEvent) => void;
}

// Компактная карточка-персонаж: медальон, имя, роль/класс, гемы-статы, дата.
export default function PersonChip({ person, assignment, onClick, draggable, onDragStart }: Props) {
    return (
        <button
            type="button"
            onClick={onClick}
            draggable={draggable}
            onDragStart={onDragStart}
            className="flex w-[104px] cursor-pointer flex-col items-center rounded-lg border border-[#DFE4EC] bg-white px-2 py-2 text-center shadow-sm transition hover:shadow-md"
        >
            <span
                className="flex h-9 w-9 items-center justify-center rounded-full text-xs font-extrabold text-white ring-2 ring-offset-1"
                style={{ backgroundColor: person.color, ['--tw-ring-color' as string]: `${person.color}55` }}
            >
                {person.initials}
            </span>
            <span className="mt-1 text-[11px] font-bold text-gray-900">{person.name}</span>
            <span className="text-[9px] font-semibold tracking-wide text-gray-500 uppercase">
                {assignment ? assignment.role_label : person.class}
            </span>
            <span className="mt-1 flex gap-1">
                <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[9px] font-extrabold text-gray-700">
                    {person.focus_count}
                </span>
                <span
                    className={`rounded px-1.5 py-0.5 text-[9px] font-extrabold ${person.is_overloaded ? 'bg-red-100 text-red-700' : 'bg-orange-50 text-orange-600'}`}
                >
                    🔥{person.hot_count}
                </span>
            </span>
            {assignment && (
                <span className="mt-1 text-[9px] text-gray-400">
                    с {assignment.started_at.slice(8, 10)}.{assignment.started_at.slice(5, 7)} · {assignment.days} дн
                </span>
            )}
        </button>
    );
}
