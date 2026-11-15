import { useCallback, useEffect, useRef, useState } from 'react';
import type { Room } from './types';

export async function roomRequest(
    url: string,
    method = 'GET',
    body?: object,
    signal?: AbortSignal,
): Promise<Room> {
    const token = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const controller = new AbortController();
    const cancel = () => controller.abort();

    if (signal?.aborted) {
        controller.abort();
    } else {
        signal?.addEventListener('abort', cancel, { once: true });
    }

    const deadline = setTimeout(() => controller.abort(), 12000);

    try {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
            },
            ...(body ? { body: JSON.stringify(body) } : {}),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(
                response.status === 419
                    ? 'Сессия истекла. Обновите страницу.'
                    : response.status === 404
                      ? 'Эфир завершён или срок ссылки истёк.'
                      : data.message || 'Не удалось связаться с сервером.',
            );
        }

        return data;
    } catch (reason) {
        if (controller.signal.aborted && !signal?.aborted) {
            throw new Error(
                'Сервер не ответил вовремя. Проверьте подключение и попробуйте ещё раз.',
            );
        }

        throw reason;
    } finally {
        clearTimeout(deadline);
        signal?.removeEventListener('abort', cancel);
    }
}

export function useLectureRoom(initialRoom: Room | null) {
    const [room, setRoom] = useState(initialRoom);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const mutating = useRef(false);
    const generation = useRef(0);
    const accept = useCallback((next: Room) => {
        setRoom((previous) =>
            previous &&
            previous.code === next.code &&
            previous.revision > next.revision
                ? previous
                : next,
        );
    }, []);
    const stateUrl = room?.stateUrl;
    const ended = room?.ended;
    useEffect(() => {
        if (!stateUrl || ended) {
            return;
        }

        const controller = new AbortController();
        let timer: ReturnType<typeof setTimeout>;
        async function refresh() {
            const requestedGeneration = generation.current;

            try {
                if (!mutating.current) {
                    const next = await roomRequest(
                        stateUrl!,
                        'GET',
                        undefined,
                        controller.signal,
                    );

                    if (
                        !controller.signal.aborted &&
                        !mutating.current &&
                        requestedGeneration === generation.current
                    ) {
                        accept(next);
                        setError('');
                    }
                }
            } catch (reason) {
                if (
                    !controller.signal.aborted &&
                    requestedGeneration === generation.current
                ) {
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Нет связи. Восстанавливаем подключение…',
                    );
                }
            } finally {
                if (!controller.signal.aborted) {
                    timer = setTimeout(refresh, document.hidden ? 12000 : 3500);
                }
            }
        }
        timer = setTimeout(refresh, 1200);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [stateUrl, ended, accept]);
    const send = async (url: string, method: string, body: object) => {
        if (mutating.current) {
            return;
        }

        generation.current += 1;
        mutating.current = true;
        setBusy(true);
        setError('');

        try {
            accept(await roomRequest(url, method, body));
        } catch (reason) {
            setError(
                reason instanceof Error
                    ? reason.message
                    : 'Не удалось сохранить ответ. Попробуйте ещё раз.',
            );
        } finally {
            mutating.current = false;
            setBusy(false);
        }
    };

    return { room, error, busy, send };
}

export function formatVoteCount(count: number): string {
    const form = new Intl.PluralRules('ru').select(count);
    const word =
        form === 'one' ? 'ответ' : form === 'few' ? 'ответа' : 'ответов';

    return count + ' ' + word;
}
