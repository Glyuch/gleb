import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import { scenes } from '../../resources/js/pages/lecture/scenes.ts';
import * as flow from '../../resources/js/pages/lecture/presentation.ts';

test('every poll requires separate waiting, open, closed and results steps', () => {
    for (const scene of scenes.filter((s) => s.poll)) {
        assert.deepEqual(
            Array.from(
                { length: flow.stepCount(scene) },
                (_, i) => flow.presentationTarget(scene, i).phase,
            ),
            ['waiting', 'open', 'closed', 'results'],
        );
    }
});
test('complete forward run includes all scenes, all four money routes, and explicit finish', () => {
    let p = { slide: 0, step: 0 };
    const positions = [];
    for (let i = 0; i < 200; i++) {
        positions.push(p);
        const next = flow.movePosition(scenes, p, 1);
        if (JSON.stringify(next) === JSON.stringify(p)) break;
        p = next;
    }
    assert.equal(
        positions.length,
        scenes.reduce((n, s) => n + flow.stepCount(s), 0),
    );
    assert.equal(
        positions.filter((p) => scenes[p.slide].stage === 7).length,
        16,
    );
    assert.deepEqual(positions.at(-1), { slide: 23, step: 1 });
    assert.equal(
        scenes.reduce((sum, s) => sum + s.minutes, 0),
        40,
    );
});
test('backward navigation never reveals an unanswered poll', () => {
    assert.deepEqual(flow.movePosition(scenes, { slide: 2, step: 0 }, -1), {
        slide: 1,
        step: 0,
    });
    assert.deepEqual(
        flow.movePosition(scenes, { slide: 1, step: 3 }, -1, true),
        { slide: 0, step: 0 },
    );
});
test('revealed answers restore their results step and never reopen', () => {
    assert.deepEqual(
        flow.confirmedPosition({ slide: 1, step: 0 }, scenes[1], {
            question: { id: 'experience' },
            revealed: true,
        }),
        { slide: 1, step: 3 },
    );
});
test('clicker keys, bounded URL steps and content poll clearing', () => {
    for (const key of ['ArrowRight', 'ArrowDown', 'PageDown', ' ', 'Enter'])
        assert.equal(flow.clickerDirection(key), 1);
    for (const key of ['ArrowLeft', 'ArrowUp', 'PageUp', 'Backspace'])
        assert.equal(flow.clickerDirection(key), -1);
    assert.equal(flow.clickerDirection('Escape'), null);
    assert.deepEqual(flow.positionFromHash('#slide-11-step-12'), {
        slide: 10,
        step: 11,
    });
    assert.deepEqual(flow.clampPosition(scenes, { slide: 100, step: 100 }), {
        slide: 23,
        step: 1,
    });
    assert.deepEqual(flow.presentationTarget(scenes[2], 0), {
        questionId: null,
        phase: 'waiting',
    });
});

