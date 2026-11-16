import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import Module from 'node:module';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import * as icons from 'lucide-react';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import ts from 'typescript';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const modules = new Map();
function load(filename) {
    if (modules.has(filename)) {
        return modules.get(filename).exports;
    }

    const code = ts.transpileModule(readFileSync(filename, 'utf8'), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            jsx: ts.JsxEmit.ReactJSX,
            target: ts.ScriptTarget.ES2022,
            esModuleInterop: true,
        },
    }).outputText;
    const mod = new Module(filename);
    mod.filename = filename;
    mod.paths = Module._nodeModulePaths(dirname(filename));
    const original = mod.require.bind(mod);
    mod.require = (name) => {
        if (name.endsWith('.css')) {
            return {};
        }

        if (name === 'lucide-react') {
            return icons;
        }

        if (name === '@inertiajs/react') {
            return {
                Head: () => null,
                Link: ({ children, ...props }) =>
                    React.createElement('a', props, children),
            };
        }

        if (name === '@/actions/App/Http/Controllers/LectureController') {
            return { show: { url: () => '/lecture' } };
        }

        if (name.startsWith('.')) {
            const relative = resolve(dirname(filename), name);
            const path = [relative, relative + '.ts', relative + '.tsx'].find(
                existsSync,
            );

            if (path && /\.tsx?$/.test(path)) {
                return load(path);
            }
        }

        return original(name);
    };
    modules.set(filename, mod);
    mod._compile(code, filename);

    return mod.exports;
}
const fromLecture = (name) =>
    load(resolve(root, 'resources/js/pages/lecture/' + name));
const render = (Component, props) =>
    renderToStaticMarkup(React.createElement(Component, props));
const questionConfig = JSON.parse(
    execFileSync(
        'php',
        [
            '-r',
            'function storage_path($path) { return $path; } $config = require "config/lecture.php"; echo json_encode($config["questions"], JSON_THROW_ON_ERROR);',
        ],
        { cwd: root, encoding: 'utf8' },
    ),
);
const questions = ['profession', 'savings_rate', 'financial_level'].map(
    (id) => ({ id, ...questionConfig[id] }),
);
const question = (id) => questions.find((item) => item.id === id);
const room = (overrides = {}) => ({
    code: 'ABC123',
    expiresAt: '2026-11-17T00:00:00Z',
    joinUrl: 'https://gleb.finance/lecture/join/ABC123',
    qrUrl: '/lecture/rooms/ABC123/qr.svg',
    stateUrl: '/lecture/rooms/ABC123/state',
    voteUrl: '/lecture/rooms/ABC123/vote',
    manageUrl: null,
    canManage: false,
    question: null,
    revision: 4,
    open: false,
    revealed: false,
    ended: false,
    totalVotes: 0,
    counts: null,
    myVote: null,
    portrait: [],
    ...overrides,
});
const { distribution, cloudWords } = fromLecture('portrait-data.ts');
const { ProfessionCloud, default: AudiencePortrait } = fromLecture(
    'audience-portrait.tsx',
);
const Poll = fromLecture('poll.tsx').default;
const Join = fromLecture('join.tsx').default;

test('distribution calculates shares from actual answers and handles empty data', () => {
    const rows = distribution(['a', 'b', 'c'], [1, 2, 5]);
    assert.deepEqual(
        rows.map((row) => row.count),
        [1, 2, 5],
    );
    assert.deepEqual(
        rows.map((row) => row.share),
        [0.125, 0.25, 0.625],
    );
    assert.deepEqual(
        rows.map((row) => row.percent),
        [13, 25, 63],
    );

    for (const counts of [null, undefined, [], [0, 0, 0]]) {
        assert.deepEqual(
            distribution(['a', 'b', 'c'], counts).map((row) => [
                row.count,
                row.share,
                row.percent,
            ]),
            [
                [0, 0, 0],
                [0, 0, 0],
                [0, 0, 0],
            ],
        );
    }

    assert.deepEqual(distribution([], [9]), []);
    assert.deepEqual(
        distribution(['a', 'b'], [-2, 3, 99]).map((row) => row.percent),
        [0, 100],
    );
});

test('cloud sizes follow vote counts regardless of label length and are deterministic', () => {
    const options = [
        'Очень длинное название профессиональной деятельности',
        'Дизайн',
        'Кино',
        'Музыка',
    ];

    for (const compact of [false, true]) {
        const words = cloudWords(options, [2, 1, 1, 0], compact);
        assert.deepEqual(
            words.map((word) => word.index),
            [0, 1, 2],
        );
        assert.ok(
            words[0].fontSize >= words[1].fontSize,
            'the most popular long label must not appear smaller',
        );
        assert.equal(
            words[1].fontSize,
            words[2].fontSize,
            'equal votes have equal visual weight',
        );
        assert.deepEqual(words, cloudWords(options, [2, 1, 1, 0], compact));
        const empty = cloudWords(options, null, compact);
        assert.equal(empty.length, options.length);
        assert.equal(new Set(empty.map((word) => word.fontSize)).size, 1);
        assert.ok(
            empty.every(
                (word) =>
                    word.count === 0 &&
                    word.share === 0 &&
                    Number.isFinite(word.fontSize),
            ),
        );
    }
});

