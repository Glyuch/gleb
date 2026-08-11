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

interface Props {
    assignmentId: number | null;
    board: Board;
    onClose: () => void;
}

export default function AssignmentDialog({
    assignmentId,
    board,
    onClose,
}: Props) {
    const object = board.objects.find((o) =>
        o.assignments.some((a) => a.id === assignmentId),
    );
    const assignment = object?.assignments.find((a) => a.id === assignmentId);
    const person = board.people.find((p) => p.id === assignment?.person_id);

    const [comment, setComment] = useState('');
    const [roleLabel, setRoleLabel] = useState(assignment?.role_label ?? '');
    const [roleType, setRoleType] = useState<RoleType>(
        assignment?.role_type ?? 'owner',
    );
    const [loadPercent, setLoadPercent] = useState(
        assignment?.load_percent ?? 50,
    );
    const [submitting, setSubmitting] = useState(false);

    if (!assignmentId || !object || !assignment) {
        return null;
    }

    const opts = {
        preserveScroll: true,
        onStart: () => setSubmitting(true),
        onFinish: () => setSubmitting(false),
        onSuccess: onClose,
    };

    // Нагрузка человека, если сохранить новую вовлечённость: помогает не переложить.
    const projectedLoad =
        (person?.load_percent ?? 0) - assignment.load_percent + loadPercent;
    const capacity = board.capacity_percent;
    const projectedStatus =
        projectedLoad > capacity
            ? 'over'
            : projectedLoad >= capacity * 0.9
              ? 'full'
              : 'ok';

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[85vh] max-w-sm overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {assignment.person_name ?? '?'} на {object.emoji}{' '}
                        {object.name}
                    </DialogTitle>
                </DialogHeader>
                <p className="text-xs text-gray-500">
                    с {assignment.started_at} · {assignment.days} дн · нагрузка
                    человека станет{' '}
                    <b className={LOAD_TONE[projectedStatus].text}>
                        {projectedLoad}%
                    </b>
                </p>
                <form
                    className="space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.patch(
                            `/shtab/assignments/${assignment.id}`,
                            {
                                role_label: roleLabel.trim() || null,
                                role_type: roleType,
                                load_percent: loadPercent,
                                comment: comment.trim() || null,
                            },
                            opts,
                        );
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
                        <Label htmlFor="role_label_edit">Уточнение роли</Label>
                        <Input
                            id="role_label_edit"
                            value={roleLabel}
                            onChange={(e) => setRoleLabel(e.target.value)}
                            placeholder="аналитика / переговоры…"
                        />
                    </div>
                    <div>
                        <Label htmlFor="end_comment">
                            Комментарий (в Хронику)
                        </Label>
                        <Input
                            id="end_comment"
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                            placeholder="релиз вышел / передал Диме…"
                        />
                    </div>
                    <Button
                        type="submit"
                        disabled={submitting}
                        className="w-full"
                    >
                        Сохранить роль
                    </Button>
                </form>
                <Button
                    variant="destructive"
                    onClick={() =>
                        router.post(
                            `/shtab/assignments/${assignment.id}/end`,
                            { comment: comment.trim() || null },
                            opts,
                        )
                    }
                    disabled={submitting}
                    className="w-full"
                >
                    Снять с территории
                </Button>
            </DialogContent>
        </Dialog>
    );
}
