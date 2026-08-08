import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Board, MetricStatus } from '../types';

interface Props {
    metricId: number | null;
    board: Board;
    onClose: () => void;
}

const STATUSES: { value: MetricStatus; label: string; cls: string }[] = [
    { value: 'green', label: '🟢 в норме', cls: 'border-green-500' },
    { value: 'yellow', label: '🟡 внимание', cls: 'border-yellow-500' },
    { value: 'red', label: '🔴 проблема', cls: 'border-red-500' },
];

export default function MetricDialog({ metricId, board, onClose }: Props) {
    const metric =
        board.business_metrics.find((m) => m.id === metricId) ??
        board.objects.flatMap((o) => o.metrics).find((m) => m.id === metricId);

    const [status, setStatus] = useState<MetricStatus>(metric?.status ?? 'green');
    const [valueText, setValueText] = useState(metric?.value_text ?? '');
    const [comment, setComment] = useState('');

    if (!metricId || !metric) {
        return null;
    }

    const submit = () => {
        router.patch(
            `/shtab/metrics/${metric.id}/status`,
            { status, value_text: valueText.trim() || null, comment: comment.trim() || null },
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    const rename = () => {
        const name = window.prompt('Новое название метрики', metric.name);

        if (name?.trim()) {
            router.put(
                `/shtab/metrics/${metric.id}`,
                { name: name.trim(), object_id: board.objects.find((o) => o.metrics.some((m) => m.id === metric.id))?.id ?? null },
                { preserveScroll: true },
            );
        }
    };

    const destroy = () => {
        if (window.confirm(`Удалить метрику «${metric.name}»?`)) {
            router.delete(`/shtab/metrics/${metric.id}`, { preserveScroll: true, onSuccess: onClose });
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>Метрика: {metric.name}</DialogTitle>
                </DialogHeader>
                <div className="space-y-3">
                    <div className="flex gap-2">
                        {STATUSES.map((s) => (
                            <button
                                key={s.value}
                                type="button"
                                onClick={() => setStatus(s.value)}
                                className={`cursor-pointer rounded-lg border-2 px-2 py-1 text-xs font-bold ${status === s.value ? s.cls : 'border-transparent bg-gray-100'}`}
                            >
                                {s.label}
                            </button>
                        ))}
                    </div>
                    <div>
                        <Label htmlFor="value_text">Значение (текстом)</Label>
                        <Input id="value_text" value={valueText} onChange={(e) => setValueText(e.target.value)} placeholder="12% / 34K MAU" />
                    </div>
                    <div>
                        <Label htmlFor="metric_comment">Комментарий</Label>
                        <Input id="metric_comment" value={comment} onChange={(e) => setComment(e.target.value)} />
                    </div>
                    <Button onClick={submit} className="w-full">
                        Сохранить
                    </Button>
                    <div className="flex gap-2">
                        <Button variant="outline" className="flex-1" onClick={rename}>
                            Переименовать
                        </Button>
                        <Button variant="destructive" className="flex-1" onClick={destroy}>
                            Удалить
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