test('profession cloud ignores all supplied counts until reveal and avoids empty-data results', () => {
    const options = question('profession').options;
    const counts = options.map((_, index) => (index === 3 ? 42 : 0));
    const hidden = render(ProfessionCloud, {
        options,
        counts,
        revealed: false,
    });
    assert.equal(
        hidden,
        render(ProfessionCloud, { options, counts: null, revealed: false }),
    );
    assert.equal((hidden.match(/role="listitem"/g) || []).length, 12);
    assert.doesNotMatch(hidden, /<small|42|\d+%/);
    const shown = render(ProfessionCloud, { options, counts, revealed: true });
    assert.equal((shown.match(/role="listitem"/g) || []).length, 1);
    assert.match(shown, /42 ответа/);
    assert.match(shown, /Музыка и звук/);
    const empty = render(ProfessionCloud, {
        options,
        counts: [],
        revealed: true,
    });
    assert.match(empty, /Пока нет ответов/);
    assert.doesNotMatch(empty, /<small|\d+%|NaN|Infinity/);
});

test('summary preserves all three revealed distributions while current question is null', () => {
    const portrait = [
        {
            questionId: 'profession',
            counts: [3, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            totalVotes: 4,
        },
        {
            questionId: 'savings_rate',
            counts: [0, 1, 0, 0, 0, 3],
            totalVotes: 4,
        },
        { questionId: 'financial_level', counts: [1, 0, 3, 0], totalVotes: 4 },
    ];
    const markup = render(AudiencePortrait, {
        questions,
        room: room({ portrait }),
    });
    assert.equal((markup.match(/<article/g) || []).length, 3);
    assert.equal((markup.match(/class="savings-column"/g) || []).length, 6);
    assert.equal((markup.match(/class="experience-row"/g) || []).length, 4);
    assert.match(markup, /75%/);
    assert.match(markup, /25%/);
    assert.match(markup, /Дизайн/);
    assert.match(markup, /Архитектура/);
    assert.doesNotMatch(markup, /Ждём результаты|Результаты появятся/);
    assert.equal(
        markup,
        render(AudiencePortrait, {
            questions,
            room: room({
                portrait,
                question: question('savings_rate'),
                counts: [999, 0, 0, 0, 0, 0],
                totalVotes: 999,
            }),
        }),
    );
});

test('pending summary never invents percentages or uses unrevealed current counts', () => {
    for (const state of [
        null,
        room(),
        room({
            question: question('savings_rate'),
            counts: [99, 0, 0, 0, 0, 0],
            totalVotes: 99,
        }),
    ]) {
        const markup = render(AudiencePortrait, { questions, room: state });
        assert.equal((markup.match(/Ждём результаты/g) || []).length, 3);
        assert.doesNotMatch(
            markup,
            /\d+(?:[.,]\d+)?%|class="savings-column"|class="experience-row"|99 ответ/,
        );
    }
});

test('savings poll shows six fixed options before reveal and six result columns after reveal', () => {
    const savings = question('savings_rate');
    assert.equal(savings.options.length, 6);
    const common = {
        question: savings,
        isPresenter: false,
        busy: false,
        onAction: () => {},
    };
    const markup = render(Poll, {
        ...common,
        room: room({ question: savings, open: true }),
    });
    assert.equal((markup.match(/class="poll-option /g) || []).length, 6);
    assert.match(markup, /Около 5%/);
    assert.match(markup, /Больше 30%/);
    assert.doesNotMatch(markup, /class="savings-column"/);
    const results = render(Poll, {
        ...common,
        room: room({
            question: savings,
            revealed: true,
            counts: [0, 1, 0, 0, 0, 3],
            totalVotes: 4,
        }),
    });
    assert.equal((results.match(/class="savings-column"/g) || []).length, 6);
    assert.doesNotMatch(results, /class="poll-option /);
    assert.match(results, /Около 5%: 1 ответ, 25% аудитории/);
    assert.match(results, /Больше 30%: 3 ответа, 75% аудитории/);
});

test('audience can select every creative field and savings option without free text', () => {
    for (const [id, count] of [
        ['profession', 12],
        ['savings_rate', 6],
    ]) {
        const item = question(id);
        assert.equal(item.options.length, count);
        const markup = render(Join, {
            room: room({ question: item, open: true }),
        });
        assert.equal((markup.match(/<button\b/g) || []).length, count);

        for (const option of item.options) {
            assert.ok(markup.includes(option), option);
        }

        assert.doesNotMatch(
            markup,
            /<(?:input|textarea)\b|contenteditable=|disabled=""/,
        );
    }

    const creative = render(Join, {
        room: room({ question: question('profession'), open: true }),
    });
    assert.match(creative, /Кино и видео/);
    assert.match(creative, /Продюсирование и ивенты/);
    assert.match(creative, /Учусь \/ начинаю/);
});
