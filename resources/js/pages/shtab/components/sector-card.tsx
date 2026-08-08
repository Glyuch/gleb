import { router } from '@inertiajs/react';
import type { Board, BoardObject } from '../types';
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
}

const TYPE_LABEL: Record<BoardObject['type'], string> = {
    product: '',
    project: 'проект',
    enabler: 'энейблер',
};

export default function SectorCard({ object, board, onAssignClick, onPersonDrop, onPersonClick, onMetricClick, onEditClick }: Props) {
    const uncoveredHot = object.is_uncovered && object.focus_level >= 1;
    const borderStyle = object.is_uncovered
        ? { borderColor: uncoveredHot ? '#D97706' : '#94A3B8' }
        : { borderColor: object.color, backgroundColor: `${object.color}14` };

    return (
        <div
            className={`rounded-xl border-[1.5px] p-3 ${object.is_uncovered ? 'border-dashed' : ''}`}
            style={borderStyle}
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => {
                e.preventDefault();
                const personId = Number(e.dataTransfer.getData('personId'));
                const assignmentId = e.dataTransfer.getData('assignmentId');

                if (personId) {
                    onPersonDrop(personId, assignmentId ? Number(assignmentId) : null, object.id);
                }
            }}
        >
            <div className="mb-2 flex items-center justify-between">
                <button type="button" onClick={() => onEditClick(object.id)} className="cursor-pointer text-[11px] font-extrabold tracking-wide text-[#3B475C] uppercase">
                    {FIRE[object.focus_level]} {object.emoji} {object.name}
                    {TYPE_LABEL[object.type] && <span className="ml-1 font-semibold text-gray-400 normal-case">· {TYPE_LABEL[object.type]}</span>}
                </button>
                <span className="flex gap-1">
                    {object.metrics.map((m) => (
                        <button
                            key={m.id}
                            type="button"
                            title={`${m.name}${m.value_text ? `: ${m.value_text}` : ''}`}
                            aria-label={m.name}
                            onClick={() => onMetricClick(m.id)}
                            className={`h-3 w-3 cursor-pointer rounded-full ${STATUS_DOT[m.status]}`}
                        />
                    ))}
                    <button
                        type="button"
                        onClick={() => {
                            const name = window.prompt('Название метрики');

                            if (name?.trim()) {
                                router.post('/shtab/metrics', { object_id: object.id, name: name.trim() }, { preserveScroll: true });
                            }
                        }}
                        className="h-3 w-3 cursor-pointer rounded-full border border-dashed border-gray-400 text-[8px] leading-none text-gray-400"
                        title="Добавить метрику"
                    >
                        +
                    </button>
                </span>
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
