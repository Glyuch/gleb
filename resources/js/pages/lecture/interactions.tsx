import {
    ArrowRight,
    BookOpen,
    Check,
    Landmark,
    Layers3,
    ShieldCheck,
    Sparkles,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { projectSavings, rubles } from './finance';

export interface PresentationStepProps {
    presentationStep?: number;
    onPresentationStepChange?: (step: number) => void;
}

function presentationIndex(value: number, count: number): number {
    return Number.isFinite(value)
        ? Math.max(0, Math.min(count - 1, Math.floor(value)))
        : 0;
}

function usePresentationStep(props: PresentationStepProps, count: number) {
    const [manualStep, setManualStep] = useState(0);
    const controlled = props.presentationStep !== undefined;
    const step = presentationIndex(
        controlled ? props.presentationStep! : manualStep,
        count,
    );

    function setStep(next: number) {
        const bounded = presentationIndex(next, count);

        if (controlled) {
            props.onPresentationStepChange?.(bounded);
        } else {
            setManualStep(bounded);
        }
    }

    return [
        step,
        setStep,
        controlled && !props.onPresentationStepChange,
    ] as const;
}

export function Goals(props: PresentationStepProps = {}) {
    const [selected, select, controlsLocked] = usePresentationStep(props, 3);
    const goals = [
        {
            icon: ShieldCheck,
            title: 'Резерв',
            time: 'Деньги могут понадобиться завтра',
            rule: 'Доступность и сохранность',
            text: 'Часть резерва должна быть доступна сразу. Проверьте срок вывода, риск потерь и условия защиты.',
            tools: 'Текущий / накопительный счёт, подходящий вклад',
        },
        {
            icon: Wallet,
            title: 'Близкая цель',
            time: 'Покупка в обозримый срок',
            rule: 'Совпадение срока и риска',
            text: 'Чем ближе обязательная трата, тем меньше возможности переждать снижение стоимости активов.',
            tools: 'Инструменты с подходящим сроком и понятным риском',
        },
        {
            icon: TrendingUp,
            title: 'Капитал',
            time: 'Горизонт — годы',
            rule: 'Стратегия и диверсификация',
            text: 'Распределяйте риски, регулярно пополняйте и проверяйте, что выбранная стратегия всё ещё соответствует цели.',
            tools: 'Диверсифицированный набор активов, в том числе фонды',
        },
    ];
    const item = goals[selected];

    return (
        <div className="goal-layout">
            <div className="goal-options">
                {goals.map((goal, index) => (
                    <button
                        disabled={controlsLocked}
                        key={goal.title}
                        aria-pressed={selected === index}
                        onClick={() => select(index)}
                        className={selected === index ? 'chosen' : ''}
                    >
                        <goal.icon size={38} />
                        <span>
                            <strong>{goal.title}</strong>
                            <small>{goal.time}</small>
                        </span>
                        <ArrowRight />
                    </button>
                ))}
            </div>
            <div className="goal-detail" key={selected}>
                <span className="eyebrow">Критерий выбора</span>
                <h2>{item.rule}</h2>
                <p>{item.text}</p>
                <div className="instrument-chip">{item.tools}</div>
                <p className="fine">
                    Начинаем с цели, суммы и срока. Название продукта выбираем
                    после этого.
                </p>
            </div>
        </div>
    );
}
export function SavingsCalculator({
    presentationStep,
}: PresentationStepProps = {}) {
    const [manualMonthly, setMonthly] = useState(10000);
    const [manualYears, setYears] = useState(10);
    const [manualRate, setRate] = useState(8);
    const controlled = presentationStep !== undefined;
    const presets = [
        { monthly: 10000, years: 10, rate: 8 },
        { monthly: 20000, years: 10, rate: 8 },
        { monthly: 20000, years: 20, rate: 8 },
    ];
    const preset =
        presets[presentationIndex(presentationStep ?? 0, presets.length)];
    const monthly = controlled ? preset.monthly : manualMonthly;
    const years = controlled ? preset.years : manualYears;
    const rate = controlled ? preset.rate : manualRate;
    const initial = 100000;
    const result = projectSavings(initial, monthly, years, rate);
    const points = Array.from({ length: years + 1 }, (_, i) =>
        projectSavings(initial, monthly, i, rate),
    );
    const max =
        Math.max(...points.map((p) => Math.max(p.total, p.contributions))) *
        1.1;
    const path = points
        .map(
            (p, i) =>
                (i === 0 ? 'M' : 'L') +
                ((i / years) * 850 + 30) +
                ',' +
                (350 - (p.total / max) * 310),
        )
        .join(' ');
    const ownPath = points
        .map(
            (p, i) =>
                (i === 0 ? 'M' : 'L') +
                ((i / years) * 850 + 30) +
                ',' +
                (350 - (p.contributions / max) * 310),
        )
        .join(' ');

    return (
        <div className="calculator-layout">
            <div className="calculator-controls">
                <span className="eyebrow">
                    Стартовый капитал · {rubles(initial)}
                </span>
                <label>
                    Ежемесячный взнос <b>{rubles(monthly)}</b>
                    <input
                        aria-label="Ежемесячный взнос"
                        type="range"
                        disabled={controlled}
                        min="1000"
                        max="50000"
                        step="1000"
                        value={monthly}
                        onChange={(e) => setMonthly(+e.target.value)}
                    />
                </label>
                <label>
                    Срок <b>{years} лет</b>
                    <input
                        aria-label="Срок накопления в годах"
                        type="range"
                        disabled={controlled}
                        min="1"
                        max="25"
                        value={years}
                        onChange={(e) => setYears(+e.target.value)}
                    />
                </label>
                <label>
                    Доходность в модели <b>{rate}% годовых</b>
                    <input
                        aria-label="Расчётная годовая доходность"
                        type="range"
                        disabled={controlled}
                        min="-5"
                        max="20"
                        value={rate}
                        onChange={(e) => setRate(+e.target.value)}
                    />
                </label>
                <p className="fine">
                    Постоянная эффективная ставка — допущение. Пополнение в
                    конце месяца. Без налогов, комиссий и инфляции.
                </p>
            </div>
            <div className="calculator-chart">
                <span className="eyebrow">Расчётный капитал</span>
                <div className="huge-number">{rubles(result.total)}</div>
                <svg
                    viewBox="0 0 920 400"
                    role="img"
                    aria-label="Расчётные накопления и собственные взносы"
                >
                    <defs>
                        <linearGradient
                            id="saving-fill"
                            x1="0"
                            y1="0"
                            x2="0"
                            y2="1"
                        >
                            <stop stopColor="#e00000" stopOpacity=".25" />
                            <stop
                                offset="1"
                                stopColor="#e00000"
                                stopOpacity="0"
                            />
                        </linearGradient>
                    </defs>
                    {[0, 1, 2, 3].map((i) => (
                        <line
                            key={i}
                            x1="30"
                            x2="880"
                            y1={50 + i * 100}
                            y2={50 + i * 100}
                            stroke="#24242616"
                        />
                    ))}
                    <path
                        d={path + ' L880,350 L30,350 Z'}
                        fill="url(#saving-fill)"
                    />
                    <path
                        d={ownPath}
                        fill="none"
                        stroke="#74747b"
                        strokeWidth="4"
                        strokeDasharray="10 8"
                    />
                    <path
                        d={path}
                        fill="none"
                        stroke="#e00000"
                        strokeWidth="6"
                    />
                    <text x="30" y="390">
                        Сейчас
                    </text>
                    <text x="880" y="390" textAnchor="end">
                        {years} лет
                    </text>
                </svg>
                <div className="chart-key">
                    <span>
                        Свои деньги <b>{rubles(result.contributions)}</b>
                    </span>
                    <span className="mint">
                        Расчётный результат <b>{rubles(result.growth)}</b>
                    </span>
                </div>
                <p className="fine">
                    Доходность не гарантирована. Стоимость инвестиций может
                    снижаться.
                </p>
            </div>
        </div>
    );
}
export function Platform(props: PresentationStepProps = {}) {
    const [step, setStep, controlsLocked] = usePresentationStep(props, 4);
    const steps = ['Цель', 'Сравнение', 'Оформление', 'Накопление'];

    return (
        <div className="platform-layout">
            <div className="platform-message">
                <span className="brand-word">
                    Финуслуги<span>от Московской биржи</span>
                </span>
                <h2>
                    Меньше действий.
                    <br />
                    <em>Больше доступности.</em>
                </h2>
                <p>
                    Продукты разных организаций, сравнение условий и оформление
                    онлайн — в одном месте.
                </p>
                <div className="platform-path">
                    {steps.map((s, i) => (
                        <button
                            disabled={controlsLocked}
                            key={s}
                            className={step === i ? 'chosen' : ''}
                            onClick={() => setStep(i)}
                        >
                            {i + 1}. {s}
                        </button>
                    ))}
                </div>
                <p className="fine">
                    Простое действие возможно после необходимых регистрации и
                    идентификации. У каждого продукта свои условия и риски.
                </p>
            </div>
            <div className="phone-demo">
                <div className="phone-top">
                    <span>Финуслуги</span>
                    <span>Учебный пример</span>
                </div>
                <span className="eyebrow">{steps[step]}</span>
                <h3>
                    {
                        [
                            'На что копим?',
                            'Что подходит цели?',
                            'Оформляем онлайн',
                            'Продолжаем регулярно',
                        ][step]
                    }
                </h3>
                <div className="demo-product">
                    <Landmark />
                    <div>
                        <b>
                            {
                                [
                                    'Финансовый резерв',
                                    'Вклады разных банков',
                                    'Изучить условия',
                                    'План пополнений',
                                ][step]
                            }
                        </b>
                        <small>
                            {
                                [
                                    'Срок · сумма · доступность',
                                    'Ставка · срок · условия',
                                    'Идентификация и подтверждение',
                                    'Удобная сумма и ритм',
                                ][step]
                            }
                        </small>
                    </div>
                </div>
                <div className="demo-product">
                    <Layers3 />
                    <div>
                        <b>
                            {
                                [
                                    'Крупная покупка',
                                    'Инвестиционные фонды',
                                    'Понятный результат',
                                    'Контроль цели',
                                ][step]
                            }
                        </b>
                        <small>
                            {
                                [
                                    'Планируем заранее',
                                    'Стратегия · риск · расходы',
                                    'Статус сделки и документы',
                                    'Взносы отдельно от дохода',
                                ][step]
                            }
                        </small>
                    </div>
                </div>
                <button
                    disabled={controlsLocked}
                    className="primary-button"
                    onClick={() => setStep((step + 1) % 4)}
                >
                    Следующий шаг <ArrowRight />
                </button>
                <div className="phone-home" />
            </div>
        </div>
    );
}
export function Moneybox(props: PresentationStepProps = {}) {
    const [step, setStep, controlsLocked] = usePresentationStep(props, 2);
    const open = step === 1;

    return (
        <div className="moneybox-layout">
            <div className="moneybox-orb">
                <Wallet size={100} strokeWidth={1} />
                <b>Финподушка</b>
                <span>Пополнение → инвестиции</span>
                <button
                    className="primary-button"
                    disabled={controlsLocked}
                    onClick={() => setStep(open ? 0 : 1)}
                >
                    {open ? 'Свернуть механику' : 'Что происходит внутри?'}{' '}
                    <ArrowRight />
                </button>
            </div>
            <div className="moneybox-copy">
                <span className="eyebrow">Продукт делает сложное понятнее</span>
                <h2>
                    Вы пополняете.
                    <br />
                    <em>Фонд инвестирует.</em>
                </h2>
                {open ? (
                    <div className="mechanism-reveal">
                        <div>
                            1 <span>Пополнение через Финуслуги</span>
                        </div>
                        <div>
                            2 <span>Покупка паёв фонда денежного рынка</span>
                        </div>
                        <div>
                            3 <span>УК размещает активы фонда</span>
                        </div>
                        <div>
                            4 <span>Результат отражается в стоимости паёв</span>
                        </div>
                    </div>
                ) : (
                    <p>
                        Начать можно с небольшой суммы, без отдельного
                        брокерского счёта. Похожую задачу упрощения решают
                        инвесткопилки брокеров.
                    </p>
                )}
                <div className="risk-line">
                    <ShieldCheck />
                    <p>
                        Это инвестиции в фонд, а не вклад. Доходность не
                        гарантирована, страхование вкладов не применяется. Вывод
                        занимает время.
                    </p>
                </div>
                <a
                    className="text-link"
                    href="https://finuslugi.ru/invest/moneybox"
                    target="_blank"
                    rel="noreferrer"
                >
                    Механика и актуальные условия на Финуслугах ↗
                </a>
            </div>
        </div>
    );
}
const fundingStages = [
    {
        title: 'Идея',
        proof: 'Проверяем спрос',
        money: ['Свои средства', 'Партнёр', 'Предзаказы', 'Конкурсный грант'],
        question: 'Кто клиент и за что он готов заплатить?',
        note: 'Грант зависит от отбора и целевого использования. Долг создаёт платежи ещё до устойчивой выручки.',
    },
    {
        title: 'Первые продажи',
        proof: 'Доказываем повторяемость',
        money: [
            'Реинвестирование',
            'Партнёрский капитал',
            'Подходящие микрозаймы',
            'Программы поддержки',
        ],
        question: 'Остаются ли деньги с каждой продажи?',
        note: 'Посчитайте маржинальность, стоимость привлечения клиента, расход денег и путь к устойчивой работе.',
    },
    {
        title: 'Устойчивый бизнес',
        proof: 'Показываем денежный поток',
        money: ['Банковский кредит', 'Факторинг', 'Лизинг'],
        question: 'Из каких поступлений будем платить?',
        note: 'Оборотные деньги, дебиторская задолженность и оборудование требуют разных инструментов и сроков.',
    },
    {
        title: 'Масштабирование',
        proof: 'Обосновываем рост',
        money: [
            'Долевой инвестор',
            'Инвестиционный кредит',
            'Инвестплатформа',
            'Облигации при готовности',
        ],
        question: 'Как капитал увеличит будущий результат?',
        note: 'Нужны экономика расширения, команда, отчётность и негативный сценарий. Доля означает разделение результата и контроля.',
    },
    {
        title: 'Публичный рынок',
        proof: 'Подтверждаем зрелость',
        money: ['Облигации', 'Публичное размещение акций'],
        question: 'Готовы ли мы к прозрачности перед рынком?',
        note: 'Финансовая история, корпоративное управление, раскрытие информации и понятная инвестиционная история.',
    },
];
export function Funding(props: PresentationStepProps = {}) {
    const [stage, setStage, controlsLocked] = usePresentationStep(props, 5);
    const data = fundingStages[stage];

    return (
        <div className="funding">
            <div className="funding-stages">
                {fundingStages.map((s, i) => (
                    <button
                        disabled={controlsLocked}
                        key={s.title}
                        onClick={() => setStage(i)}
                        className={stage === i ? 'chosen' : ''}
                    >
                        <span>0{i + 1}</span>
                        <b>{s.title}</b>
                    </button>
                ))}
            </div>
            <div className="funding-detail" key={stage}>
                <div>
                    <span className="eyebrow">{data.proof}</span>
                    <h2>{data.question}</h2>
                    <p>{data.note}</p>
                </div>
                <div className="funding-sources">
                    <span className="eyebrow">Возможные источники</span>
                    {data.money.map((m) => (
                        <div key={m}>
                            <ArrowRight size={26} />
                            {m}
                        </div>
                    ))}
                </div>
            </div>
            <p className="fine">
                Учебная схема, не жёсткая лестница. Доступность зависит от
                бизнеса, условий программы и требований инвестора.
            </p>
        </div>
    );
}
export function Statements({ presentationStep }: PresentationStepProps = {}) {
    const [manualPaid, setPaid] = useState(60);
    const controlled = presentationStep !== undefined;
    const paid = controlled
        ? [100, 60, 80][presentationIndex(presentationStep, 3)]
        : manualPaid;

    return (
        <div className="statements">
            <div className="statement-cards">
                <article>
                    <span>01 / Отчёт о финансовых результатах</span>
                    <h3>Зарабатываем?</h3>
                    <div className="statement-formula">
                        100 − 70 = <b className="mint">30</b>
                    </div>
                    <p>Выручка − расходы = прибыль</p>
                </article>
                <article className="cash-card">
                    <span>02 / Движение денежных средств</span>
                    <h3>Можем платить?</h3>
                    <div className="statement-formula">
                        {paid} − 70 ={' '}
                        <b className={paid < 70 ? 'coral' : 'mint'}>
                            {paid - 70}
                        </b>
                    </div>
                    <p>Поступления − выплаты = поток</p>
                </article>
                <article>
                    <span>03 / Баланс</span>
                    <h3>Что у нас есть?</h3>
                    <div className="balance-word">
                        Активы =<br />
                        обязательства + капитал
                    </div>
                    <p>Деньги, дебиторка, имущество и долги</p>
                </article>
            </div>
            <div className="payment-slider">
                <label htmlFor="paid">
                    Сколько уже оплатили покупатели? <b>{paid} из 100</b>
                </label>
                <input
                    id="paid"
                    type="range"
                    disabled={controlled}
                    min="0"
                    max="100"
                    step="5"
                    value={paid}
                    onChange={(e) => setPaid(+e.target.value)}
                />
                <p>
                    {paid < 70
                        ? 'Кассовый разрыв: прибыль не оплачивает счета, пока деньги не получены.'
                        : 'Поступления покрывают выплаты. Прибыль всё ещё равна 30.'}
                </p>
            </div>
            <p className="fine">
                Условные единицы. Упрощённый пример без налогов, инвестиций,
                стартового остатка и прочих движений.
            </p>
        </div>
    );
}
export function Pitch(props: PresentationStepProps = {}) {
    const [selected, select, controlsLocked] = usePresentationStep(props, 4);
    const items = [
        [
            'Сколько и на что',
            'Сумма → действия → проверяемый результат',
            'Разделите инвестиции в развитие и оборотный капитал. Покажите, какой этап будет достигнут на привлечённые деньги.',
        ],
        [
            'Откуда возврат',
            'Продажи → маржа → денежный поток',
            'Объясните предпосылки, сроки и экономику. Для доли — возможные дивиденды и выход; для долга — источник платежей.',
        ],
        [
            'На каких условиях',
            'Срок → права → распределение результата',
            'Что получает инвестор? Как принимаются решения? Какие ограничения, расходы и обязательства возникают у сторон?',
        ],
        [
            'Что может пойти иначе',
            'Сценарии → запас денег → действия команды',
            'Покажите негативный сценарий: продажи ниже, оплаты позже, расходы выше. Объясните, как вы будете действовать.',
        ],
    ];

    return (
        <div className="pitch-layout">
            <div className="pitch-questions">
                {items.map((item, index) => (
                    <button
                        disabled={controlsLocked}
                        key={item[0]}
                        className={selected === index ? 'chosen' : ''}
                        onClick={() => select(index)}
                    >
                        <span>0{index + 1}</span>
                        {item[0]}
                        <ArrowRight />
                    </button>
                ))}
            </div>
            <div className="pitch-answer" key={selected}>
                <span className="eyebrow">Проверяемость вместо обещаний</span>
                <h2>{items[selected][1]}</h2>
                <p>{items[selected][2]}</p>
                <div className="pitch-proof">
                    <Check /> Отчётность <Check /> Бизнес-план <Check /> Риски
                </div>
                <p className="fine">
                    Доходность вложения = (получено − вложено) / вложено. Всегда
                    уточняйте период, денежные потоки, расходы и допущения.
                </p>
            </div>
        </div>
    );
}
export function School({ qrUrl }: { qrUrl?: string }) {
    return (
        <div className="school-layout">
            <div>
                <BookOpen className="mint" size={70} />
                <span className="eyebrow">Школа Московской биржи</span>
                <h2>
                    Инвестиции
                    <br />
                    <em>с нуля</em>
                </h2>
                <p>
                    Цели и риск. Инструменты и портфель.
                    <br />
                    Практика и защита от ошибок.
                </p>
                <div className="school-facts">
                    <span>
                        <b>17</b> уроков
                    </span>
                    <span>
                        <b>4</b> теста
                    </span>
                    <span>
                        <Sparkles /> бесплатно
                    </span>
                </div>
                <a
                    className="primary-button"
                    href="https://school.moex.com/courses/investicii-s-nulya"
                    target="_blank"
                    rel="noreferrer"
                >
                    Перейти к курсу <ArrowRight />
                </a>
                <p className="fine">
                    В своём темпе. Для прохождения курса нужна регистрация на
                    платформе школы.
                </p>
            </div>
            <div className="school-poster">
                {qrUrl ? (
                    <img
                        className="school-qr"
                        src={qrUrl}
                        alt="QR-код бесплатного курса Инвестиции с нуля"
                    />
                ) : (
                    <BookOpen size={130} strokeWidth={1} />
                )}
                <h3>
                    Разобраться
                    <br />
                    проще, чем кажется.
                </h3>
                <span>school.moex.com</span>
                <div className="orbital-line" />
            </div>
        </div>
    );
}
