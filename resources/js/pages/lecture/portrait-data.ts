export const portraitQuestionIds = [
    'profession',
    'savings_rate',
    'financial_level',
] as const;
export function isPortraitQuestion(id: string): boolean {
    return (portraitQuestionIds as readonly string[]).includes(id);
}
export function portraitHint(id: string): string {
    if (id === 'profession') {
        return 'Выберите одну основную сферу или ближайшее направление.';
    }

    if (id === 'savings_rate') {
        return 'Выберите ближайший вариант. Откладывать — значит оставлять деньги на будущие цели.';
    }

    if (id === 'financial_level') {
        return 'Оцените свой опыт. Здесь нет правильных и неправильных ответов.';
    }

    return '';
}
export function distribution(
    options: string[],
    counts: number[] | null | undefined,
) {
    const values = options.map((label, index) => ({
        label,
        index,
        count: Math.max(0, counts?.[index] ?? 0),
    }));
    const total = values.reduce((sum, item) => sum + item.count, 0);

    return values.map((item) => ({
        ...item,
        share: total ? item.count / total : 0,
        percent: total ? Math.round((item.count / total) * 100) : 0,
    }));
}
export function cloudWords(
    options: string[],
    counts: number[] | null | undefined,
    compact = false,
) {
    const words = distribution(options, counts);
    const hasAnswers = words.some((word) => word.count > 0);

    return words
        .filter((word) => !hasAnswers || word.count > 0)
        .sort((a, b) => b.count - a.count || a.index - b.index)
        .map((word) => ({
            ...word,
            fontSize: hasAnswers
                ? Math.round(
                      (compact ? 18 : 30) +
                          Math.sqrt(word.share) * (compact ? 32 : 48),
                  )
                : compact
                  ? 19
                  : 28,
        }));
}
