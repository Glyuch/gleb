import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    BookOpen,
    Check,
    ChevronDown,
    CircleHelp,
    Maximize,
    Pause,
    Play,
    Radio,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { CSSProperties } from 'react';
import { presenter } from '@/actions/App/Http/Controllers/LectureController';
import {
    Funding,
    Goals,
    Moneybox,
    Pitch,
    Platform,
    SavingsCalculator,
    School,
    Statements,
} from './interactions';
import MarketMap from './market-map';
import { useLectureRoom } from './room-client';
import { blocks, scenes, sources } from './scenes';
import type { LectureProps, Question, Room } from './types';
import './lecture.css';

function Poll({
    question,
    room,
    isPresenter,
    busy,
    onAction,
}: {
    question: Question;
    room: Room | null;
    isPresenter: boolean;
    busy: boolean;
    onAction: (action: string, questionId?: string) => void;
}) {
    const active = room?.question?.id === question.id && !room.ended;
    const results = active && room.revealed;

    return (
        <div className="poll-layout">
            <div className="poll-answers">
                <div className="poll-status">
                    <span
                        className={
                            active && room.open ? 'live-dot' : 'idle-dot'
                        }
                    />
                    {!room
                        ? 'Обсудим с залом'
                        : !active
                          ? 'Вопрос ещё не выбран'
                          : room.open
                            ? 'Голосование открыто'
                            : results
                              ? 'Результаты голосования'
                              : 'Ожидаем открытия'}
                    {active && <b>{room.totalVotes} ответов</b>}
                </div>
                {question.options.map((option, index) => {
                    const count = results ? (room.counts?.[index] ?? 0) : 0;
                    const percent =
                        results && room.totalVotes
                            ? Math.round((count / room.totalVotes) * 100)
                            : 0;

                    return (
                        <div
                            key={option}
                            className={
                                'poll-option ' +
                                (results &&
                                room.question?.correctIndex === index
                                    ? 'correct'
                                    : '')
                            }
                        >
                            <div
                                className="poll-bar"
                                style={{ width: percent + '%' }}
                            />
                            <span className="option-letter">
                                {String.fromCharCode(65 + index)}
                            </span>
                            <span>{option}</span>
                            {results && (
                                <b>
                                    {percent}% <small>· {count}</small>
                                </b>
                            )}
                            {results &&
                                room.question?.correctIndex === index && (
                                    <Check />
                                )}
                        </div>
                    );
                })}
                {results && room.question?.explanation && (
                    <p className="poll-explanation">
                        {room.question.explanation}
                    </p>
                )}
                {isPresenter && room && !room.ended && (
                    <div className="poll-control-buttons">
                        {!active ? (
                            <button
                                disabled={busy}
                                onClick={() =>
                                    onAction('question', question.id)
                                }
                            >
                                Выбрать этот вопрос
                            </button>
                        ) : (
                            <>
                                <button
                                    disabled={
                                        busy || room.open || room.revealed
                                    }
                                    onClick={() => onAction('open')}
                                >
                                    Открыть голосование
                                </button>
                                <button
                                    disabled={busy || !room.open}
                                    onClick={() => onAction('close')}
                                >
                                    Закрыть
                                </button>
                                <button
                                    disabled={busy || room.revealed}
                                    onClick={() => onAction('reveal')}
                                >
                                    Показать ответы
                                </button>
                            </>
                        )}
                    </div>
                )}
            </div>
            <div className="join-card">
                {room && !room.ended ? (
                    <>
                        <img
                            src={room.qrUrl}
                            alt="QR-код подключения к голосованию"
                        />
                        <span>Наведите камеру</span>
                        <b>{room.code}</b>
                        <a href={room.joinUrl} target="_blank" rel="noreferrer">
                            {new URL(room.joinUrl).host}/lecture/join/
                            {room.code}
                        </a>
                        <p>
                            Без регистрации.
                            <br />
                            Один ответ на вопрос.
                        </p>
                    </>
                ) : (
                    <>
                        <Radio size={75} strokeWidth={1} />
                        <h3>Подключаем зал</h3>
                        <p>
                            {isPresenter
                                ? 'Нажмите «Эфир» и создайте голосование. Здесь появится QR-код.'
                                : 'Ведущий покажет QR-код и откроет вопросы во время выступления.'}
                        </p>
                    </>
                )}
            </div>
        </div>
    );
}
function Cover() {
    return (
        <div className="cover-layout">
            <div>
                <span className="eyebrow">
                    Глеб Юченков · интерактивная лекция
                </span>
                <h1>
                    Деньги.
                    <br />
                    Рынок.
                    <br />
                    <em>Вы.</em>
                </h1>
                <p>
                    Как устроены инвестиции —<br />и как пользоваться этой
                    системой
                </p>
                <div className="cover-meta">
                    <span>40 минут</span>
                    <span>3 взгляда на капитал</span>
                    <span>Ваше участие</span>
                </div>
            </div>
            <div className="cover-network" aria-hidden="true">
                <div className="cover-orbit orbit-one" />
                <div className="cover-orbit orbit-two" />
                <div className="cover-orbit orbit-three" />
                <div className="network-core">КАПИТАЛ</div>
                <div className="network-node node-one">Рынок</div>
                <div className="network-node node-two">Накопления</div>
                <div className="network-node node-three">Бизнес</div>
                <div className="orbit-particle" />
            </div>
        </div>
    );
}
export default function Lecture({
    isPresenter,
    questions,
    room: initialRoom,
    createRoomUrl,
}: LectureProps) {
    const [slide, setSlide] = useState(0);
    const [scale, setScale] = useState(1);
    const [panel, setPanel] = useState(false);
    const [notes, setNotes] = useState(false);
    const [contents, setContents] = useState(false);
    const [sourceOpen, setSourceOpen] = useState(false);
    const [help, setHelp] = useState(false);
    const [running, setRunning] = useState(false);
    const [elapsed, setElapsed] = useState(0);
    const [fullscreenError, setFullscreenError] = useState('');
    const [endConfirm, setEndConfirm] = useState(false);
    const { room, error, busy, send } = useLectureRoom(initialRoom);
    const scene = scenes[slide];
    const go = useCallback((target: number) => {
        const next = Math.max(0, Math.min(scenes.length - 1, target));
        setSlide(next);
        window.history.replaceState(null, '', '#slide-' + (next + 1));
    }, []);
    useEffect(() => {
        const readHash = () => {
            const match = window.location.hash.match(/^#slide-(\d+)$/);
            setSlide(
                Math.max(
                    0,
                    Math.min(
                        scenes.length - 1,
                        match ? Number(match[1]) - 1 : 0,
                    ),
                ),
            );
        };
        const resize = () =>
            setScale(
                Math.min(window.innerWidth / 1920, window.innerHeight / 1080),
            );
        readHash();
        resize();
        window.addEventListener('resize', resize);
        window.addEventListener('hashchange', readHash);

        return () => {
            window.removeEventListener('resize', resize);
            window.removeEventListener('hashchange', readHash);
        };
    }, []);
    const full = useCallback(async () => {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else {
                await document.documentElement.requestFullscreen();
            }

            setFullscreenError('');
        } catch {
            setFullscreenError(
                'Разверните окно браузера или используйте его полноэкранный режим.',
            );
        }
    }, []);
    useEffect(() => {
        const key = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement;

            if (
                target.closest('input,select,textarea,[contenteditable="true"]')
            ) {
                return;
            }

            if (event.key === 'Escape') {
                setPanel(false);
                setNotes(false);
                setContents(false);
                setSourceOpen(false);
                setHelp(false);

                return;
            }

            if (panel || contents || sourceOpen || help) {
                return;
            }

            if (
                event.key === ' ' &&
                target.closest('button,a,[role="button"]')
            ) {
                return;
            }

            if (['ArrowRight', 'PageDown', ' '].includes(event.key)) {
                event.preventDefault();
                go(slide + 1);
            }

            if (['ArrowLeft', 'PageUp'].includes(event.key)) {
                event.preventDefault();
                go(slide - 1);
            }

            if (event.key === 'Home') {
                event.preventDefault();
                go(0);
            }

            if (event.key === 'End') {
                event.preventDefault();
                go(scenes.length - 1);
            }

            if (event.key.toLowerCase() === 'f') {
                void full();
            }

            if (event.key.toLowerCase() === 'n') {
                setNotes((n) => !n);
            }

            if (event.key.toLowerCase() === 'p' && isPresenter) {
                setPanel((p) => !p);
            }
        };
        window.addEventListener('keydown', key);

        return () => window.removeEventListener('keydown', key);
    }, [slide, go, full, isPresenter, panel, contents, sourceOpen, help]);
    useEffect(() => {
        if (!running) {
            return;
        }

        let previous = Date.now();
        const interval = setInterval(() => {
            const current = Date.now();
            const delta = (current - previous) / 1000;
            setElapsed((value) => value + delta);
            previous = current;
        }, 1000);

        return () => clearInterval(interval);
    }, [running]);
    const action = (act: string, questionId?: string) => {
        if (room?.manageUrl) {
            void send(room.manageUrl, 'PATCH', {
                action: act,
                ...(questionId ? { questionId } : {}),
            });
        }
    };
    const question = questions.find((q) => q.id === scene.poll);
    const stageChange = (stage: number) => {
        const target = scenes.findIndex(
            (s) => s.kind === 'map' && s.stage === stage,
        );

        if (target >= 0) {
            go(target);
        }
    };
    const timer =
        Math.floor(elapsed / 60)
            .toString()
            .padStart(2, '0') +
        ':' +
        Math.floor(elapsed % 60)
            .toString()
            .padStart(2, '0');
    const planned = scenes
        .slice(0, slide)
        .reduce((sum, s) => sum + s.minutes, 0);

    return (
        <>
            <Head title="Деньги. Рынок. Вы. — интерактивная лекция" />
            <div className="lecture-shell">
                <main
                    className="lecture-stage"
                    style={{ '--lecture-scale': scale } as CSSProperties}
                >
                    <header className="lecture-header">
                        <button
                            className="lecture-wordmark"
                            onClick={() => go(0)}
                        >
                            gleb<span>.finance</span>
                        </button>
                        <nav aria-label="Блоки презентации">
                            {[1, 2, 3].map((b) => (
                                <button
                                    key={b}
                                    className={
                                        scene.block === b ? 'active' : ''
                                    }
                                    onClick={() =>
                                        go(
                                            scenes.findIndex(
                                                (s) => s.block === b,
                                            ),
                                        )
                                    }
                                >
                                    {blocks[b]}
                                </button>
                            ))}
                        </nav>
                        <div className="header-actions">
                            <button
                                aria-label="Оглавление"
                                title="Оглавление"
                                onClick={() => setContents(true)}
                            >
                                <BookOpen size={25} />
                            </button>
                            <button
                                aria-label="Полный экран"
                                title="Полный экран · F"
                                onClick={() => void full()}
                            >
                                <Maximize size={25} />
                            </button>
                            {isPresenter ? (
                                <button
                                    className={
                                        room && !room.ended ? 'live-button' : ''
                                    }
                                    onClick={() => setPanel(!panel)}
                                >
                                    <Radio size={23} />
                                    Эфир
                                </button>
                            ) : (
                                <Link href={presenter.url()}>Ведущему ↗</Link>
                            )}
                        </div>
                    </header>
                    <section
                        className={'lecture-slide slide-' + scene.kind}
                        aria-label={'Слайд ' + (slide + 1)}
                    >
                        {scene.kind === 'cover' ? (
                            <Cover />
                        ) : (
                            <>
                                <div className="slide-heading">
                                    <span className="eyebrow">
                                        {blocks[scene.block]}
                                    </span>
                                    <h1>{scene.title}</h1>
                                    <p>{scene.subtitle}</p>
                                </div>
                                <div className="slide-body">
                                    {scene.kind === 'map' && (
                                        <MarketMap
                                            stage={scene.stage ?? 0}
                                            onStageChange={stageChange}
                                        />
                                    )}
                                    {scene.kind === 'poll' && question && (
                                        <Poll
                                            question={question}
                                            room={room}
                                            isPresenter={isPresenter}
                                            busy={busy}
                                            onAction={action}
                                        />
                                    )}
                                    {scene.kind === 'goals' && <Goals />}
                                    {scene.kind === 'calculator' && (
                                        <SavingsCalculator />
                                    )}
                                    {scene.kind === 'platform' && <Platform />}
                                    {scene.kind === 'moneybox' && <Moneybox />}
                                    {scene.kind === 'school' && (
                                        <School qrUrl="/media/lecture-course.svg" />
                                    )}
                                    {scene.kind === 'funding' && <Funding />}
                                    {scene.kind === 'statements' && (
                                        <Statements />
                                    )}
                                    {scene.kind === 'pitch' && <Pitch />}
                                    {scene.kind === 'bridge' && (
                                        <div className="bridge-layout">
                                            <div>
                                                <span>Когда инвестируете</span>
                                                <h2>
                                                    «Как будут
                                                    <br />
                                                    работать
                                                    <br />
                                                    <em>мои деньги?»</em>
                                                </h2>
                                            </div>
                                            <div className="bridge-arrow">
                                                <ArrowRight size={100} />
                                            </div>
                                            <div>
                                                <span>Когда привлекаете</span>
                                                <h2>
                                                    «Вот как будут
                                                    <br />
                                                    работать
                                                    <br />
                                                    <em>ваши деньги»</em>
                                                </h2>
                                            </div>
                                            <p>
                                                Срок. Доходность. Риск.
                                                Прозрачность.
                                            </p>
                                        </div>
                                    )}
                                    {scene.kind === 'finish' && (
                                        <div className="finish-layout">
                                            <div className="finish-actions">
                                                <button
                                                    onClick={() =>
                                                        stageChange(7)
                                                    }
                                                >
                                                    <span>01</span>
                                                    <h2>Понять систему</h2>
                                                    <p>
                                                        Исследуйте карту и
                                                        маршрут своих денег.
                                                    </p>
                                                    <ArrowRight />
                                                </button>
                                                <button
                                                    onClick={() =>
                                                        go(
                                                            scenes.findIndex(
                                                                (s) =>
                                                                    s.kind ===
                                                                    'goals',
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <span>02</span>
                                                    <h2>Определить цель</h2>
                                                    <p>
                                                        Сумма, срок, риск —
                                                        затем продукт.
                                                    </p>
                                                    <ArrowRight />
                                                </button>
                                                <button
                                                    onClick={() =>
                                                        go(
                                                            scenes.findIndex(
                                                                (s) =>
                                                                    s.kind ===
                                                                    'pitch',
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <span>03</span>
                                                    <h2>Объяснить бизнес</h2>
                                                    <p>
                                                        Капитал, действия,
                                                        возврат и сценарии.
                                                    </p>
                                                    <ArrowRight />
                                                </button>
                                            </div>
                                            <div className="finish-links">
                                                <a
                                                    href="https://finuslugi.ru/"
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Финуслуги ↗
                                                </a>
                                                <a
                                                    href="https://school.moex.com/courses/investicii-s-nulya"
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Бесплатный курс ↗
                                                </a>
                                                <button
                                                    onClick={() =>
                                                        setSourceOpen(true)
                                                    }
                                                >
                                                    Материалы и источники ↗
                                                </button>
                                                <span>
                                                    gleb.finance/lecture
                                                </span>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </>
                        )}
                    </section>
                    <footer className="lecture-footer">
                        <div>
                            <button
                                onClick={() => setHelp(true)}
                                aria-label="Как управлять презентацией"
                            >
                                <CircleHelp size={22} />
                            </button>
                            <button onClick={() => setNotes(!notes)}>
                                Заметки
                            </button>
                            <button onClick={() => setSourceOpen(true)}>
                                Источники
                            </button>
                        </div>
                        <div
                            className="lecture-progress"
                            aria-label="Ход презентации"
                        >
                            {scenes.map((s, i) => (
                                <button
                                    key={i}
                                    onClick={() => go(i)}
                                    className={
                                        i === slide
                                            ? 'current'
                                            : i < slide
                                              ? 'passed'
                                              : ''
                                    }
                                    aria-label={i + 1 + '. ' + s.title}
                                />
                            ))}
                        </div>
                        <div className="slide-controls">
                            <span>
                                {String(slide + 1).padStart(2, '0')} /{' '}
                                {scenes.length}
                            </span>
                            <button
                                aria-label="Предыдущий слайд"
                                disabled={slide === 0}
                                onClick={() => go(slide - 1)}
                            >
                                <ArrowLeft />
                            </button>
                            <button
                                aria-label="Следующий слайд"
                                disabled={slide === scenes.length - 1}
                                onClick={() => go(slide + 1)}
                            >
                                <ArrowRight />
                            </button>
                        </div>
                    </footer>
                    {(error || fullscreenError) && (
                        <div className="lecture-toast" role="status">
                            {error || fullscreenError}
                        </div>
                    )}
                    {notes && (
                        <aside className="speaker-notes">
                            <button
                                aria-label="Закрыть заметки"
                                onClick={() => setNotes(false)}
                            >
                                <X />
                            </button>
                            <span className="eyebrow">
                                Ведущему · {planned}–{planned + scene.minutes}{' '}
                                мин
                            </span>
                            <p>{scene.notes}</p>
                        </aside>
                    )}
                    {panel && (
                        <div className="lecture-modal-backdrop">
                            <section
                                className="presenter-panel"
                                role="dialog"
                                aria-label="Панель ведущего"
                            >
                                <button
                                    className="modal-close"
                                    aria-label="Закрыть панель"
                                    onClick={() => setPanel(false)}
                                >
                                    <X />
                                </button>
                                <span className="eyebrow">Панель ведущего</span>
                                <h2>Управление эфиром</h2>
                                <div className="presenter-timer">
                                    <span
                                        className={
                                            elapsed >= 2400 ? 'coral' : ''
                                        }
                                    >
                                        {timer}
                                    </span>
                                    <button
                                        onClick={() => setRunning(!running)}
                                    >
                                        {running ? <Pause /> : <Play />}
                                        {running ? 'Пауза' : 'Старт'}
                                    </button>
                                    <small>План · 40:00</small>
                                </div>
                                {!room || room.ended ? (
                                    <>
                                        <p>
                                            Создайте отдельный эфир для
                                            аудитории. QR появится на слайдах с
                                            вопросами.
                                        </p>
                                        <button
                                            className="primary-button"
                                            disabled={busy || !createRoomUrl}
                                            onClick={() =>
                                                createRoomUrl &&
                                                void send(
                                                    createRoomUrl,
                                                    'POST',
                                                    {},
                                                )
                                            }
                                        >
                                            Создать эфир
                                        </button>
                                    </>
                                ) : (
                                    <>
                                        <div className="room-summary">
                                            <img
                                                src={room.qrUrl}
                                                alt="QR-код аудитории"
                                            />
                                            <div>
                                                <span className="eyebrow">
                                                    Код подключения
                                                </span>
                                                <b>{room.code}</b>
                                                <a
                                                    href={room.joinUrl}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Открыть страницу участника ↗
                                                </a>
                                                <small>
                                                    Ссылка работает 12 часов.
                                                    Закрытые ответы, без имён и
                                                    сообщений.
                                                </small>
                                            </div>
                                        </div>
                                        <label className="question-select">
                                            Вопрос
                                            <select
                                                value={room.question?.id ?? ''}
                                                disabled={busy || room.open}
                                                onChange={(e) =>
                                                    action(
                                                        'question',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="" disabled>
                                                    Выберите вопрос
                                                </option>
                                                {questions.map((q) => (
                                                    <option
                                                        key={q.id}
                                                        value={q.id}
                                                    >
                                                        {q.text}
                                                    </option>
                                                ))}
                                            </select>
                                            <ChevronDown />
                                        </label>
                                        <div className="poll-control-buttons">
                                            <button
                                                disabled={
                                                    busy ||
                                                    !room.question ||
                                                    room.open ||
                                                    room.revealed
                                                }
                                                onClick={() => action('open')}
                                            >
                                                Открыть
                                            </button>
                                            <button
                                                disabled={busy || !room.open}
                                                onClick={() => action('close')}
                                            >
                                                Закрыть
                                            </button>
                                            <button
                                                disabled={
                                                    busy ||
                                                    !room.question ||
                                                    room.revealed
                                                }
                                                onClick={() => action('reveal')}
                                            >
                                                Показать результаты
                                            </button>
                                        </div>
                                        <p className="room-state">
                                            {room.open
                                                ? 'Голосование идёт'
                                                : room.revealed
                                                  ? 'Результаты раскрыты'
                                                  : 'Голосование закрыто'}{' '}
                                            · {room.totalVotes} ответов
                                        </p>
                                        {room.counts && room.question && (
                                            <div className="host-counts">
                                                {room.question.options.map(
                                                    (o, i) => (
                                                        <span key={o}>
                                                            {o}
                                                            <b>
                                                                {room.counts?.[
                                                                    i
                                                                ] ?? 0}
                                                            </b>
                                                        </span>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                        <div className="end-session">
                                            {endConfirm ? (
                                                <>
                                                    <span>
                                                        Завершить текущий эфир?
                                                    </span>
                                                    <button
                                                        disabled={busy}
                                                        onClick={() => {
                                                            action('end');
                                                            setEndConfirm(
                                                                false,
                                                            );
                                                        }}
                                                    >
                                                        Да, завершить
                                                    </button>
                                                    <button
                                                        onClick={() =>
                                                            setEndConfirm(false)
                                                        }
                                                    >
                                                        Отмена
                                                    </button>
                                                </>
                                            ) : (
                                                <button
                                                    onClick={() =>
                                                        setEndConfirm(true)
                                                    }
                                                >
                                                    Завершить эфир
                                                </button>
                                            )}
                                        </div>
                                    </>
                                )}
                                {error && (
                                    <p className="panel-error" role="alert">
                                        {error}
                                    </p>
                                )}
                                <p className="fine">
                                    Один ответ с браузера. Ограничение частоты
                                    запросов. Новое окно или устройство может
                                    создать нового участника.
                                </p>
                            </section>
                        </div>
                    )}
                    {contents && (
                        <div className="lecture-modal-backdrop">
                            <section
                                className="contents-panel"
                                role="dialog"
                                aria-label="Оглавление"
                            >
                                <button
                                    className="modal-close"
                                    aria-label="Закрыть оглавление"
                                    onClick={() => setContents(false)}
                                >
                                    <X />
                                </button>
                                <h2>Маршрут на 40 минут</h2>
                                <div>
                                    {scenes.map((s, i) => (
                                        <button
                                            key={i}
                                            onClick={() => {
                                                go(i);
                                                setContents(false);
                                            }}
                                            className={
                                                slide === i ? 'chosen' : ''
                                            }
                                        >
                                            <span>
                                                {String(i + 1).padStart(2, '0')}
                                            </span>
                                            <b>{s.title}</b>
                                            <small>{s.minutes} мин</small>
                                        </button>
                                    ))}
                                </div>
                            </section>
                        </div>
                    )}
                    {sourceOpen && (
                        <div className="lecture-modal-backdrop">
                            <section
                                className="sources-panel"
                                role="dialog"
                                aria-label="Материалы и источники"
                            >
                                <button
                                    className="modal-close"
                                    aria-label="Закрыть источники"
                                    onClick={() => setSourceOpen(false)}
                                >
                                    <X />
                                </button>
                                <h2>Материалы и источники</h2>
                                <p>
                                    Карта — по схеме автора в Miro. Блок о
                                    накоплениях — по четырём предоставленным
                                    выступлениям. Блок о финансировании бизнеса
                                    подготовлен для этой лекции.
                                </p>
                                <div>
                                    {sources.map(([label, url]) => (
                                        <a
                                            href={url}
                                            target="_blank"
                                            rel="noreferrer"
                                            key={url}
                                        >
                                            {label} ↗
                                        </a>
                                    ))}
                                </div>
                                <p className="fine">
                                    PDF автора: «Инвестиции в будущее», «Что
                                    такое ПИФы и зачем они нужны», «Практикум.
                                    Зачем инвестировать сегодня», «Жизнь с
                                    комфортом. Инвестируем в будущее». Проверка
                                    ссылок и условий: 15.11.2026. Материал
                                    образовательный; расчёты не являются
                                    прогнозом доходности.
                                </p>
                            </section>
                        </div>
                    )}
                    {help && (
                        <div className="lecture-modal-backdrop">
                            <section
                                className="help-panel"
                                role="dialog"
                                aria-label="Управление"
                            >
                                <button
                                    className="modal-close"
                                    aria-label="Закрыть помощь"
                                    onClick={() => setHelp(false)}
                                >
                                    <X />
                                </button>
                                <h2>Управление со сцены</h2>
                                <p>
                                    <kbd>→</kbd> <kbd>Пробел</kbd> следующий
                                    слайд
                                </p>
                                <p>
                                    <kbd>←</kbd> предыдущий слайд
                                </p>
                                <p>
                                    <kbd>F</kbd> полный экран · <kbd>N</kbd>{' '}
                                    заметки · <kbd>P</kbd> эфир
                                </p>
                                <p>
                                    На карте нажимайте участников, переключайте
                                    слои и маршруты. Голосования открывайте и
                                    раскрывайте вручную.
                                </p>
                                <p className="fine">
                                    Рабочий формат — 1920 × 1080. Встроенный
                                    таймер находится в панели ведущего. На
                                    телефоне аудитории открывается отдельная
                                    страница голосования.
                                </p>
                            </section>
                        </div>
                    )}
                </main>
            </div>
        </>
    );
}
