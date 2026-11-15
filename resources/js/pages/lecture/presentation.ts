import type { Scene } from './scenes';
import type { Room } from './types';

export type Position = { slide: number; step: number };
export const pollPhases = ['waiting', 'open', 'closed', 'results'] as const;
export function stepCount(scene: Scene): number {
    if (scene.kind === 'map') {
        return scene.stage === 7 ? 16 : 1;
    }

    return (
        (
            {
                poll: 4,
                goals: 3,
                calculator: 3,
                platform: 4,
                moneybox: 2,
                funding: 5,
                pitch: 4,
                finish: 2,
            } as Record<string, number>
        )[scene.kind] ?? 1
    );
}
export function clampPosition(scenes: Scene[], position: Position): Position {
    const slide = Math.max(
        0,
        Math.min(
            scenes.length - 1,
            Number.isFinite(position.slide) ? Math.trunc(position.slide) : 0,
        ),
    );
    const step = Math.max(
        0,
        Math.min(
            stepCount(scenes[slide]) - 1,
            Number.isFinite(position.step) ? Math.trunc(position.step) : 0,
        ),
    );

    return { slide, step };
}
export function movePosition(
    scenes: Scene[],
    position: Position,
    direction: 1 | -1,
    revealed = false,
): Position {
    const current = clampPosition(scenes, position);
    const scene = scenes[current.slide];

    if (direction === 1) {
        if (current.step + 1 < stepCount(scene)) {
            return { ...current, step: current.step + 1 };
        }

        return clampPosition(scenes, {
            slide: current.slide + 1,
            step: current.slide === scenes.length - 1 ? current.step : 0,
        });
    }

    if (current.step > 0 && !(scene.kind === 'poll' && revealed)) {
        return { ...current, step: current.step - 1 };
    }

    if (current.slide === 0) {
        return { slide: 0, step: 0 };
    }

    const previous = scenes[current.slide - 1];

    // Going backwards may review a question, but must never reveal its answer.
    return {
        slide: current.slide - 1,
        step: previous.kind === 'poll' ? 0 : stepCount(previous) - 1,
    };
}
export function presentationTarget(scene: Scene, step: number) {
    return {
        questionId: scene.poll ?? null,
        phase: scene.poll
            ? pollPhases[Math.max(0, Math.min(3, step))]
            : ('waiting' as const),
    };
}
export function confirmedPosition(
    position: Position,
    scene: Scene,
    room: Room,
): Position {
    return scene.poll && room.question?.id === scene.poll && room.revealed
        ? { ...position, step: 3 }
        : position;
}
export function positionFromHash(hash: string): Position {
    const match = hash.match(/^#slide-(\d+)(?:-step-(\d+))?$/);

    return {
        slide: match ? Number(match[1]) - 1 : 0,
        step: match?.[2] ? Number(match[2]) - 1 : 0,
    };
}
export function nextCue(scene: Scene, step: number): string {
    if (scene.kind === 'poll') {
        return [
            'Открыть голосование',
            'Закрыть голосование',
            'Показать результаты',
            'Следующий слайд',
        ][step];
    }

    if (scene.kind === 'finish') {
        return step === 0 ? 'Завершить лекцию' : 'Лекция завершена';
    }

    return step + 1 < stepCount(scene) ? 'Следующий шаг' : 'Следующий слайд';
}
export function clickerDirection(key: string): 1 | -1 | null {
    if (['ArrowRight', 'ArrowDown', 'PageDown', ' ', 'Enter'].includes(key)) {
        return 1;
    }

    if (['ArrowLeft', 'ArrowUp', 'PageUp', 'Backspace'].includes(key)) {
        return -1;
    }

    return null;
}
