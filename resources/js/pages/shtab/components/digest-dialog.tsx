import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

interface DigestOperation {
    type: string;
    summary: string;
    [key: string]: unknown;
}

interface UnparsedItem {
    text: string;
    reason: string;
}

interface ApplyFailure {
    index: number;
    summary: string;
    reason: string;
}

interface DigestResponse {
    operations: DigestOperation[];
    unparsed: UnparsedItem[];
}

interface ApplyResponse {
    applied: number[];
    failed: ApplyFailure[];
}

type Phase = 'input' | 'loading' | 'preview' | 'result' | 'error';

interface Props {
    open: boolean;
    onClose: () => void;
}

function xsrfToken(): string {
    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie);

    return match ? decodeURIComponent(match[1]) : '';
}

async function postJson<T>(url: string, body: unknown, signal: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
        signal,
    });

    if (!response.ok) {
        throw new Error(`HTTP ${String(response.status)}`);
    }

    return (await response.json()) as T;
}

export default function DigestDialog({ open, onClose }: Props) {
    const [phase, setPhase] = useState<Phase>('input');
    const [text, setText] = useState('');
    const [operations, setOperations] = useState<DigestOperation[]>([]);
    const [unparsed, setUnparsed] = useState<UnparsedItem[]>([]);
    const [selected, setSelected] = useState<boolean[]>([]);
    const [applying, setApplying] = useState(false);
    const [result, setResult] = useState<ApplyResponse | null>(null);
    // Какая фаза упала: «Попробовать ещё раз» перезапускает именно её. Падение
    // применения возвращает к предпросмотру (выбор сохранён), а не к новому разбору.
    const [errorSource, setErrorSource] = useState<'digest' | 'apply'>('digest');
    const abortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        return () => abortRef.current?.abort();
    }, []);

    if (!open) {
        return null;
    }

    const digest = () => {
        if (phase === 'loading' || !text.trim()) {
            return;
        }

        const controller = new AbortController();
        abortRef.current = controller;
        setPhase('loading');

        postJson<DigestResponse>('/shtab/ai/digest', { text: text.trim() }, controller.signal)
            .then((data) => {
                setOperations(data.operations);
                setUnparsed(data.unparsed);
                setSelected(data.operations.map(() => true));
                setPhase('preview');
            })
            .catch((err: unknown) => {
                if (err instanceof DOMException && err.name === 'AbortError') {
                    return;
                }

                setErrorSource('digest');
                setPhase('error');
            });
    };

    const apply = () => {
        if (applying) {
            return;
        }

        const chosen = operations.filter((_, i) => selected[i]);
        const controller = new AbortController();
        abortRef.current = controller;
        setApplying(true);

        postJson<ApplyResponse>('/shtab/ai/apply', { operations: chosen, unparsed, text: text.trim() }, controller.signal)
            .then((data) => {
                setResult(data);
                setPhase('result');
            })
            .catch((err: unknown) => {
                if (err instanceof DOMException && err.name === 'AbortError') {
                    return;
                }

                setErrorSource('apply');
                setPhase('error');
            })
            .finally(() => setApplying(false));
    };

    const retry = () => {
        if (errorSource === 'apply') {
            setPhase('preview');
            apply();
        } else {
            digest();
        }
    };

    const finish = () => {
        // router.reload() preserves scroll and state by default in Inertia v3.
        router.reload();
        onClose();
    };

    const selectedCount = selected.filter(Boolean).length;

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>🤖 Рассказать штабу</DialogTitle>
                </DialogHeader>

                {phase === 'input' && (
                    <div className="space-y-3">
                        <textarea
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            rows={6}
                            maxLength={8000}
                            autoFocus
                            placeholder="Вика уходит с Обмена на KYC, маржа просела, у Димы завал…"
                            className="w-full resize-y rounded-md border border-gray-300 p-2 text-sm focus:border-violet-500 focus:outline-none"
                        />
                        <Button onClick={digest} disabled={!text.trim()} className="w-full">
                            Разобрать
                        </Button>
                    </div>
                )}

                {phase === 'loading' && (
                    <div className="flex flex-col items-center gap-3 py-8">
                        <Spinner className="size-8 text-violet-500" />
                        <p className="text-center text-sm text-gray-500">
                            Claude раскладывает твой рассказ по штабу… это может занять минуту-другую
                        </p>
                    </div>
                )}

                {phase === 'preview' && (
                    <div className="space-y-3">
                        {operations.length === 0 && <p className="text-sm text-gray-500">Операций не найдено.</p>}
                        {operations.length > 0 && (
                            <div className="max-h-72 space-y-2 overflow-y-auto">
                                {operations.map((op, i) => (
                                    <label key={i} className="flex items-start gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={selected[i] ?? false}
                                            onChange={(e) => setSelected(selected.map((v, j) => (j === i ? e.target.checked : v)))}
                                            className="mt-0.5"
                                        />
                                        <span>{op.summary}</span>
                                    </label>
                                ))}
                            </div>
                        )}
                        {unparsed.length > 0 && (
                            <div className="rounded-md border border-amber-300 bg-amber-50 p-3">
                                <p className="mb-1 text-xs font-bold text-amber-800">Не разнесено</p>
                                <ul className="space-y-1">
                                    {unparsed.map((u, i) => (
                                        <li key={i} className="text-xs text-amber-800">
                                            «{u.text}» — {u.reason}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        <div className="flex gap-2">
                            <Button onClick={apply} disabled={applying || selectedCount === 0} className="flex-1">
                                Применить выбранное ({selectedCount})
                            </Button>
                            <Button variant="outline" onClick={onClose} disabled={applying}>
                                Отмена
                            </Button>
                        </div>
                    </div>
                )}

                {phase === 'result' && result && (
                    <div className="space-y-3">
                        <p className="text-sm font-semibold text-gray-800">Применено операций: {result.applied.length}</p>
                        {result.failed.length > 0 && (
                            <div className="rounded-md border border-red-300 bg-red-50 p-3">
                                <p className="mb-1 text-xs font-bold text-red-800">Не удалось применить</p>
                                <ul className="space-y-1">
                                    {result.failed.map((f) => (
                                        <li key={f.index} className="text-xs text-red-800">
                                            {f.summary} — {f.reason}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        <Button onClick={finish} className="w-full">
                            Готово
                        </Button>
                    </div>
                )}

                {phase === 'error' && (
                    <div className="space-y-3">
                        <p className="text-sm text-red-600">ИИ недоступен, попробуй позже.</p>
                        <div className="flex gap-2">
                            <Button onClick={retry} className="flex-1">
                                Попробовать ещё раз
                            </Button>
                            <Button variant="outline" onClick={onClose}>
                                Отмена
                            </Button>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
