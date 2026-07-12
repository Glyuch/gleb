<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NutritionInvite;
use App\Models\NutritionMeal;
use App\Models\NutritionMetric;
use App\Models\NutritionProfile;
use App\Support\Nutrition\ProgramStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Админка нутрициолога: список профилей, карточка профиля, управление
 * (пауза, настройки, ai_profile) и генерация инвайтов. Сайтовый admin-гейт.
 *
 * Агрегаты index-страницы (средний балл 7д, вес-дельта 30д) считаются
 * компактными inline-запросами; на show переиспользуется тот же расчёт.
 * TODO(post-merge): унифицировать с App\Support\Nutrition\NutritionStats (Task 6).
 */
class NutritionAdminController extends Controller
{
    public function index(): View
    {
        $profiles = NutritionProfile::query()->orderByDesc('is_admin')->orderBy('id')->get();

        $scores = $this->avgScores7d();
        $deltas = $this->weightDeltas30d();

        $rows = $profiles->map(fn (NutritionProfile $p) => [
            'profile' => $p,
            'day' => ProgramStatus::day($p),
            'avg_score' => $scores[$p->id] ?? null,
            'weight_delta' => $deltas[$p->id] ?? null,
        ]);

        return view('admin.nutrition.index', ['rows' => $rows]);
    }

    public function show(NutritionProfile $profile): View
    {
        return view('admin.nutrition.show', [
            'profile' => $profile,
            'day' => ProgramStatus::day($profile),
            'avgScore' => $this->avgScores7d($profile->id)[$profile->id] ?? null,
            'weightDelta' => $this->weightDeltas30d($profile->id)[$profile->id] ?? null,
            'recentMeals' => $this->recentMeals($profile),
            'weightSeries' => $this->weightSeries($profile),
            'settings' => array_merge(NutritionProfile::DEFAULT_SETTINGS, $profile->settings ?? []),
        ]);
    }

    /**
     * Пауза/возобновление профиля (toggle). Онбординг не трогаем — только
     * активный ↔ на паузе.
     */
    public function pause(NutritionProfile $profile): RedirectResponse
    {
        if ($profile->status === 'paused') {
            $profile->update(['status' => 'active']);

            return back()->with('status', 'Профиль возобновлён.');
        }

        if ($profile->status === 'active') {
            $profile->update(['status' => 'paused']);

            return back()->with('status', 'Профиль поставлен на паузу.');
        }

        return back()->with('status', 'Профиль на онбординге — пауза недоступна.');
    }

    /**
     * Правка настроек (wake/sleep/steps_target/portion_adjustment) и ai_profile.
     * Настройки пишутся в profile.settings; ai_profile — отдельная колонка.
     */
    public function update(Request $request, NutritionProfile $profile): RedirectResponse
    {
        $data = $request->validate([
            'wake_time' => ['required', 'date_format:H:i'],
            'sleep_time' => ['required', 'date_format:H:i'],
            'steps_target' => ['required', 'integer', 'between:3000,30000'],
            'portion_adjustment' => ['required', 'integer', 'between:-3,3'],
            'ai_profile' => ['nullable', 'string', 'max:20000'],
        ]);

        $settings = $profile->settings ?? [];
        $settings['wake_time'] = $data['wake_time'];
        $settings['sleep_time'] = $data['sleep_time'];
        $settings['steps_target'] = (int) $data['steps_target'];
        $settings['portion_adjustment'] = (int) $data['portion_adjustment'];

        $profile->update([
            'settings' => $settings,
            'ai_profile' => $data['ai_profile'] !== null && $data['ai_profile'] !== ''
                ? $data['ai_profile']
                : null,
        ]);

        return back()->with('status', 'Настройки сохранены.');
    }

    /**
     * Генерация инвайт-кода от имени admin-профиля (владельца инстанса).
     * Код показывается через flash.
     */
    public function invite(): RedirectResponse
    {
        $admin = NutritionProfile::admin();

        if ($admin === null) {
            return back()->withErrors(['invite' => 'Нет admin-профиля для создания инвайта.']);
        }

        $invite = NutritionInvite::generate($admin);

        return back()->with('status', 'Инвайт-код: '.$invite->code);
    }

    /**
     * Средний балл приёмов за последние 7 дней, ключ — profile_id.
     *
     * @return array<int, float>
     */
    private function avgScores7d(?int $profileId = null): array
    {
        $since = CarbonImmutable::now('Europe/Moscow')->subDays(6)->format('Y-m-d');

        return NutritionMeal::query()
            ->whereNotNull('score')
            ->whereDate('date', '>=', $since)
            ->when($profileId !== null, fn ($q) => $q->where('profile_id', $profileId))
            ->groupBy('profile_id')
            ->selectRaw('profile_id, round(avg(score), 1) as avg_score')
            ->pluck('avg_score', 'profile_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Изменение веса за 30 дней (последний − первый замер), ключ — profile_id.
     *
     * @return array<int, float>
     */
    private function weightDeltas30d(?int $profileId = null): array
    {
        $since = CarbonImmutable::now('Europe/Moscow')->subDays(30)->format('Y-m-d');

        $metrics = NutritionMetric::query()
            ->where('type', 'weight')
            ->whereDate('date', '>=', $since)
            ->when($profileId !== null, fn ($q) => $q->where('profile_id', $profileId))
            ->orderBy('date')
            ->get(['profile_id', 'date', 'value']);

        $deltas = [];
        foreach ($metrics->groupBy('profile_id') as $pid => $group) {
            if ($pid === null || $group->count() < 2) {
                continue;
            }
            $deltas[(int) $pid] = round((float) $group->last()->value - (float) $group->first()->value, 1);
        }

        return $deltas;
    }

    /**
     * Последние приёмы профиля (14 дней) для карточки.
     *
     * @return Collection<int, NutritionMeal>
     */
    private function recentMeals(NutritionProfile $profile)
    {
        $since = CarbonImmutable::now('Europe/Moscow')->subDays(13)->format('Y-m-d');

        return NutritionMeal::query()
            ->where('profile_id', $profile->id)
            ->whereDate('date', '>=', $since)
            ->orderByDesc('date')
            ->orderBy('window_start')
            ->get();
    }

    /**
     * Замеры веса профиля за 30 дней (по возрастанию даты).
     *
     * @return Collection<int, NutritionMetric>
     */
    private function weightSeries(NutritionProfile $profile)
    {
        $since = CarbonImmutable::now('Europe/Moscow')->subDays(30)->format('Y-m-d');

        return NutritionMetric::query()
            ->where('profile_id', $profile->id)
            ->where('type', 'weight')
            ->whereDate('date', '>=', $since)
            ->orderBy('date')
            ->get();
    }
}
