import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Board, RoleType } from '../types';
import { LOAD_TONE } from '../types';
import RoleFields from './role-fields';

export interface AssignIntent {
    objectId: number;
    personId: number | null; // null → показать пикер людей
    moveAssignmentId: number | null; // задан → это перенос с другой территории
}

interface Props {
    intent: AssignIntent | null;
    board: Board;
    onClose: () => void;
}

export default function AssignDialog({ intent, board, onClose }: Props) {
    const [personId, setPersonId] = useState<number | null>(null);
    const [roleLabel, setRoleLabel] = useState('');
    const [roleType, setRoleType] = useState<RoleType>('owner');
    const [loadPercent, setLoadPercent] = useState(
        board.roles.find((r) => r.key === 'owner')?.default_load ?? 50,
    );
    const [comment, setComment] = useState('');
    const [submitting, setSubmitting] = useState(false);

    if (!intent) {
        return null;
    }

    const object = board.objects.find((o) => o.id === intent.objectId);
    const chosenPersonId = intent.personId ?? personId;
    const alreadyThere = new Set(object?.assignments.map((a) => a.person_id));
    const candidates = board.people.filter((p) => !alreadyThere.has(p.id));

    const submit = () => {
        if (!chosenPersonId) {
            return;
        }

        const payload = {
            role_label: roleLabel.trim() || null,
            role_type: roleType,
            load_percent: loadPercent,
            comment: comment.trim() || null,
            object_id: intent.objectId,
        };
        const opts = {
            preserveScroll: true,
            onStart: () => setSubmitting(true),
            onFinish: () => setSubmitting(false),
            onSuccess: onClose,
        };

        if (intent.moveAssignmentId) {
            router.post(
                `/shtab/assignments/${intent.moveAssignmentId}/move`,
                payload,
                opts,
            );
        } else {
            router.post(
                '/shtab/assignments',
                { ...payload, person_id: chosenPersonId },
                opts,
            );
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[85vh] max-w-sm overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {intent.moveAssignmentId
                            ? 'Перенос на'
                            : 'Назначение на'}{' '}
                        {object?.emoji} {object?.name}
                    </DialogTitle>
                </DialogHeader>
                {intent.personId === null && (
                    <div className="flex flex-wrap gap-2">
                        {candidates.map((p) => (
                            <button
                                key={p.id}
                                type="button"
                                onClick={() => setPersonId(p.id)}
                                className={`cursor-pointer rounded-full border px-2 py-1 text-xs font-bold ${personId === p.id ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-300'}`}
                            >
                                {p.name}{' '}
                                <span
                                    className={
                                        personId === p.id
                                            ? 'text-gray-300'
                                            : LOAD_TONE[p.load_status].text
                                    }
                                >
                                    {p.load_percent}%
                                </span>
                            </button>
                        ))}
                    </div>
                )}
                <form
                    className="space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <RoleFields
                        roles={board.roles}
                        roleType={roleType}
                        loadPercent={loadPercent}
                        onRoleType={setRoleType}
                        onLoadPercent={setLoadPercent}
                    />
                    <div>
                        <Label htmlFor="role_label">
                            Уточнение роли (необязательно)
                        </Label>
                        <Input
                            id="role_label"
                            value={roleLabel}
                            onChange={(e) => setRoleLabel(e.target.value)}
                            placeholder="аналитика / переговоры с НРД…"
                        />
                    </div>
                    <div>
                        <Label htmlFor="assign_comment">
                            Почему (для Хроники)
                        </Label>
                        <Input
                            id="assign_comment"
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                            placeholder="на месяц, до релиза"
                        />
                    </div>
                    <Button
                        type="submit"
                        disabled={!chosenPersonId || submitting}
                        className="w-full"
                    >
                        {intent.moveAssignmentId ? 'Перенести' : 'Назначить'}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
