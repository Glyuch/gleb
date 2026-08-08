import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Board, BoardPerson } from '../types';

const COLORS = ['#10B981', '#8B5CF6', '#F59E0B', '#EC4899', '#3B82F6', '#14B8A6', '#EF4444', '#64748B'];

interface Props {
    open: boolean;
    person: BoardPerson | null; // null → создание
    board: Board;
    onClose: () => void;
}

export default function PersonFormDialog({ open, person, board, onClose }: Props) {
    const [name, setName] = useState(person?.name ?? '');
    const [initials, setInitials] = useState(person?.initials ?? '');
    const [klass, setKlass] = useState(person?.class ?? '');
    const [color, setColor] = useState(person?.color ?? COLORS[0]);
    const [isDirect, setIsDirect] = useState(person?.is_direct ?? true);
    const [isMe, setIsMe] = useState(person?.is_me ?? false);
    const [managerId, setManagerId] = useState<number | null>(person?.manager_id ?? null);
    const [submitting, setSubmitting] = useState(false);

    if (!open) {
        return null;
    }

    const submit = () => {
        const payload = {
            name: name.trim(),
            initials: initials.trim() || name.trim().slice(0, 2).toUpperCase(),
            class: klass.trim() || 'Боец',
            color,
            is_direct: isDirect,
            manager_id: managerId,
            is_me: isMe,
        };
        const opts = {
            preserveScroll: true,
            onStart: () => setSubmitting(true),
            onFinish: () => setSubmitting(false),
            onSuccess: onClose,
        };

        if (person) {
            router.put(`/shtab/people/${person.id}`, payload, opts);
        } else {
            router.post('/shtab/people', payload, opts);
        }
    };

    const archive = () => {
        if (person) {
            router.post(`/shtab/people/${person.id}/archive`, {}, { preserveScroll: true, onSuccess: onClose });
        }
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{person ? `Персонаж: ${person.name}` : 'Новый персонаж'}</DialogTitle>
                </DialogHeader>
                <form
                    className="space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <div>
                        <Label htmlFor="p_name">Имя</Label>
                        <Input id="p_name" value={name} onChange={(e) => setName(e.target.value)} />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <Label htmlFor="p_initials">Инициалы</Label>
                            <Input id="p_initials" value={initials} onChange={(e) => setInitials(e.target.value)} maxLength={8} />
                        </div>
                        <div>
                            <Label htmlFor="p_class">Класс</Label>
                            <Input id="p_class" value={klass} onChange={(e) => setKlass(e.target.value)} placeholder="Аналитик" />
                        </div>
                    </div>
                    <div className="flex gap-1.5">
                        {COLORS.map((c) => (
                            <button
                                key={c}
                                type="button"
                                aria-label={`Цвет ${c}`}
                                onClick={() => setColor(c)}
                                className={`h-6 w-6 cursor-pointer rounded-full ${color === c ? 'ring-2 ring-gray-900 ring-offset-1' : ''}`}
                                style={{ backgroundColor: c }}
                            />
                        ))}
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={isDirect} onChange={(e) => setIsDirect(e.target.checked)} />
                        Прямой подчинённый
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={isMe} onChange={(e) => setIsMe(e.target.checked)} />
                        Это я (моя карточка на карте)
                    </label>
                    <div>
                        <Label htmlFor="p_manager">Руководитель</Label>
                        <select
                            id="p_manager"
                            className="w-full rounded-md border border-gray-300 p-2 text-sm"
                            value={managerId ?? ''}
                            onChange={(e) => setManagerId(e.target.value ? Number(e.target.value) : null)}
                        >
                            <option value="">—</option>
                            {board.people
                                .filter((p) => p.id !== person?.id)
                                .map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                        </select>
                    </div>
                    <Button type="submit" disabled={!name.trim() || submitting} className="w-full">
                        Сохранить
                    </Button>
                </form>
                {person && (
                    <Button type="button" variant="outline" onClick={archive} className="w-full">
                        В архив (если без назначений)
                    </Button>
                )}
            </DialogContent>
        </Dialog>
    );
}
