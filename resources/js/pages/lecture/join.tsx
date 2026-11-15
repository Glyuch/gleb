import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Check, Radio, ShieldCheck } from 'lucide-react';
import { show } from '@/actions/App/Http/Controllers/LectureController';
import { FinuslugiLogo } from './brand';
import { formatVoteCount, useLectureRoom } from './room-client';
import type { Room } from './types';
import './lecture.css';

export default function Join({ room: initialRoom }: { room: Room }) {
    const { room, error, busy, send } = useLectureRoom(initialRoom);

    if (!room) {
        return null;
    }

    const question = room.question;
    const submitted = room.myVote !== null;

    return (
        <>
            <Head title="Голосование — Деньги. Рынок. Вы." />
            <main className="audience-page">
                <header>
                    <FinuslugiLogo />
                    <span className="room-badge">{room.code}</span>
                </header>
                <div className="audience-intro">
                    <span className="eyebrow">Деньги. Рынок. Вы.</span>
                    <div className="audience-status">
                        <span className={room.open ? 'live-dot' : 'idle-dot'} />
                        {room.ended
                            ? 'Эфир завершён'
                            : room.open
                              ? 'Можно отвечать'
                              : room.revealed
                                ? 'Результаты'
                                : 'Ждём ведущего'}
                    </div>
                </div>
                {room.ended ? (
                    <section className="audience-wait">
                        <Check size={60} />
                        <h1>Спасибо за участие</h1>
                        <p>
                            Карта рынка и материалы остаются доступны после
                            лекции.
                        </p>
                        <Link className="primary-button" href={show.url()}>
                            Открыть презентацию <ArrowRight />
                        </Link>
                        <a href="https://school.moex.com/courses/investicii-s-nulya">
                            Бесплатный курс «Инвестиции с нуля» ↗
                        </a>
                    </section>
                ) : !question ? (
                    <section className="audience-wait">
                        <Radio size={64} />
                        <h1>Вы подключились</h1>
                        <p>
                            Когда ведущий откроет вопрос, он появится здесь
                            автоматически. Оставьте страницу открытой.
                        </p>
                    </section>
                ) : (
                    <section className="audience-question">
                        <h1>{question.text}</h1>
                        {submitted && !room.revealed && (
                            <div className="answer-saved" role="status">
                                <Check />
                                Ответ принят. Ждём результатов.
                            </div>
                        )}
                        <div className="audience-options">
                            {question.options.map((option, index) => {
                                const correct =
                                    room.revealed &&
                                    question.correctIndex === index;
                                const count = room.revealed
                                    ? (room.counts?.[index] ?? 0)
                                    : 0;
                                const percent =
                                    room.revealed && room.totalVotes
                                        ? Math.round(
                                              (count / room.totalVotes) * 100,
                                          )
                                        : 0;

                                return (
                                    <button
                                        key={question.id + index}
                                        className={
                                            (room.myVote === index
                                                ? 'selected '
                                                : '') +
                                            (correct ? 'correct' : '')
                                        }
                                        disabled={
                                            busy || !room.open || submitted
                                        }
                                        onClick={() =>
                                            void send(room.voteUrl, 'POST', {
                                                questionId: question.id,
                                                revision: room.revision,
                                                option: index,
                                            })
                                        }
                                    >
                                        <div
                                            className="audience-result-bar"
                                            style={{ width: percent + '%' }}
                                        />
                                        <span className="option-letter">
                                            {String.fromCharCode(65 + index)}
                                        </span>
                                        <span>
                                            {option}
                                            {room.myVote === index && (
                                                <small>Ваш ответ</small>
                                            )}
                                        </span>
                                        {room.revealed ? (
                                            <b>{percent}%</b>
                                        ) : room.myVote === index ? (
                                            <Check />
                                        ) : null}
                                    </button>
                                );
                            })}
                        </div>
                        {busy && <p role="status">Сохраняем ответ…</p>}
                        {room.revealed && (
                            <div className="audience-explanation">
                                <b>{formatVoteCount(room.totalVotes)}</b>
                                <p>
                                    {question.explanation ||
                                        'Так распределились ответы аудитории.'}
                                </p>
                                <span>
                                    Следующий вопрос появится автоматически.
                                </span>
                            </div>
                        )}
                        {!room.open && !room.revealed && !submitted && (
                            <p>
                                Ведущий пока не принимает ответы на этот вопрос.
                            </p>
                        )}
                    </section>
                )}
                {error && (
                    <div className="audience-error" role="alert">
                        {error}
                        <button onClick={() => window.location.reload()}>
                            Обновить подключение
                        </button>
                    </div>
                )}
                <footer>
                    <ShieldCheck size={19} />
                    <p>
                        Без регистрации и имён. Один ответ на вопрос с этого
                        браузера. Технический cookie сохраняет ваш ответ на
                        время эфира.
                    </p>
                </footer>
            </main>
        </>
    );
}
