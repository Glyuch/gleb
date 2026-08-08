import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { Board, BoardObject, MetricStatus } from '../types';
import { FIRE, STATUS_DOT } from '../types';
import PersonChip from './person-chip';

interface Props {
    object: BoardObject;
    board: Board;
    onAssignClick: (objectId: number) => void;
    onPersonDrop: (personId: number, assignmentId: number | null, objectId: number) => void;
    onPersonClick: (assignmentId: number) => void;
    onMetricClick: (metricId: number) => void;
    onEditClick: (objectId: number) => void;
    onTasksClick: (objectId: number) => void;
}

const TYPE_LABEL: Record<BoardObject['type'], string> = {
    product: '',
    project: 'проект',
    enabler: 'энейблер',
};

const STATUS_PILL: Record<MetricStatus, string> = {
    green: 'bg-green-50 text-green-800',
    yellow: 'bg-yellow-50 text-yellow-800',
    red: 'bg-red-50 text-red-800',
};

export default function SectorCard({ object, board, onAssignClick, onPersonDrop, onPersonClick, onMetricClick, onEditClick, onTasksClick }: Props) {
    const [dragOver, setDragOver] = useState(false);
    const uncoveredHot = object.is_uncovered && object.focus_level >= 1;
    const keyTask = object.tasks.find((t) => !t.is_done && t.is_key);
    const accentColor = object.is_uncovered ? (uncoveredHot ? '#D97706' : '#94A3B8') : object.color;
    const borderStyle = object.is_uncovered
        ? { borderColor: accentColor }
        : { borderColor: object.color, backgroundColor: `${object.color}14` };

    return (
        <div
            className={`rounded-xl border-[1.5px] p-3 ${object.is_uncovered ? 'border-dashed' : ''}`}
            style={{ ...borderStyle, outline: dragOver ? `2px solid ${accentColor}` : undefined, outlineOffset: '2px' }}
            onDragOver={(e) => {
                e.preventDefault();
                setDragOver(true);
            }}
            onDragLeave={() => setDragOver(false)}
            onDrop={(e) => {
                e.preventDefault();
                setDragOver(false);
                const personId = Number(e.dataTransfer.getData('personId'));
                const assignmentId = e.dataTransfer.getData('assignmentId');

                if (!personId) {
                    return;
                }

                if (object.assignments.some((a) => a.person_id === personId)) {
                    return;
                }

                onPersonDrop(personId, assignmentId ? Number(assignmentId) : null, object.id);
            }}
        >
            <div className="mb-1 flex items-center">
                <button type="button" onClick={() => onEditClick(object.id)} className="cursor-pointer text-left text-[11px] font-extrabold tracking-wide text-[#3B475C] uppercase">
                    {FIRE[object.focus_level]} {object.emoji} {object.name}
                    {TYPE_LABEL[object.type] && <span className="ml-1 font-semibold text-gray-400 normal-case">· {TYPE_LABEL[object.type]}</span>}
                </button>
            </div>
            {object.description && (
                <p className="mb-1.5 line-clamp-2 text-[10px] leading-snug text-gray-500" title={object.description}>
                    {object.description}
                </p>
            )}
            {keyTask && (
                <p className="mb-1.5 flex items-center gap-1 text-[10px] font-semibold text-gray-700">
                    <span aria-hidden="true">⭐</span>
                    <span className="truncate" title={keyTask.title}>
                        {keyTask.title}
                    </span>
                    {keyTask.assignee && (
                        <span
                            role="img"
                            aria-label={keyTask.assignee.name}
                            title={keyTask.assignee.name}
                            className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[7px] font-extrabold text-white"
                            style={{ backgroundColor: keyTask.assignee.color }}
                        >
                            {keyTask.assignee.initials}
                        </span>
                    )}
                </p>
            )}
            <div className="mb-2 flex flex-wrap items-center gap-1">
                {object.metrics.map((m) => (
                    <button
                        key={m.id}
                        type="button"
                        title={`${m.name}${m.value_text ? `: ${m.value_text}` : ''}`}
                        aria-label={m.name}
                        onClick={() => onMetricClick(m.id)}
                        className={`flex cursor-pointer items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_PILL[m.status]}`}
                    >
                        {m.name}
                        <span className={`h-2.5 w-2.5 rounded-full ${STATUS_DOT[m.status]}`} />
                    </button>
                ))}
                <button
                    type="button"
                    onClick={() => {
                        const name = window.prompt('Название метрики');

                        if (name?.trim()) {
                            router.post('/shtab/metrics', { object_id: object.id, name: name.trim() }, { preserveScroll: true });
                        }
                    }}
                    className="cursor-pointer rounded-full border border-dashed border-gray-400 px-2 py-0.5 text-[10px] font-semibold text-gray-400 transition hover:border-gray-600 hover:text-gray-600"
                    title="Добавить метрику"
                >
                    + метрика
                </button>
                <button
                    type="button"
                    onClick={() => onTasksClick(object.id)}
                    title="Задачи территории"
                    className={
                        object.total_tasks > 0
                            ? 'cursor-pointer rounded-full bg-white/70 px-2 py-0.5 text-[10px] font-semibold text-gray-600 ring-1 ring-gray-200 transition hover:text-gray-900'
                            : 'cursor-pointer rounded-full border border-dashed border-gray-400 px-2 py-0.5 text-[10px] font-semibold text-gray-400 transition hover:border-gray-600 hover:text-gray-600'
                    }
                >
                    {object.total_tasks > 0 ? `задачи ${object.open_tasks}/${object.total_tasks}` : '+ задача'}
                </button>
            </div>
            <div className="flex flex-wrap gap-2">
                {object.assignments.map((a) => {
                    const person = board.people.find((p) => p.id === a.person_id);

                    return person ? (
                        <PersonChip
                            key={a.id}
                            person={person}
                            assignment={a}
                            draggable
                            onDragStart={(e) => {
                                e.dataTransfer.setData('personId', String(person.id));
                                e.dataTransfer.setData('assignmentId', String(a.id));
                            }}
                            onClick={() => onPersonClick(a.id)}
                        />
                    ) : (
                        <span
                            key={a.id}
                            className="flex items-center gap-1 self-start rounded-lg px-2 py-1 text-[10px] font-semibold text-white"
                            style={{ backgroundColor: a.person_color ?? '#94A3B8' }}
                        >
                            {a.person_initials ?? '?'} {a.person_name ?? '—'} · {a.role_label}
                        </span>
                    );
                })}
                <button
                    type="button"
                    onClick={() => onAssignClick(object.id)}
                    className="flex h-[104px] w-[64px] cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-gray-400 text-gray-400 transition hover:border-gray-600 hover:text-gray-600"
                >
                    <span className="text-lg">+</span>
                    {object.is_uncovered && (
                        <span className={`px-1 text-center text-[9px] ${uncoveredHot ? 'text-amber-700' : ''}`}>
                            пусто {object.uncovered_days} дн
                        </span>
                    )}
                </button>
            </div>
        </div>
    );
}
