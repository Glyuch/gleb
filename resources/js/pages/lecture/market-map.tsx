import { useId, useState } from 'react';
import type { CSSProperties } from 'react';
import {
    marketFlows,
    marketLinks,
    marketNodes,
    marketStages,
    marketSources,
    secondarySeller,
} from './market-data';
import type { MarketNode } from './market-data';
import './market-map.css';

interface MarketMapProps {
    stage: number;
    presentationStep?: number;
    onPresentationStepChange?: (step: number) => void;
    onStageChange?: (stage: number) => void;
}

const nodeById = Object.fromEntries(
    [...marketNodes, secondarySeller].map((node) => [node.id, node]),
);

function connection(from: MarketNode, to: MarketNode): string {
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    const length = Math.hypot(dx, dy) || 1;
    const horizontal = Math.abs(dx / length);
    const vertical = Math.abs(dy / length);
    const rectangleEdge = Math.min(
        90 / (horizontal || 0.001),
        36 / (vertical || 0.001),
    );
    const inset = from.kind === 'core' ? 61 : rectangleEdge + 5;
    const endInset = to.kind === 'core' ? 64 : rectangleEdge + 9;
    const x1 = from.x + (dx / length) * inset;
    const y1 = from.y + (dy / length) * inset;
    const x2 = to.x - (dx / length) * endInset;
    const y2 = to.y - (dy / length) * endInset;
    const bend = Math.abs(dx) > 140 ? Math.min(70, Math.abs(dy) * 0.3) : 0;

    return `M ${x1} ${y1} C ${x1 + dx * 0.35} ${y1 + bend}, ${x2 - dx * 0.35} ${y2 + bend}, ${x2} ${y2}`;
}

function functionLines(node: MarketNode): string[] {
    if (node.id === 'ncc') {
        return ['Клиринг и', 'центральный контрагент'];
    }

    const lines: string[] = [];
    let currentLine = '';

    for (const word of node.functionLabel.split(' ')) {
        if (currentLine && currentLine.length + word.length + 1 > 20) {
            lines.push(currentLine);
            currentLine = word;
        } else {
            currentLine = currentLine ? `${currentLine} ${word}` : word;
        }
    }

    if (currentLine) {
        lines.push(currentLine);
    }

    return lines;
}

