export function projectSavings(
    initial: number,
    monthly: number,
    years: number,
    annualPercent: number,
) {
    const months = Math.round(years * 12);
    const rate = (1 + annualPercent / 100) ** (1 / 12) - 1;
    const contributions = initial + monthly * months;
    const total =
        Math.abs(rate) < 1e-10
            ? contributions
            : initial * (1 + rate) ** months +
              monthly * (((1 + rate) ** months - 1) / rate);

    return { total, contributions, growth: total - contributions };
}
export const rubles = (value: number) =>
    new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: 'RUB',
        maximumFractionDigits: 0,
    }).format(value);
