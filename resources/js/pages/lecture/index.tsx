import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    BookOpen,
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
import AudiencePortrait from './audience-portrait';
import { FinuslugiLogo } from './brand';
import {
    Funding,
    Goals,
    Moneybox,
    Pitch,
    Platform,
    SavingsCalculator,
    School,
} from './interactions';
import MarketMap from './market-map';
import Poll from './poll';
import { clickerDirection, nextCue, stepCount } from './presentation';
import { formatVoteCount, useLectureRoom } from './room-client';
import { blocks, scenes, sources } from './scenes';
import type { LectureProps, Room } from './types';
import { usePresentation } from './use-presentation';
import './lecture.css';

function Cover({ room }: { room: Room | null }) {
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
            <div className="cover-network">
                <div className="cover-orbit orbit-one" />
                <div className="cover-orbit orbit-two" />
                <div className="cover-orbit orbit-three" />
                <div className="network-core">КАПИТАЛ</div>
                <div className="network-node node-one">
                    <span>01</span>
                    <b>Рынок</b>
                </div>
                <div className="network-node node-two">
                    <span>02</span>
                    <b>Накопления</b>
                </div>
                <div className="network-node node-three">
                    <span>03</span>
                    <b>Бизнес</b>
                </div>
                <div className="orbit-particle" />
                {room && !room.ended && (
                    <div className="cover-join" aria-hidden="false">
                        <img
                            src={room.qrUrl}
                            alt="QR-код подключения к лекции"
                        />
                        <div>
                            <b>Подключайтесь к лекции</b>
                            <span>Один QR на всё выступление</span>
                            <strong>{room.code}</strong>
                        </div>
                    </div>
                )}
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
    const [scale, setScale] = useState(1);
    const [panel, setPanel] = useState(false);
    const [notes, setNotes] = useState(false);
    const [contents, setContents] = useState(false);
    const [sourceOpen, setSourceOpen] = useState(false);
    const [help, setHelp] = useState(false);
    const [elapsed, setElapsed] = useState(0);
    const [fullscreenError, setFullscreenError] = useState('');
    const [endConfirm, setEndConfirm] = useState(false);
    const { room, error, busy, send } = useLectureRoom(initialRoom);
    const playback = usePresentation(room, send, createRoomUrl, isPresenter);
    const { slide, step, active: clicker, go, move } = playback;
    const [running, setRunning] = useState(playback.active);
    const scene = scenes[slide];
    const presentationProps = clicker
        ? { presentationStep: step, onPresentationStepChange: playback.setStep }
        : {};
    const controlsBusy = busy || playback.transitioning;
    useEffect(() => {
        const resize = () =>
            setScale(
                Math.min(window.innerWidth / 1920, window.innerHeight / 1080),
            );
        resize();
        window.addEventListener('resize', resize);

        return () => {
            window.removeEventListener('resize', resize);
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
    const launch = async () => {
        if (controlsBusy) {
            return;
        }

        if (!document.fullscreenElement) {
            void full();
        }

        if (await playback.start()) {
            setRunning(true);
            setElapsed(0);
            setPanel(false);
            setNotes(false);
            setHelp(false);
            setContents(false);
            setSourceOpen(false);
            (document.activeElement as HTMLElement | null)?.blur();
        }
    };
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

            const direction = clickerDirection(event.key);

            if (direction) {
                if (
                    !clicker &&
                    [' ', 'Enter'].includes(event.key) &&
                    target.closest('button,a,[role="button"]')
                ) {
                    return;
                }

                event.preventDefault();

                if (!event.repeat && !controlsBusy) {
                    void move(direction);
                }

                return;
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
    }, [
        clicker,
        controlsBusy,
        move,
        go,
        full,
        isPresenter,
        panel,
        contents,
        sourceOpen,
        help,
    ]);
    useEffect(() => {
        if (!running || room?.ended) {
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
    }, [running, room?.ended]);
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
            <div className={'lecture-shell' + (clicker ? ' clicker-mode' : '')}>
                <main
                    className="lecture-stage"
                    style={{ '--lecture-scale': scale } as CSSProperties}
                >
                    <header className="lecture-header">
                        <button
                            className="lecture-brand"
                            aria-label="Финуслуги — к началу лекции"
                            onClick={() => go(0)}
                        >
                            <FinuslugiLogo />
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
                            {isPresenter && (!clicker || room?.ended) && (
                                <button
                                    className="launch-lecture"
                                    disabled={controlsBusy}
                                    onClick={() => void launch()}
                                >
                                    <Play size={21} />
                                    {controlsBusy
                                        ? 'Подключаем…'
                                        : 'Начать лекцию'}
                                </button>
                            )}
                            {clicker && !room?.ended && (
                                <span className="clicker-badge">
                                    Кликер · {timer}
                                </span>
                            )}
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
                            <Cover room={clicker ? room : null} />
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
                                            {...presentationProps}
                                        />
                                    )}
                                    {scene.kind === 'poll' && question && (
                                        <Poll
                                            question={question}
                                            room={room}
                                            isPresenter={isPresenter}
                                            busy={controlsBusy}
                                            onAction={action}
                                            clicker={clicker}
                                            step={step}
                                        />
                                    )}
                                    {scene.kind === 'portrait' && (
                                        <AudiencePortrait
                                            questions={questions}
                                            room={room}
                                        />
                                    )}
                                    {scene.kind === 'goals' && (
                                        <Goals {...presentationProps} />
                                    )}
                                    {scene.kind === 'calculator' && (
                                        <SavingsCalculator
                                            {...presentationProps}
                                        />
                                    )}
                                    {scene.kind === 'platform' && (
                                        <Platform {...presentationProps} />
                                    )}
                                    {scene.kind === 'moneybox' && (
                                        <Moneybox {...presentationProps} />
                                    )}
                                    {scene.kind === 'school' && (
                                        <School qrUrl="/media/lecture-course.svg" />
                                    )}
                                    {scene.kind === 'funding' && (
                                        <Funding {...presentationProps} />
                                    )}
                                    {scene.kind === 'pitch' && (
                                        <Pitch {...presentationProps} />
                                    )}
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
                        {clicker && (
                            <div className="clicker-cue" aria-live="polite">
                                <span>
                                    {room?.ended
                                        ? 'Спасибо за участие'
                                        : controlsBusy
                                          ? 'Синхронизация…'
                                          : playback.retrying
                                            ? '→ Повторить шаг'
                                            : '→ ' + nextCue(scene, step)}
                                </span>
                                <small>
                                    Шаг {step + 1} / {stepCount(scene)}
                                </small>
                            </div>
                        )}
                        <div className="lecture-tools">
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
                                disabled={
                                    controlsBusy ||
                                    (slide === 0 && step === 0) ||
                                    Boolean(clicker && room?.ended)
                                }
                                onClick={() => void playback.move(-1)}
                            >
                                <ArrowLeft />
                            </button>
                            <button
                                aria-label="Следующий слайд"
                                disabled={
                                    controlsBusy ||
                                    (slide === scenes.length - 1 &&
                                        (!clicker ||
                                            step === stepCount(scene) - 1))
                                }
                                onClick={() => void playback.move(1)}
                            >
                                <ArrowRight />
                            </button>
                        </div>
                    </footer>
                    {(error || fullscreenError) && (
                        <div className="lecture-toast" role="status">
                            {error || fullscreenError}
                            {playback.retrying &&
                                ' Нажмите «вперёд», чтобы повторить этот шаг.'}
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
                                {!clicker && room && !room.ended && (
                                    <div className="launch-explanation">
                                        <p>
                                            Один запуск — и дальше только
                                            кликер. Вопрос, открытие, закрытие и
                                            результаты идут отдельными шагами.
                                        </p>
                                        <button
                                            className="primary-button"
                                            disabled={controlsBusy}
                                            onClick={() => void launch()}
                                        >
                                            <Play />
                                            Начать лекцию
                                        </button>
                                    </div>
                                )}
                                {clicker && (
                                    <p className="clicker-panel-note">
                                        Режим кликера включён. Закройте панель и
                                        нажимайте «вперёд». Голосования и
                                        интерактивы переключаются вместе со
                                        слайдами.
                                    </p>
                                )}
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
                                            Нажмите один раз: создадим эфир и
                                            QR-код. Дальше лекцией управляет
                                            кликер.
                                        </p>
                                        <button
                                            className="primary-button"
                                            disabled={busy || !createRoomUrl}
                                            onClick={() => void launch()}
                                        >
                                            Начать лекцию
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
                                        <label
                                            className="question-select"
                                            hidden={clicker}
                                        >
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
                                        <div
                                            className="poll-control-buttons"
                                            hidden={clicker}
                                        >
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
                                            · {formatVoteCount(room.totalVotes)}
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
                                    <kbd>→</kbd> <kbd>Page Down</kbd>{' '}
                                    <kbd>Пробел</kbd> следующий шаг
                                </p>
                                <p>
                                    <kbd>←</kbd> <kbd>Page Up</kbd> предыдущий
                                    шаг
                                </p>
                                <p>
                                    <kbd>F</kbd> полный экран · <kbd>N</kbd>{' '}
                                    заметки · <kbd>P</kbd> эфир
                                </p>
                                <p>
                                    Нажмите «Начать лекцию» один раз. На
                                    вопросах: вопрос и QR → открыть голосование
                                    → закрыть → показать результаты → дальше.
                                    Карта и примеры раскрываются тем же
                                    кликером. На финальном слайде следующее
                                    нажатие завершит эфир.
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
