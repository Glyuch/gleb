import { useCallback, useEffect, useRef, useState } from 'react';
import {
    clampPosition,
    confirmedPosition,
    movePosition,
    positionFromHash,
    presentationTarget,
} from './presentation';
import type { Position } from './presentation';
import { scenes } from './scenes';
import type { Room } from './types';

type Sender = (
    url: string,
    method: string,
    body: object,
) => Promise<Room | null>;
type Pending = { position: Position; body: object };
const storageKey = 'lecture-clicker-v1';
function restoredRun(room: Room | null) {
    try {
        const saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');

        if (
            room &&
            !room.ended &&
            saved?.code === room.code &&
            saved.position
        ) {
            const position = clampPosition(scenes, saved.position);

            return {
                active: true,
                position: confirmedPosition(
                    position,
                    scenes[position.slide],
                    room,
                ),
            };
        }
    } catch {
        /* Storage is optional. */
    }

    return {
        active: false,
        position: clampPosition(
            scenes,
            positionFromHash(
                typeof window === 'undefined' ? '' : window.location.hash,
            ),
        ),
    };
}
export function usePresentation(
    room: Room | null,
    send: Sender,
    createRoomUrl: string | null,
    isPresenter: boolean,
) {
    const [playback, setPlayback] = useState(() =>
        restoredRun(isPresenter ? room : null),
    );
    const [pending, setPending] = useState<Pending | null>(null);
    const [transitioning, setTransitioning] = useState(false);
    const locked = useRef(false);
    const active = isPresenter && playback.active;
    const position = playback.position;
    const commit = useCallback(
        (next: Position, nextRoom: Room | null, live: boolean) => {
            setPlayback({ active: live, position: next });
            window.history.replaceState(
                null,
                '',
                '#slide-' +
                    (next.slide + 1) +
                    (live ? '-step-' + (next.step + 1) : ''),
            );

            try {
                if (live && nextRoom && !nextRoom.ended) {
                    sessionStorage.setItem(
                        storageKey,
                        JSON.stringify({ code: nextRoom.code, position: next }),
                    );
                } else {
                    sessionStorage.removeItem(storageKey);
                }
            } catch {
                /* Private browsing can disable session storage. */
            }
        },
        [],
    );
    const navigate = useCallback(
        async (requested: Position) => {
            if (locked.current) {
                return false;
            }

            const target = clampPosition(scenes, requested);

            if (!active) {
                commit({ ...target, step: 0 }, room, false);

                return true;
            }

            if (!room?.manageUrl || room.ended) {
                return false;
            }

            locked.current = true;
            setTransitioning(true);

            try {
                const targetState = presentationTarget(
                    scenes[target.slide],
                    target.step,
                );
                // Content steps need no round trip once the previous poll is closed.
                const needsRequest =
                    pending ||
                    targetState.questionId !== null ||
                    room.question !== null;
                let nextRoom = room;
                let nextPosition = target;

                if (needsRequest) {
                    const command = pending ?? {
                        position: target,
                        body: {
                            action: 'present',
                            commandId: crypto.randomUUID(),
                            ...targetState,
                        },
                    };
                    const result = await send(
                        room.manageUrl,
                        'PATCH',
                        command.body,
                    );

                    if (!result) {
                        setPending(command);

                        return false;
                    }

                    nextRoom = result;
                    nextPosition = confirmedPosition(
                        command.position,
                        scenes[command.position.slide],
                        result,
                    );
                }

                setPending(null);
                commit(nextPosition, nextRoom, true);

                return true;
            } finally {
                locked.current = false;
                setTransitioning(false);
            }
        },
        [active, commit, pending, room, send],
    );
    const start = useCallback(async () => {
        if (locked.current || !createRoomUrl) {
            return false;
        }

        locked.current = true;
        setTransitioning(true);

        try {
            const nextRoom =
                room && !room.ended
                    ? room
                    : await send(createRoomUrl, 'POST', { reuse: true });

            if (!nextRoom?.manageUrl) {
                return false;
            }

            // A new launch starts with a stable QR and no question accepting votes.
            const ready = await send(nextRoom.manageUrl, 'PATCH', {
                action: 'present',
                commandId: crypto.randomUUID(),
                questionId: null,
                phase: 'waiting',
            });

            if (!ready) {
                return false;
            }

            setPending(null);
            commit({ slide: 0, step: 0 }, ready, true);

            return true;
        } finally {
            locked.current = false;
            setTransitioning(false);
        }
    }, [commit, createRoomUrl, room, send]);
    const move = useCallback(
        async (direction: 1 | -1) => {
            if (locked.current) {
                return;
            }

            if (!active) {
                await navigate({ slide: position.slide + direction, step: 0 });

                return;
            }

            if (pending) {
                await navigate(pending.position);

                return;
            }

            if (
                scenes[position.slide].kind === 'finish' &&
                position.step === 0 &&
                direction === 1 &&
                room?.manageUrl &&
                !room.ended
            ) {
                locked.current = true;
                setTransitioning(true);

                try {
                    const ended = await send(room.manageUrl, 'PATCH', {
                        action: 'end',
                    });

                    if (ended) {
                        commit({ ...position, step: 1 }, ended, true);
                    }
                } finally {
                    locked.current = false;
                    setTransitioning(false);
                }

                return;
            }

            if (room?.ended && scenes[position.slide].kind === 'finish') {
                commit({ ...position, step: 1 }, room, true);

                return;
            }

            const revealed =
                room?.question?.id === scenes[position.slide].poll &&
                room?.revealed;
            await navigate(
                movePosition(scenes, position, direction, Boolean(revealed)),
            );
        },
        [active, commit, navigate, pending, position, room, send],
    );
    const go = useCallback(
        (slide: number) => {
            void navigate({ slide, step: 0 });
        },
        [navigate],
    );
    const setStep = useCallback(
        (step: number) => {
            void navigate({ ...position, step });
        },
        [navigate, position],
    );
    useEffect(() => {
        const readHash = () => {
            void navigate(positionFromHash(window.location.hash));
        };
        window.addEventListener('hashchange', readHash);

        return () => window.removeEventListener('hashchange', readHash);
    }, [navigate]);

    return {
        ...position,
        active,
        start,
        move,
        go,
        setStep,
        transitioning,
        retrying: pending !== null,
    };
}
