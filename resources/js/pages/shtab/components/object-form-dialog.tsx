import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Board, BoardObject } from '../types';

const COLORS = ['#5B6EE8', '#0EA5E9', '#14B8A6', '#F59E0B', '#EC4899', '#64748B'];

interface Props {
    open: boolean;
    object: BoardObject | null; // null → создание
    board: Board;
    onClose: () => void;
}

export default function ObjectFormDialog({ open, object, board, onClose }: Props) {
    const [type, setType] = useState<BoardObject['type']>(object?.type ?? 'product');
    const [name, setName] = useState(object?.name ?? '');
    const [emoji, setEmoji] = useState(object?.emoji ?? '🏰');
    const [focusLevel, setFocusLevel] = useState<0 | 1 | 2>(object?.focus_level ?? 0);
    const [color, setColor] = useState(object?.color ?? COLORS[0]);
    const [parentId, setParentId] = useState<number | null>(object?.parent_id ?? null);

    if (!open) {
        return null;
    }

    const submit = () => {
        const payload = { type, name: name.trim(), emoji: emoji.trim() || null, focus_level: focusLevel, color, parent_id: parentId };
        const opts = { preserveScroll: true, onSuccess: onClose };

        if (object) {
            router.put(`/shtab/objects/${object.id}`, payload, opts);
        } else {
            router.post('/shtab/objects', payload, opts);
        }
    };

    const archive = () => {
        if (object) {
            router.post(`/shtab/objects/${object.id}/archive`, {}, { preserveScroll: true, onSuccess: onClose });
        }
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>{object ? `Территория: ${object.name}` : 'Новая территория'}</DialogTitle>
                </DialogHeader>
                <div className="space-y-3">
                    <div className="flex gap-2">
                        {(
                            [
                                ['product', 'Продукт'],
                                ['project', 'Проект'],
                                ['enabler', 'Энейблер'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setType(value)}
                                className={`cursor-pointer rounded-lg border px-2 py-1 text-xs font-bold ${type === value ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-300'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="grid grid-cols-[1fr_70px] gap-3">
                        <div>
                            <Label htmlFor="o_name">Название</Label>
                            <Input id="o_name" value={name} onChange={(e) => setName(e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor="o_emoji">Эмодзи</Label>
                            <Input id="o_emoji" value={emoji} onChange={(e) => setEmoji(e.target.value)} maxLength={4} />
                        </div>
                    </div>
                    <div>
                        <Label>Твой фокус</Label>
                        <div className="flex gap-2">
                            {(
                                [
                                    [0, 'фоновый'],
                                    [1, '🔥'],
                                    [2, '🔥🔥'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => setFocusLevel(value)}
                                    className={`cursor-pointer rounded-lg border px-3 py-1 text-xs font-bold ${focusLevel === value ? 'border-orange-500 bg-orange-50' : 'border-gray-300'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>
                    {type !== 'product' && (
                        <div>
                            <Label htmlFor="o_parent">Часть продукта</Label>
                            <select
                                id="o_parent"
                                className="w-full rounded-md border border-gray-300 p-2 text-sm"
                                value={parentId ?? ''}
                                onChange={(e) => setParentId(e.target.value ? Number(e.target.value) : null)}
                            >
                                <option value="">— самостоятельный</option>
                                {board.objects
                                    .filter((o) => o.type === 'product' && o.id !== object?.id)
                                    .map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {o.emoji} {o.name}
                                        </option>
                                    ))}
                            </select>
                        </div>
                    )}
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
                    <Button onClick={submit} disabled={!name.trim()} className="w-full">
                        Сохранить
                    </Button>
                    {object && (
                        <Button variant="outline" onClick={archive} className="w-full">
                            В архив (если пуста)
                        </Button>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
