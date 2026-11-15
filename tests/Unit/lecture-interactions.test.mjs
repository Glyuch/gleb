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

function loadComponent(filename) {
    if (modules.has(filename)) return modules.get(filename).exports;
    const compiled = ts.transpileModule(readFileSync(filename, 'utf8'), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            jsx: ts.JsxEmit.ReactJSX,
            target: ts.ScriptTarget.ES2022,
            esModuleInterop: true,
        },
    });
    const loaded = new Module(filename);
    loaded.filename = filename;
    loaded.paths = Module._nodeModulePaths(dirname(filename));
    const originalRequire = loaded.require.bind(loaded);
    loaded.require = (specifier) => {
        if (specifier.endsWith('.css')) return {};
        if (specifier === 'lucide-react') return icons;
        if (specifier.startsWith('.')) {
            const relative = resolve(dirname(filename), specifier);
            const source = [relative, relative + '.ts', relative + '.tsx'].find(
                (path) => existsSync(path),
            );
            if (source && /\.tsx?$/.test(source)) return loadComponent(source);
        }
        return originalRequire(specifier);
    };
    modules.set(filename, loaded);
    loaded._compile(compiled.outputText, filename);
    return loaded.exports;
}

const interactions = loadComponent(
    resolve(root, 'resources/js/pages/lecture/interactions.tsx'),
);
const MarketMap = loadComponent(
    resolve(root, 'resources/js/pages/lecture/market-map.tsx'),
).default;
const { marketFlows } = loadComponent(
    resolve(root, 'resources/js/pages/lecture/market-data.ts'),
);
const render = (Component, props = {}) =>
    renderToStaticMarkup(React.createElement(Component, props));

for (const [name, expected] of [
    [
        'Goals',
        [
            'Доступность и сохранность',
            'Совпадение срока и риска',
            'Стратегия и диверсификация',
        ],
    ],
    [
        'Platform',
        [
            'На что копим?',
            'Что подходит цели?',
            'Оформляем онлайн',
            'Продолжаем регулярно',
        ],
    ],
    [
        'Funding',
        [
            'Кто клиент и за что он готов заплатить?',
            'Остаются ли деньги с каждой продажи?',
            'Из каких поступлений будем платить?',
            'Как капитал увеличит будущий результат?',
            'Готовы ли мы к прозрачности перед рынком?',
        ],
    ],
    [
        'Pitch',
        [
            'Сумма → действия → проверяемый результат',
            'Продажи → маржа → денежный поток',
            'Срок → права → распределение результата',
            'Сценарии → запас денег → действия команды',
        ],
    ],
]) {
    test(`${name} renders every controlled step from props`, () => {
        expected.forEach((text, presentationStep) => {
            const html = render(interactions[name], {
                presentationStep,
                onPresentationStepChange: () => {},
            });
            assert.ok(
                html.includes(text),
                `${name} step ${presentationStep} is missing its content`,
            );
            assert.ok(
                !html.includes('disabled=""'),
                `${name} supports callback-backed manual selection`,
            );
        });
    });
    test(`${name} retains manual default and bounds invalid steps`, () => {
        assert.ok(render(interactions[name]).includes(expected[0]));
        assert.ok(
            render(interactions[name], { presentationStep: -10 }).includes(
                expected[0],
            ),
        );
        assert.ok(
            render(interactions[name], { presentationStep: 100 }).includes(
                expected.at(-1),
            ),
        );
    });
}

test('Moneybox reveals its inner mechanism only on the reveal step', () => {
    assert.ok(!render(interactions.Moneybox).includes('mechanism-reveal'));
    assert.ok(
        !render(interactions.Moneybox, { presentationStep: 0 }).includes(
            'mechanism-reveal',
        ),
    );
    assert.ok(
        render(interactions.Moneybox, { presentationStep: 1 }).includes(
            'mechanism-reveal',
        ),
    );
});

test('Statements presents payment scenarios without changing accounting profit', () => {
    [100, 60, 80].forEach((paid, presentationStep) => {
        const html = render(interactions.Statements, { presentationStep });
        assert.match(html, new RegExp(`id="paid"[^>]*value="${paid}"`));
        assert.ok(html.includes('100 − 70 ='));
        assert.ok(html.includes('disabled=""'));
    });
    assert.match(render(interactions.Statements), /id="paid"[^>]*value="60"/);
});

test('SavingsCalculator presets isolate contribution and duration changes', () => {
    [
        [10000, 10],
        [20000, 10],
        [20000, 20],
    ].forEach(([monthly, years], presentationStep) => {
        const html = render(interactions.SavingsCalculator, {
            presentationStep,
        });
        assert.match(
            html,
            new RegExp(`aria-label="Ежемесячный взнос"[^>]*value="${monthly}"`),
        );
        assert.match(
            html,
            new RegExp(
                `aria-label="Срок накопления в годах"[^>]*value="${years}"`,
            ),
        );
        assert.match(
            html,
            /aria-label="Расчётная годовая доходность"[^>]*value="8"/,
        );
        assert.ok(html.includes('disabled=""'));
    });
    assert.ok(!render(interactions.SavingsCalculator).includes('disabled=""'));
});

test('The market clicker visits all four routes and all four steps in order', () => {
    for (let presentationStep = 0; presentationStep < 16; presentationStep++) {
        const flow = marketFlows[Math.floor(presentationStep / 4)];
        const step = flow.steps[presentationStep % 4];
        const html = render(MarketMap, {
            stage: 7,
            presentationStep,
            onPresentationStepChange: () => {},
        });
        assert.ok(
            html.includes(step.title),
            `Missing map step ${presentationStep}: ${step.title}`,
        );
        assert.ok(
            html.includes(step.text),
            `Missing map explanation ${presentationStep}`,
        );
        assert.equal(html.includes('Другой инвестор'), presentationStep < 4);
    }
});

test('Controlled flow is ignored before the full-map chapter; manual full map starts without a route', () => {
    const html = render(MarketMap, { stage: 3, presentationStep: 9 });
    assert.ok(html.includes('Управляющая компания'));
    assert.ok(!html.includes('mm-flow-progress'));
    assert.ok(!render(MarketMap, { stage: 7 }).includes('mm-flow-progress'));
});
