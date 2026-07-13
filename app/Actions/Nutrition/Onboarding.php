<?php

namespace App\Actions\Nutrition;

use App\Models\NutritionProfile;
use App\Support\Nutrition\Claude;
use App\Support\Nutrition\TelegramClient;
use Carbon\CarbonImmutable;

/**
 * Стейт-машина анкеты онбординга нового клиента (6 шагов). Состояние живёт в
 * awaiting['onboarding_step'] (номер текущего вопроса) и awaiting['onboarding_answers']
 * (map шаг → ответ). Пока профиль в статусе onboarding, ProcessNutritionUpdate шлёт
 * сюда весь свободный текст мимо MealIntent/HandleQuestion/HandleNumbers.
 *
 * По завершении (6-й ответ или «Пропустить») ответы сжимаются моделью чата в
 * нейтральный ai_profile, статус переводится в active и предлагается кнопка старта
 * программы (существующий callback program:start).
 */
class Onboarding
{
    /** Тексты вопросов анкеты по номеру шага. */
    private const QUESTIONS = [
        1 => 'Давай знакомиться 🙌 Как к тебе обращаться и какая у тебя цель? (снизить вес / набрать форму / больше энергии …)',
        2 => 'Записал 👌🏻 Теперь параметры: вес, рост, возраст?',
        3 => 'Во сколько обычно встаёшь и ложишься? Напиши два времени, например: 07:00 и 23:00',
        4 => 'Спорт и активность — чем занимаешься и как часто?',
        5 => 'Есть ограничения в еде: аллергии, что не ешь?',
        6 => 'Последнее: заметки о здоровье, если хочешь их учесть (анализы, особенности). Можно пропустить.',
    ];

    /** Короткие ярлыки шагов для сборки сырого профиля и промпта сжатия. */
    private const LABELS = [
        1 => 'Обращение и цель',
        2 => 'Параметры (вес, рост, возраст)',
        3 => 'Режим дня (подъём/отбой)',
        4 => 'Активность',
        5 => 'Ограничения в еде',
        6 => 'Здоровье',
    ];

    private const LAST_STEP = 6;

    /**
     * Старт анкеты: фиксирует статус onboarding, сбрасывает состояние и задаёт
     * первый вопрос. Вызывается при погашении валидного инвайт-кода.
     */
    public function start(NutritionProfile $profile, ?int $chatId = null): void
    {
        $profile->update(['status' => 'onboarding']);
        $profile->setWaiting('onboarding_step', 1);
        $profile->setWaiting('onboarding_answers', []);

        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;
        $tg->send('Код принят! 🎉 Задам пару вопросов и составлю твой профиль.', chatId: $chatId);

        $this->ask($profile, 1, $chatId);
    }

    /**
     * Обрабатывает текстовый ответ на текущий вопрос: сохраняет его, выполняет
     * пошаговые сайд-эффекты (шаг 3 — режим дня) и либо задаёт следующий вопрос,
     * либо (после последнего) завершает анкету.
     */
    public function answer(NutritionProfile $profile, string $text, ?int $chatId = null): void
    {
        $step = (int) ($profile->waiting('onboarding_step') ?? 1);
        $answers = $profile->waiting('onboarding_answers');
        $answers = is_array($answers) ? $answers : [];
        $answers[$step] = trim($text);
        $profile->setWaiting('onboarding_answers', $answers);

        if ($step === 3) {
            $this->applySchedule($profile, $text);
        }

        if ($step >= self::LAST_STEP) {
            $this->finish($profile, $chatId);

            return;
        }

        $next = $step + 1;
        $profile->setWaiting('onboarding_step', $next);
        $this->ask($profile, $next, $chatId);
    }

    /**
     * Повторяет текущий вопрос анкеты (реакция на /start во время онбординга).
     */
    public function repeat(NutritionProfile $profile, ?int $chatId = null): void
    {
        $step = (int) ($profile->waiting('onboarding_step') ?? 1);
        $this->ask($profile, $step, $chatId);
    }

    /**
     * Кнопка «Пропустить» на 6-м шаге: завершает анкету без ответа о здоровье.
     */
    public function skip(NutritionProfile $profile, ?int $chatId = null): void
    {
        $this->finish($profile, $chatId);
    }

    /**
     * Задаёт вопрос шага. На последнем шаге добавляет кнопку пропуска.
     */
    private function ask(NutritionProfile $profile, int $step, ?int $chatId = null): void
    {
        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;

        $keyboard = $step === self::LAST_STEP
            ? [[['text' => 'Пропустить', 'callback_data' => 'onboard:skip']]]
            : null;

        $tg->send(self::QUESTIONS[$step], $keyboard, chatId: $chatId);
    }

