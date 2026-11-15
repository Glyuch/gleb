import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import Module from 'node:module';
import { dirname, resolve } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import React from 'react';
import * as icons from 'lucide-react';
import { renderToStaticMarkup } from 'react-dom/server';
import ts from 'typescript';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const modules = new Map();
function load(filename) {
    if (modules.has(filename)) return modules.get(filename).exports;
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
        if (name.endsWith('.css')) return {};
        if (name === 'lucide-react') return icons;
        if (name === '@inertiajs/react')
            return {
                Head: () => null,
                Link: ({ children, ...props }) =>
                    React.createElement('a', props, children),
            };
        if (name === '@/actions/App/Http/Controllers/LectureController')
            return {
                presenter: { url: () => '/admin/lecture' },
                show: { url: () => '/lecture' },
            };
        if (name.startsWith('.')) {
            const relative = resolve(dirname(filename), name);
            const path = [relative, relative + '.ts', relative + '.tsx'].find(
                existsSync,
            );
            if (path && /\.tsx?$/.test(path)) return load(path);
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
test('both lecture modes render the supplied Finuslugi logo and all 22 navigation scenes', () => {
    const Lecture = fromLecture('index.tsx').default;
    for (const isPresenter of [false, true]) {
        const markup = render(Lecture, {
            isPresenter,
            questions: [],
            room: null,
            createRoomUrl: isPresenter ? '/create' : null,
        });
        assert.match(markup, /src="\/media\/finuslugi-logo.png"/);
        assert.match(markup, /alt="Финуслуги"/);
        assert.doesNotMatch(markup, /gleb<span>/);
        assert.equal((markup.match(/aria-label="\d+\. /g) || []).length, 22);
        assert.doesNotMatch(
            markup,
            /Прибыль есть\. Денег нет|Три отчёта — три разных вопроса/,
        );
    }
});
test('participant screen uses the same brand and the original PNG is valid', () => {
    const Join = fromLecture('join.tsx').default;
    const markup = render(Join, {
        room: {
            code: 'ABC123',
            ended: true,
            question: null,
            open: false,
            myVote: null,
        },
    });
    assert.match(markup, /alt="Финуслуги"/);
    const png = readFileSync(resolve(root, 'public/media/finuslugi-logo.png'));
    assert.equal(png.subarray(1, 4).toString(), 'PNG');
    assert.equal(png.readUInt32BE(16), 906);
    assert.equal(png.readUInt32BE(20), 607);
});
function declaration(css, selector, property) {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const blocks = [
        ...css.matchAll(
            new RegExp('(?:^|\\n)' + escaped + '\\s*\\{([^{}]*)\\}', 'g'),
        ),
    ];
    let result;
    for (const block of blocks) {
        const match = block[1].match(
            new RegExp(
                '(?:^|[;\\n])\\s*' + property + ':\\s*(#[0-9a-f]{6})\\b',
            ),
        );
        if (match) result = match[1];
    }
    assert.ok(result, selector + ' ' + property);
    return result;
}
function luminance(hex) {
    const rgb = hex
        .slice(1)
        .match(/../g)
        .map((h) => parseInt(h, 16) / 255)
        .map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
    return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
}
function contrast(a, b) {
    const x = luminance(a),
        y = luminance(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}
test('light palette keeps body, primary buttons and correct answers readable', () => {
    const css = readFileSync(
        resolve(root, 'resources/js/pages/lecture/lecture.css'),
        'utf8',
    );
    assert.ok(contrast('#606066', '#ffffff') >= 4.5);
    assert.ok(
        contrast(
            declaration(css, '.primary-button', 'color'),
            declaration(css, '.primary-button', 'background'),
        ) >= 4.5,
    );
    assert.ok(
        contrast(
            declaration(css, '.poll-option.correct', 'color'),
            '#f8f8f9',
        ) >= 4.5,
    );
    assert.ok(
        contrast(
            declaration(css, '.lecture-header .launch-lecture', 'color'),
            declaration(css, '.lecture-header .launch-lecture', 'background'),
        ) >= 4.5,
    );
});