export default function MarketMap({
    stage,
    onStageChange,
    presentationStep,
    onPresentationStepChange,
}: MarketMapProps) {
    const safeStage = Math.max(0, Math.min(7, stage));
    const [selection, setSelection] = useState({ stage: -1, id: 'exchange' });
    const [functionsVisible, setFunctionsVisible] = useState(true);
    const [instrumentsVisible, setInstrumentsVisible] = useState(true);
    const [flowId, setFlowId] = useState('');
    const [flowStep, setFlowStep] = useState(0);
    const controlled = safeStage === 7 && presentationStep !== undefined;
    const controlledStep = Number.isFinite(presentationStep)
        ? Math.max(0, Math.min(15, Math.floor(presentationStep!)))
        : 0;
    const effectiveFlowId = controlled
        ? marketFlows[Math.floor(controlledStep / 4)].id
        : flowId;
    const effectiveFlowStep = controlled ? controlledStep % 4 : flowStep;
    const controlsLocked = controlled && !onPresentationStepChange;
    const markerId = useId().replace(/:/g, '');
    const chapter = marketStages[safeStage];
    const selected =
        nodeById[selection.stage === safeStage ? selection.id : chapter.focus];
    const flow =
        safeStage === 7
            ? marketFlows.find((item) => item.id === effectiveFlowId)
            : undefined;
    const step =
        flow?.steps[Math.min(effectiveFlowStep, flow.steps.length - 1)];
    const activeNodes = step?.nodes ?? [];
    const drawnNodes =
        flow?.id === 'secondary'
            ? [...marketNodes, secondarySeller]
            : marketNodes;
    const visibleNodes = marketNodes.filter((node) => node.stage <= safeStage);
    const selectedLinks = marketLinks.filter(
        ([from, to]) => from === selected.id || to === selected.id,
    );
    const neighbourIds = new Set(selectedLinks.flat());

    function selectFlow(id: string) {
        if (controlled) {
            onPresentationStepChange?.(
                marketFlows.findIndex((item) => item.id === id) * 4,
            );

            return;
        }

        setFlowId(id === flowId ? '' : id);
        setFlowStep(0);
    }

    function selectFlowStep(index: number) {
        if (controlled) {
            onPresentationStepChange?.(
                Math.floor(controlledStep / 4) * 4 + index,
            );

            return;
        }

        setFlowStep(index);
    }

    function nextFlowStep() {
        if (controlled) {
            onPresentationStepChange?.(Math.min(15, controlledStep + 1));

            return;
        }

        setFlowStep((flowStep + 1) % (flow?.steps.length ?? 1));
    }

    return (
        <section
            className="market-map"
            aria-label="Интерактивная архитектура финансового рынка"
            data-stage={safeStage}
        >
            <div className="mm-toolbar">
                <div className="mm-stage-caption">
                    <span>{String(safeStage + 1).padStart(2, '0')}</span>
                    {chapter.title}
                </div>
                <div className="mm-layer-controls" aria-label="Слои карты">
                    <span className="mm-participant-key">
                        <i />
                        Участники
                    </span>
                    <button
                        type="button"
                        aria-pressed={functionsVisible}
                        onClick={() => setFunctionsVisible(!functionsVisible)}
                    >
                        <i className="mm-function-key" />
                        Функции
                    </button>
                    <button
                        type="button"
                        aria-pressed={instrumentsVisible}
                        onClick={() =>
                            setInstrumentsVisible(!instrumentsVisible)
                        }
                    >
                        <i className="mm-instrument-key" />
                        Инструменты
                    </button>
                </div>
            </div>
            <div className="mm-body">
                <div className="mm-canvas">
                    <svg
                        viewBox="0 0 1220 650"
                        role="group"
                        aria-label={chapter.description}
                    >
                        <defs>
                            <pattern
                                id={`${markerId}-grid`}
                                width="32"
                                height="32"
                                patternUnits="userSpaceOnUse"
                            >
                                <circle
                                    cx="1"
                                    cy="1"
                                    r="1"
                                    fill="#8d8d95"
                                    opacity=".22"
                                />
                            </pattern>
                            <radialGradient id={`${markerId}-glow`}>
                                <stop
                                    offset="0"
                                    stopColor="#ff0000"
                                    stopOpacity=".12"
                                />
                                <stop
                                    offset="1"
                                    stopColor="#ff0000"
                                    stopOpacity="0"
                                />
                            </radialGradient>
                            <marker
                                id={`${markerId}-money`}
                                viewBox="0 0 10 10"
                                refX="8"
                                refY="5"
                                markerWidth="7"
                                markerHeight="7"
                                orient="auto-start-reverse"
                            >
                                <path
                                    d="M 0 1 L 9 5 L 0 9 Z"
                                    fill="var(--mm-money)"
                                />
                            </marker>
                            <marker
                                id={`${markerId}-rights`}
                                viewBox="0 0 10 10"
                                refX="8"
                                refY="5"
                                markerWidth="7"
                                markerHeight="7"
                                orient="auto-start-reverse"
                            >
                                <path
                                    d="M 0 1 L 9 5 L 0 9 Z"
                                    fill="var(--mm-information)"
                                />
                            </marker>
                        </defs>
                        <rect
                            width="1220"
                            height="650"
                            fill={`url(#${markerId}-grid)`}
                        />
                        <ellipse
                            cx="855"
                            cy="190"
                            rx="320"
                            ry="260"
                            fill={`url(#${markerId}-glow)`}
                        />
                        <text
                            x="935"
                            y="168"
                            className="mm-core-label"
                            opacity={safeStage >= 1 ? 1 : 0}
                        >
                            ИНФРАСТРУКТУРА
                        </text>
                        {marketLinks.map(([fromId, toId]) => {
                            const from = nodeById[fromId];
                            const to = nodeById[toId];
                            const shown =
                                from.stage <= safeStage &&
                                to.stage <= safeStage;
                            const connected =
                                from.id === selected.id ||
                                to.id === selected.id;

                            return (
                                <path
                                    key={`${fromId}-${toId}`}
                                    d={connection(from, to)}
                                    className={`mm-link ${shown ? 'is-visible' : ''} ${connected && !step ? 'is-connected' : ''}`}
                                />
                            );
                        })}
                        {step && (
                            <g
                                key={`${effectiveFlowId}-${effectiveFlowStep}`}
                                className={`mm-flow mm-flow-${step.kind}`}
                                aria-label={step.text}
                            >
                                <path
                                    d={connection(
                                        nodeById[step.from],
                                        nodeById[step.to],
                                    )}
                                    markerEnd={`url(#${markerId}-${step.kind})`}
                                />
                                <circle className="mm-moving-dot" r="6">
                                    <animateMotion
                                        dur="2.7s"
                                        repeatCount="indefinite"
                                        path={connection(
                                            nodeById[step.from],
                                            nodeById[step.to],
                                        )}
                                    />
                                </circle>
                            </g>
                        )}
                        {drawnNodes.map((node, index) => {
                            const shown = node.stage <= safeStage;
                            const isSelected = !step && node.id === selected.id;
                            const active = step
                                ? activeNodes.includes(node.id)
                                : isSelected || neighbourIds.has(node.id);
                            const core = node.kind === 'core';
                            const hasTwoLines = node.label.length > 1;

                            return (
                                <g
                                    key={node.id}
                                    transform={`translate(${node.x} ${node.y})`}
                                    className={`mm-node mm-node-${node.kind} ${shown ? 'is-visible' : ''} ${isSelected ? 'is-selected' : ''} ${step && !active ? 'is-dimmed' : ''}`}
                                    style={
                                        {
                                            '--mm-order': index % 4,
                                        } as CSSProperties
                                    }
                                    role={
                                        node.id === 'seller' ? 'img' : 'button'
                                    }
                                    tabIndex={
                                        shown &&
                                        node.id !== 'seller' &&
                                        !controlled
                                            ? 0
                                            : -1
                                    }
                                    aria-hidden={!shown}
                                    aria-label={`${node.label.join(' ')}. ${node.functionLabel}`}
                                    aria-pressed={
                                        node.id === 'seller'
                                            ? undefined
                                            : isSelected
                                    }
                                    onClick={() => {
                                        if (
                                            node.id === 'seller' ||
                                            controlled
                                        ) {
                                            return;
                                        }

                                        setSelection({
                                            stage: safeStage,
                                            id: node.id,
                                        });
                                        setFlowId('');
                                    }}
                                    onKeyDown={(event) => {
                                        if (controlled) {
                                            return;
                                        }

                                        if (
                                            event.key === 'Enter' ||
                                            event.key === ' '
                                        ) {
                                            event.preventDefault();
                                            event.stopPropagation();
                                            setSelection({
                                                stage: safeStage,
                                                id: node.id,
                                            });
                                            setFlowId('');
                                        }
                                    }}
                                >
                                    {core ? (
                                        <>
                                            <circle
                                                className="mm-node-halo"
                                                r="65"
                                            />
                                            <circle
                                                className="mm-node-shape"
                                                r="54"
                                            />
                                        </>
                                    ) : (
                                        <>
                                            <rect
                                                className="mm-node-halo"
                                                x="-94"
                                                y="-39"
                                                width="188"
                                                height="78"
                                                rx="23"
                                            />
                                            <rect
                                                className="mm-node-shape"
                                                x="-88"
                                                y="-33"
                                                width="176"
                                                height="66"
                                                rx="18"
                                            />
                                        </>
                                    )}
                                    {node.label.map((line, lineIndex) => (
                                        <text
                                            key={line}
                                            y={
                                                hasTwoLines
                                                    ? -5 + lineIndex * 26
                                                    : 8
                                            }
                                            className={`mm-node-name ${line.length >= 14 ? 'mm-node-name-small' : ''}`}
                                        >
                                            {line}
                                        </text>
                                    ))}
                                    {functionsVisible && (
                                        <text
                                            y={core ? 84 : 59}
                                            className="mm-node-function"
                                        >
                                            {functionLines(node).map(
                                                (line, index) => (
                                                    <tspan
                                                        key={line}
                                                        x="0"
                                                        dy={
                                                            index === 0 ? 0 : 22
                                                        }
                                                    >
                                                        {line}
                                                    </tspan>
                                                ),
                                            )}
                                        </text>
                                    )}
                                </g>
                            );
                        })}
                        {instrumentsVisible && (
                            <g
                                className="mm-stickers"
                                aria-label="Примеры инструментов и способов доступа"
                            >
                                {safeStage >= 4 && (
                                    <g transform="translate(43 142)">
                                        <rect width="124" height="29" rx="6" />
                                        <text x="62" y="20">
                                            Акции / долг
                                        </text>
                                    </g>
                                )}
                                {safeStage >= 5 && (
                                    <g transform="translate(37 278) rotate(-3)">
                                        <rect width="148" height="29" rx="6" />
                                        <text x="74" y="20">
                                            Брокерский счёт*
                                        </text>
                                    </g>
                                )}
                                {safeStage >= 5 && (
                                    <g transform="translate(156 418) rotate(2)">
                                        <rect width="90" height="29" rx="6" />
                                        <text x="45" y="20">
                                            Вклад
                                        </text>
                                    </g>
                                )}
                                {safeStage >= 3 && (
                                    <g transform="translate(483 463) rotate(-2)">
                                        <rect width="100" height="29" rx="6" />
                                        <text x="50" y="20">
                                            Пай ПИФа
                                        </text>
                                    </g>
                                )}
                            </g>
                        )}
                        {safeStage === 0 && (
                            <g
                                className="mm-intro"
                                transform="translate(120 205)"
                            >
                                <text y="0">Кто встречается</text>
                                <text y="48">на рынке?</text>
                                <text y="102" className="mm-intro-small">
                                    Начнём с места, где совершают сделки.
                                </text>
                                <text y="135" className="mm-intro-small">
                                    Затем добавим участников и их роли.
                                </text>
                            </g>
                        )}
                    </svg>
                    <div className="mm-map-footnote">
                        {safeStage >= 5
                            ? '* Брокерский счёт — способ доступа, не ценная бумага.'
                            : 'Выберите участника, чтобы увидеть его роль.'}
                    </div>
                </div>
                <aside className="mm-detail" aria-live="polite">
                    {step ? (
                        <>
                            <div className="mm-detail-eyebrow">
                                {flow?.title}
                            </div>
                            <h3>{step.title}</h3>
                            <p className="mm-role">{step.text}</p>
                            <div
                                className={`mm-flow-key mm-flow-key-${step.kind}`}
                            >
                                <i />
                                {step.kind === 'money'
                                    ? 'Движение денег'
                                    : 'Поручения, сведения и права'}
                            </div>
                            <div className="mm-flow-progress">
                                {flow?.steps.map((item, index) => (
                                    <button
                                        type="button"
                                        key={item.title}
                                        aria-label={`Шаг ${index + 1}: ${item.title}`}
                                        aria-current={
                                            index === effectiveFlowStep
                                                ? 'step'
                                                : undefined
                                        }
                                        disabled={controlsLocked}
                                        onClick={() => selectFlowStep(index)}
                                    >
                                        {index + 1}
                                    </button>
                                ))}
                            </div>
                            <button
                                className="mm-next-step"
                                type="button"
                                disabled={
                                    controlsLocked ||
                                    (controlled && controlledStep === 15)
                                }
                                onClick={nextFlowStep}
                            >
                                {controlled
                                    ? controlledStep === 15
                                        ? 'Маршрут завершён'
                                        : 'Следующий шаг'
                                    : effectiveFlowStep ===
                                        (flow?.steps.length ?? 1) - 1
                                      ? 'Повторить маршрут'
                                      : 'Следующий шаг'}{' '}
                                <span>→</span>
                            </button>
                            <p className="mm-detail-note">
                                Схема показывает экономический смысл. Реальные
                                денежные расчёты проходят через счета и
                                расчётную инфраструктуру.
                            </p>
                        </>
                    ) : (
                        <>
                            <div
                                className={`mm-detail-eyebrow mm-kind-${selected.kind}`}
                            >
                                {selected.kind === 'core'
                                    ? 'Инфраструктура рынка'
                                    : selected.kind === 'platform'
                                      ? 'Цифровой доступ'
                                      : 'Участник рынка'}
                            </div>
                            <h3>{selected.label.join(' ')}</h3>
                            <div className="mm-detail-section">ФУНКЦИЯ</div>
                            <p className="mm-role">{selected.role}</p>
                            <div className="mm-detail-section">
                                ИНСТРУМЕНТЫ И МЕХАНИЗМЫ
                            </div>
                            <div className="mm-detail-chips">
                                {selected.instruments.map((item) => (
                                    <span key={item}>{item}</span>
                                ))}
                            </div>
                            <p className="mm-detail-note">{selected.note}</p>
                        </>
                    )}
                    {!step && marketSources[selected.id] && (
                        <a
                            className="mm-source"
                            href={marketSources[selected.id].url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            {marketSources[selected.id].label} ↗
                        </a>
                    )}
                    <div className="mm-node-counter">
                        {visibleNodes.length} участников и ролей на карте
                    </div>
                </aside>
            </div>
            <div className="mm-bottom-bar">
                {safeStage === 7 ? (
                    <div className="mm-routes">
                        <span>Маршрут</span>
                        {marketFlows.map((item) => (
                            <button
                                type="button"
                                key={item.id}
                                className={
                                    effectiveFlowId === item.id
                                        ? 'is-active'
                                        : ''
                                }
                                aria-pressed={effectiveFlowId === item.id}
                                disabled={controlsLocked}
                                onClick={() => selectFlow(item.id)}
                            >
                                {item.title}
                            </button>
                        ))}
                    </div>
                ) : (
                    <p>{chapter.description}</p>
                )}
                {onStageChange && (
                    <div className="mm-stage-dots" aria-label="Этапы карты">
                        {marketStages.map((item, index) => (
                            <button
                                type="button"
                                key={item.title}
                                aria-label={`Этап ${index + 1}: ${item.title}`}
                                aria-current={
                                    safeStage === index ? 'step' : undefined
                                }
                                onClick={() => onStageChange(index)}
                            >
                                <span>{index + 1}</span>
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </section>
    );
}
