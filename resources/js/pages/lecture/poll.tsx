import { Check, Radio } from 'lucide-react';
import { ProfessionCloud } from './audience-portrait';
import {
    isPortraitQuestion,
    portraitHint,
    portraitQuestionIds,
} from './portrait-data';
import { formatVoteCount } from './room-client';
import type { Question, Room } from './types';

export default function Poll({
    question,
    room,
    isPresenter,
    busy,
    onAction,
    clicker = false,
    step = 0,
}: {
    question: Question;
    room: Room | null;
    isPresenter: boolean;
    busy: boolean;
    onAction: (action: string, questionId?: string) => void;
    clicker?: boolean;
    step?: number;
}) {
    const active = room?.question?.id === question.id && !room.ended;
    const survey = isPortraitQuestion(question.id);
    const results = active && room.revealed && !survey;
    const hint = portraitHint(question.id);

    return (
        <div className={'poll-layout' + (survey ? ' portrait-poll' : '')}>
            <div className="poll-answers">
                <div className="poll-status">
                    <span
                        className={
                            active && room.open ? 'live-dot' : 'idle-dot'
                        }
                    />
                    {survey && (
                        <span>
                            Вопрос{' '}
                            {portraitQuestionIds.findIndex(
                                (id) => id === question.id,
                            ) + 1}{' '}
                            из 3 ·
                        </span>
                    )}
                    {!room
                        ? 'Обсудим с залом'
                        : !active
                          ? 'Вопрос ещё не выбран'
                          : room.open
                            ? 'Голосование открыто'
                            : survey && room.revealed
                              ? 'Итоги — на общем слайде'
                              : results
                                ? 'Результаты голосования'
                                : clicker && step === 2
                                  ? 'Голосование закрыто'
                                  : 'Ожидаем открытия'}
                    {active && <b>{formatVoteCount(room.totalVotes)}</b>}
                </div>
                {hint && <p className="portrait-question-hint">{hint}</p>}
                {question.id === 'profession' ? (
                    <ProfessionCloud
                        options={question.options}
                        revealed={false}
                    />
                ) : (
                    <div
                        className={
                            question.options.length > 4
                                ? 'poll-option-grid'
                                : ''
                        }
                    >
                        {question.options.map((option, index) => {
                            const count = results
                                ? (room.counts?.[index] ?? 0)
                                : 0;
                            const percent =
                                results && room.totalVotes
                                    ? Math.round(
                                          (count / room.totalVotes) * 100,
                                      )
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
                                    {!survey && (
                                        <span className="option-letter">
                                            {String.fromCharCode(65 + index)}
                                        </span>
                                    )}
                                    <span>{option}</span>
                                    {results && (
                                        <b>
                                            {percent}% <small>· {count}</small>
                                        </b>
                                    )}
                                    {results &&
                                        room.question?.correctIndex ===
                                            index && <Check />}
                                </div>
                            );
                        })}
                    </div>
                )}
                {results && !survey && room.question?.explanation && (
                    <p className="poll-explanation">
                        {room.question.explanation}
                    </p>
                )}
                {isPresenter && !clicker && !survey && room && !room.ended && (
                    <div className="poll-control-buttons" hidden={clicker}>
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
                                ? 'Нажмите «Начать лекцию». QR-код будет единым для всех вопросов.'
                                : 'Ведущий покажет QR-код и откроет вопросы во время выступления.'}
                        </p>
                    </>
                )}
            </div>
        </div>
    );
}