// Exercise the real hook's asynchronous transitions with a deterministic hook host.
// React rendering is covered separately by the component SSR checks.
function harness(initialRoom, handler) {
    const state = [];
    let cursor = 0;
    let room = initialRoom;
    const sent = [];
    const storage = new Map();
    globalThis.sessionStorage = {
        getItem: (k) => storage.get(k) ?? null,
        setItem: (k, v) => storage.set(k, v),
        removeItem: (k) => storage.delete(k),
    };
    globalThis.window = {
        location: { hash: '' },
        history: {
            replaceState: (_a, _b, hash) => {
                window.location.hash = hash;
            },
        },
        addEventListener() {},
        removeEventListener() {},
    };
    const react = {
        useState(initial) {
            const i = cursor++;
            if (!(i in state))
                state[i] = typeof initial === 'function' ? initial() : initial;
            return [
                state[i],
                (v) => {
                    state[i] = typeof v === 'function' ? v(state[i]) : v;
                },
            ];
        },
        useRef(initial) {
            const i = cursor++;
            return state[i] ?? (state[i] = { current: initial });
        },
        useCallback(fn) {
            return fn;
        },
        useEffect() {},
    };
    const source = ts.transpileModule(
        readFileSync(
            new URL(
                '../../resources/js/pages/lecture/use-presentation.ts',
                import.meta.url,
            ),
            'utf8',
        ),
        {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        },
    ).outputText;
    const exports = {};
    new Function('require', 'exports', source)(
        (name) =>
            name === 'react'
                ? react
                : name === './presentation'
                  ? flow
                  : name === './scenes'
                    ? { scenes }
                    : null,
        exports,
    );
    const send = async (url, method, body) => {
        sent.push({ url, method, body });
        const result = await handler(url, method, body, room);
        if (result) room = result;
        return result;
    };
    return {
        sent,
        render() {
            cursor = 0;
            return exports.usePresentation(room, send, '/create', true);
        },
    };
}
const baseRoom = {
    code: 'ABC123',
    manageUrl: '/manage',
    stateUrl: '/state',
    question: null,
    open: false,
    revealed: false,
    ended: false,
};
function successful(_url, _method, body, room) {
    if (_url === '/create') return { ...baseRoom, code: 'NEW234' };
    if (body.action === 'end') return { ...room, ended: true, open: false };
    return {
        ...(room ?? baseRoom),
        question: body.questionId ? { id: body.questionId } : null,
        open: body.phase === 'open',
        revealed: body.phase === 'results',
    };
}
test('launch creates a room once and moves poll only after acknowledgement; failed click retries same command', async () => {
    let failOpen = true;
    const h = harness(null, async (u, m, b, r) =>
        b.phase === 'open' && failOpen ? null : successful(u, m, b, r),
    );
    assert.equal(await h.render().start(), true);
    assert.equal(h.sent.filter((c) => c.url === '/create').length, 1);
    await h.render().move(1);
    assert.equal(h.render().slide, 1);
    assert.equal(h.render().step, 0);
    await h.render().move(1);
    assert.equal(h.render().step, 0);
    assert.equal(h.render().retrying, true);
    const failed = h.sent.at(-1).body;
    failOpen = false;
    await h.render().move(1);
    assert.deepEqual(h.sent.at(-1).body, failed);
    assert.equal(h.render().step, 1);
    await h.render().move(1);
    assert.equal(h.render().step, 2);
    await h.render().move(1);
    assert.equal(h.render().step, 3);
    await h.render().move(1);
    assert.equal(h.render().slide, 2);
    assert.equal(h.sent.at(-1).body.questionId, null);
});
test('repeated clicks while request is in flight cannot skip a voting phase', async () => {
    let release;
    const h = harness(baseRoom, async (u, m, b, r) =>
        b.phase === 'open'
            ? await new Promise((resolve) => {
                  release = () => resolve(successful(u, m, b, r));
              })
            : successful(u, m, b, r),
    );
    await h.render().start();
    await h.render().move(1);
    const first = h.render().move(1);
    await h.render().move(1);
    assert.equal(h.sent.filter((c) => c.body.phase === 'open').length, 1);
    release();
    await first;
    assert.equal(h.render().step, 1);
});
test('the final click ends the room and a new launch creates a fresh session', async () => {
    const h = harness(baseRoom, successful);
    await h.render().start();
    for (
        let i = 0;
        i < 150 && !h.sent.some((c) => c.body.action === 'end');
        i++
    )
        await h.render().move(1);
    assert.equal(h.sent.filter((c) => c.body.action === 'end').length, 1);
    assert.equal(h.render().slide, 23);
    assert.equal(h.render().step, 1);
    await h.render().start();
    assert.equal(h.sent.filter((c) => c.url === '/create').length, 1);
    assert.equal(h.render().slide, 0);
    assert.equal(h.render().active, true);
    await h.render().move(1);
    assert.equal(h.render().slide, 1);
});