    /**
     * Шаг 3: парсит подъём/отбой из текста (первые два ЧЧ:ММ), пишет в настройки и
     * сдвигает окно завтрака от подъёма (подъём+30мин … подъём+90мин); остальные
     * окна остаются дефолтными. Если время распознать не удалось — тихо пропускаем
     * (анкета не должна застревать на вводе).
     */
    private function applySchedule(NutritionProfile $profile, string $text): void
    {
        preg_match_all('/(\d{1,2}):(\d{2})/', $text, $matches, PREG_SET_ORDER);

        $times = [];
        foreach ($matches as $match) {
            $hours = (int) $match[1];
            $minutes = (int) $match[2];
            if ($hours > 23 || $minutes > 59) {
                continue;
            }
            $times[] = sprintf('%02d:%02d', $hours, $minutes);
        }

        if ($times === []) {
            return;
        }

        $wake = $times[0];
        $profile->setSetting('wake_time', $wake);

        if (isset($times[1])) {
            $profile->setSetting('sleep_time', $times[1]);
        }

        $windows = $profile->setting('default_windows');
        if (! is_array($windows)) {
            $windows = NutritionProfile::DEFAULT_SETTINGS['default_windows'];
        }

        $wakeAt = CarbonImmutable::createFromFormat('H:i', $wake);
        $windows['breakfast'] = [
            'start' => $wakeAt->addMinutes(30)->format('H:i'),
            'end' => $wakeAt->addMinutes(90)->format('H:i'),
        ];

        $profile->setSetting('default_windows', $windows);
    }

    /**
     * Завершение анкеты: сжимает ответы моделью чата в нейтральный ai_profile,
     * переводит профиль в active, чистит состояние и предлагает старт программы.
     * Идемпотентно: если профиль уже не onboarding — сообщает и выходит.
     */
    private function finish(NutritionProfile $profile, ?int $chatId = null): void
    {
        $tg = app(TelegramClient::class);
        $tg->profileId = $profile->id;

        if ($profile->status !== 'onboarding') {
            $tg->send('Мы уже закончили знакомство 👌🏻', chatId: $chatId);

            return;
        }

        $now = $profile->now();
        $answers = $profile->waiting('onboarding_answers');
        $answers = is_array($answers) ? $answers : [];
        $raw = $this->rawProfile($answers);

        $ai = Claude::text(
            [['type' => 'text', 'text' => $this->compressionPrompt($raw)]],
            (string) config('nutrition.models.chat'),
            800,
            $profile,
        );

        $aiProfile = ($ai !== null && trim($ai) !== '') ? trim($ai) : $raw;
        $aiProfile .= "\n\nОнбординг пройден ".$now->format('d.m.Y');

        $profile->ai_profile = $aiProfile;
        $profile->status = 'active';
        $profile->save();

        $profile->clearWaiting('onboarding_step');
        $profile->clearWaiting('onboarding_answers');

        $summary = "Знакомство завершено! 🎉 Вот твой профиль:\n\n"
            .$aiProfile
            ."\n\nГотов начать программу? Жми кнопку — и погнали 💪🏼";

        $tg->send(
            $summary,
            [[['text' => '🚀 Начать программу', 'callback_data' => 'program:start']]],
            chatId: $chatId,
        );
    }

    /**
     * Собирает ответы анкеты в подписанный список (ярлык: ответ). Пустые пропускает.
     * Служит и промпту сжатия, и фолбэком при недоступности модели.
     *
     * @param  array<int, mixed>  $answers
     */
    private function rawProfile(array $answers): string
    {
        $lines = [];
        foreach (self::LABELS as $step => $label) {
            $value = trim((string) ($answers[$step] ?? ''));
            if ($value === '') {
                continue;
            }
            $lines[] = $label.': '.$value;
        }

        return implode("\n", $lines);
    }

    /**
     * Промпт сжатия анкеты: строго по фактам из ответов, без домыслов, диагнозов и
     * назначений; тон — нейтральная сводка.
     */
    private function compressionPrompt(string $raw): string
    {
        return "Сожми ответы клиента из анкеты онбординга в структурированный профиль для нутрициолога.\n"
            ."Разделы (только если данные есть): Цель; Параметры (вес, рост, возраст); Режим дня; Активность; Ограничения в еде; Здоровье.\n"
            .'СТРОГО: используй только факты из ответов клиента ниже. Ничего не выдумывай — '
            .'ни данных, ни диагнозов, ни назначений, ни рекомендаций. Не ставь медицинских диагнозов '
            ."и не интерпретируй анализы. Тон — нейтральная фактическая сводка, 10–15 строк, по-русски.\n\n"
            ."Ответы клиента:\n".$raw;
    }
}
