import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { Board, BoardTask } from '../types';

interface Props {
    objectId: number | null;
    board: Board;
    onClose: () => void;
}

export default function TasksDialog({ objectId, board, onClose }: Props) {
    const [title, setTitle] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [showDone, setShowDone] = useState(false);
    const [pickerTaskId, setPickerTaskId] = useState<number | null>(null);
    const object = board.objects.find((o) => o.id === objectId);

    if (!objectId || !object) {
        return null;
    }

    const openTasks = object.tasks.filter((t) => !t.is_done);
    const doneTasks = object.tasks.filter((t) => t.is_done);
    const opts = {
        preserveScroll: true,
        onStart: () => setSubmitting(true),
        onFinish: () => setSubmitting(false),
    };

    const addTask = () => {
        if (!title.trim() || submitting) {
            return;
        }

        router.post('/shtab/tasks', { object_id: object.id, title: title.trim() }, { ...opts, onSuccess: () => setTitle('') });
    };

    const patch = (task: BoardTask, data: Record<string, boolean>) => {
        if (submitting) {
            return;
        }

        router.patch(`/shtab/tasks/${task.id}`, data, opts);
    };

    const setAssignee = (task: BoardTask, personId: number | null) => {
        if (submitting) {
            return;
        }

        router.patch(`/shtab/tasks/${task.id}`, { assignee_person_id: personId }, { ...opts, onSuccess: () => setPickerTaskId(null) });
    };

    const destroy = (task: BoardTask) => {
        if (submitting || !window.confirm(`Удалить задачу «${task.title}»?`)) {
            return;
        }

        router.delete(`/shtab/tasks/${task.id}`, opts);
    };

    const row = (task: BoardTask) => (
        <div key={task.id}>
            <div className="flex items-center gap-2">
                <Checkbox
                    checked={task.is_done}
                    disabled={submitting}
                    aria-label={task.is_done ? `Вернуть в работу: ${task.title}` : `Закрыть задачу: ${task.title}`}
                    onCheckedChange={(checked) => patch(task, { is_done: checked === true })}
                />
                <span className={`min-w-0 flex-1 text-xs ${task.is_done ? 'text-gray-400 line-through' : 'text-gray-800'}`}>{task.title}</span>
                <button
                    type="button"
                    disabled={submitting}
                    aria-label={task.is_key ? 'Снять отметку «ключевая»' : 'Сделать ключевой'}
                    title={task.is_key ? 'Снять отметку «ключевая»' : 'Сделать ключевой'}
                    onClick={() => patch(task, { is_key: !task.is_key })}
                    className={`cursor-pointer text-sm ${task.is_key ? '' : 'opacity-25 grayscale hover:opacity-60'}`}
                >
                    ⭐
                </button>
                <button
                    type="button"
                    disabled={submitting}
                    aria-label={task.assignee ? `Исполнитель: ${task.assignee.name}` : 'Назначить исполнителя'}
                    title={task.assignee?.name ?? 'Назначить исполнителя'}
                    onClick={() => setPickerTaskId(pickerTaskId === task.id ? null : task.id)}
                    className="cursor-pointer"
                >
                    {task.assignee ? (
                        <span
                            className="flex h-5 w-5 items-center justify-center rounded-full text-[8px] font-extrabold text-white"
                            style={{ backgroundColor: task.assignee.color }}
                        >
                            {task.assignee.initials}
                        </span>
                    ) : (
                        <span className="flex h-5 w-5 items-center justify-center rounded-full border border-dashed border-gray-400 text-[10px] text-gray-400">
                            +
                        </span>
                    )}
                </button>
                <button
                    type="button"
                    disabled={submitting}
                    aria-label={`Удалить задачу: ${task.title}`}
                    title="Удалить"
                    onClick={() => destroy(task)}
                    className="cursor-pointer text-sm text-gray-300 hover:text-red-500"
                >
                    ×
                </button>
            </div>
            {pickerTaskId === task.id && (
                <div className="mt-1 ml-6 flex flex-wrap gap-1">
                    {board.people.map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            disabled={submitting}
                            onClick={() => setAssignee(task, p.id)}
                            className={`cursor-pointer rounded-full border px-2 py-0.5 text-[10px] font-bold ${task.assignee?.id === p.id ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-300 text-gray-600 hover:border-gray-500'}`}
                        >
                            {p.name}
                        </button>
                    ))}
                    {task.assignee && (
                        <button
                            type="button"
                            disabled={submitting}
                            onClick={() => setAssignee(task, null)}
                            className="cursor-pointer rounded-full border border-dashed border-gray-400 px-2 py-0.5 text-[10px] font-bold text-gray-400 hover:border-gray-600 hover:text-gray-600"
                        >
                            снять
                        </button>
                    )}
                </div>
            )}
        </div>
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>
                        Задачи: {object.emoji} {object.name}
                    </DialogTitle>
                </DialogHeader>
                <form
                    className="flex gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        addTask();
                    }}
                >
                    <Input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={500} placeholder="Новая задача…" aria-label="Новая задача" />
                    <Button type="submit" disabled={!title.trim() || submitting} aria-label="Добавить задачу">
                        +
                    </Button>
                </form>
                <div className="space-y-2">
                    {openTasks.map(row)}
                    {openTasks.length === 0 && <p className="text-xs text-gray-400">Открытых задач нет.</p>}
                </div>
                {doneTasks.length > 0 && (
                    <div className="border-t border-gray-100 pt-2">
                        <button
                            type="button"
                            onClick={() => setShowDone(!showDone)}
                            className="cursor-pointer text-[10px] font-bold text-gray-400 hover:text-gray-600"
                        >
                            {showDone ? 'скрыть закрытые' : `показать закрытые (${doneTasks.length})`}
                        </button>
                        {showDone && <div className="mt-2 space-y-2">{doneTasks.map(row)}</div>}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
