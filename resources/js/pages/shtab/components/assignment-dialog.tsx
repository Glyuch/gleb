import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Board } from '../types';

interface Props {
    assignmentId: number | null;
    board: Board;
    onClose: () => void;
}

export default function AssignmentDialog({ assignmentId, board, onClose }: Props) {
    const [comment, setComment] = useState('');

    if (!assignmentId) {
        return null;
    }

    const object = board.objects.find((o) => o.assignments.some((a) => a.id === assignmentId));
    const assignment = object?.assignments.find((a) => a.id === assignmentId);

    if (!object || !assignment) {
        return null;
    }

    const end = () => {
        router.post(
            `/shtab/assignments/${assignment.id}/end`,
            { comment: comment.trim() || null },
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>
                        {assignment.person_name ?? '?'} на {object.emoji} {object.name}
                    </DialogTitle>
                </DialogHeader>
                <p className="text-sm text-gray-600">
                    {assignment.role_label} · с {assignment.started_at} · {assignment.days} дн
                </p>
                <div className="space-y-3">
                    <div>
                        <Label htmlFor="end_comment">Комментарий к снятию</Label>
                        <Input id="end_comment" value={comment} onChange={(e) => setComment(e.target.value)} placeholder="релиз вышел / передал Диме…" />
                    </div>
                    <Button variant="destructive" onClick={end} className="w-full">
                        Снять с территории
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
