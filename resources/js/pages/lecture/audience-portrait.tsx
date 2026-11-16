import type { CSSProperties } from 'react';
import { cloudWords, distribution } from './portrait-data';
import { formatVoteCount } from './room-client';
import type { Question, Room } from './types';

export function ProfessionCloud({
    options,
    counts,
    revealed,
    compact = false,
}: {
    options: string[];
    counts?: number[] | null;
    revealed: boolean;
    compact?: boolean;
}) {
    // Counts are never used until the presenter has revealed this question.
    const visibleCounts = revealed ? counts : null;
    const words = cloudWords(options, visibleCounts, compact);
    const hasAnswers = revealed && words.some((word) => word.count > 0);

    return (
        <div className={'profession-visual' + (compact ? ' compact' : '')}>
            <div
                className={
                    'profession-cloud' +
                    (hasAnswers ? ' revealed' : ' placeholder')
                }
                role="list"
                aria-label="Сферы аудитории"
            >
                {words.map((word) => (
                    <div
                        role="listitem"
                        key={word.index}
                        className={
                            'profession-word ' +
                            (word.share >= 0.2 ? 'major' : '')
                        }
                        style={
                            {
                                '--word-size': word.fontSize + 'px',
                            } as CSSProperties
                        }
                    >
                        <span>{word.label}</span>
                        {hasAnswers && (
                            <small aria-label={formatVoteCount(word.count)}>
                                {word.count}
                            </small>
                        )}
                    </div>
                ))}
            </div>
            <p className="portrait-chart-note">
                {hasAnswers
                    ? 'Чем больше слово, тем больше ответов.'
                    : revealed
                      ? 'Пока нет ответов на этот вопрос.'
                      : 'Выбирайте на телефоне. Облако зала появится в общих итогах.'}
            </p>
        </div>
    );
}
export function SavingsDistribution({
    options,
    counts,
    compact = false,
}: {
    options: string[];
    counts: number[];
    compact?: boolean;
}) {
    const rows = distribution(options, counts);
    const max = Math.max(1, ...rows.map((row) => row.count));
    const labels = ['0%', '5%', '10%', '20%', '30%', '>30%'];

    return (
        <div className={'savings-distribution' + (compact ? ' compact' : '')}>
            <div
                className="savings-columns"
                role="list"
                aria-label="Доля дохода, которую откладывает аудитория"
            >
                {rows.map((row) => (
                    <div
                        className="savings-column"
                        role="listitem"
                        key={row.index}
                        aria-label={
                            row.label +
                            ': ' +
                            formatVoteCount(row.count) +
                            ', ' +
                            row.percent +
                            '% аудитории'
                        }
                    >
                        <div className="savings-track">
                            <div
                                className="savings-fill"
                                style={{
                                    height: (row.count / max) * 100 + '%',
                                }}
                            />
                            <b>{row.percent}%</b>
                        </div>
                        <strong>{labels[row.index] ?? row.label}</strong>
                        <small>{formatVoteCount(row.count)}</small>
                    </div>
                ))}
            </div>
            <p className="portrait-chart-note">
                Внизу — доля дохода на накопления. Над столбцами — доля
                ответивших.
            </p>
        </div>
    );
}
export function ExperienceDistribution({
    options,
    counts,
    compact = false,
}: {
    options: string[];
    counts: number[];
    compact?: boolean;
}) {
    const rows = distribution(options, counts);
    const labels = [
        'Пока не разбираюсь',
        'Вклады и счета',
        'Акции, облигации, фонды',
        'Сложные инструменты',
    ];

    return (
        <div
            className={'experience-distribution' + (compact ? ' compact' : '')}
            role="list"
            aria-label="Опыт аудитории в финансах"
        >
            {rows.map((row) => (
                <div className="experience-row" role="listitem" key={row.index}>
                    <span className="experience-level">{row.index}</span>
                    <div>
                        <span>
                            {compact
                                ? labels[row.index]
                                : row.label.replace(/^\d+ — /, '')}
                        </span>
                        <div className="experience-track">
                            <i style={{ width: row.share * 100 + '%' }} />
                        </div>
                    </div>
                    <b>
                        {row.percent}%
                        <small>{formatVoteCount(row.count)}</small>
                    </b>
                </div>
            ))}
            <p className="portrait-chart-note">
                Самооценка опыта. Статус квалифицированного инвестора здесь не
                проверяется.
            </p>
        </div>
    );
}
export default function AudiencePortrait({
    questions,
    room,
}: {
    questions: Question[];
    room: Room | null;
}) {
    const cards = [
        {
            id: 'profession',
            number: '01',
            title: 'Кто мы',
            subtitle: 'Креативные сферы',
        },
        {
            id: 'savings_rate',
            number: '02',
            title: 'Как копим',
            subtitle: 'Доля дохода в месяц',
        },
        {
            id: 'financial_level',
            number: '03',
            title: 'Наш опыт',
            subtitle: 'Самооценка от 0 до 3',
        },
    ];

    return (
        <div className="portrait-summary">
            <div className="portrait-panels">
                {cards.map((card) => {
                    const question = questions.find(
                        (question) => question.id === card.id,
                    );
                    const result = room?.portrait?.find(
                        (result) => result.questionId === card.id,
                    );
                    const counts =
                        result?.counts ?? question?.options.map(() => 0) ?? [];

                    return (
                        <article
                            className={'portrait-panel portrait-' + card.id}
                            key={card.id}
                        >
                            <div className="portrait-panel-head">
                                <span>{card.number}</span>
                                <small>
                                    {result
                                        ? formatVoteCount(result.totalVotes)
                                        : 'Ждём результаты'}
                                </small>
                            </div>
                            <h2>{card.title}</h2>
                            <p>{card.subtitle}</p>
                            {!question ? (
                                <p className="portrait-pending">
                                    Подключаем вопросы…
                                </p>
                            ) : card.id === 'profession' ? (
                                <ProfessionCloud
                                    options={question.options}
                                    counts={counts}
                                    revealed={Boolean(result)}
                                    compact
                                />
                            ) : !result ? (
                                <div className="portrait-pending">
                                    Результаты появятся после раскрытия вопроса
                                    ведущим.
                                </div>
                            ) : card.id === 'savings_rate' ? (
                                <SavingsDistribution
                                    options={question.options}
                                    counts={counts}
                                    compact
                                />
                            ) : (
                                <ExperienceDistribution
                                    options={question.options}
                                    counts={counts}
                                    compact
                                />
                            )}
                        </article>
                    );
                })}
            </div>
            <p className="portrait-summary-caption">
                Это портрет нашего зала сегодня. На каждом графике — ответы на
                свой вопрос.
            </p>
        </div>
    );
}
