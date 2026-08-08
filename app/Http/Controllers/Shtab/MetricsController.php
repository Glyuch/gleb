<?php

namespace App\Http\Controllers\Shtab;

use App\Http\Controllers\Controller;
use App\Models\ShtabEvent;
use App\Models\ShtabMetric;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MetricsController extends Controller
{
    public function status(Request $request, ShtabMetric $metric): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['green', 'yellow', 'red'])],
            'value_text' => ['nullable', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $newValueText = array_key_exists('value_text', $data) ? $data['value_text'] : $metric->value_text;

        if ($metric->status === $data['status'] && $newValueText === $metric->value_text) {
            return redirect()->back();
        }

        DB::transaction(function () use ($metric, $data, $newValueText): void {
            $from = $metric->status;
            $metric->update([
                'status' => $data['status'],
                'value_text' => $newValueText,
            ]);

            ShtabEvent::record('metric_status_changed', [
                'metric_id' => $metric->id,
                'object_id' => $metric->object_id,
                'payload' => [
                    'from' => $from,
                    'to' => $data['status'],
                    'value_text' => $newValueText,
                ],
                'comment' => $data['comment'] ?? null,
            ]);
        });

        return redirect()->back();
    }

    public function store(Request $request): RedirectResponse
    {
        ShtabMetric::query()->create($this->validated($request));

        return redirect()->back();
    }

    public function update(Request $request, ShtabMetric $metric): RedirectResponse
    {
        $metric->update($this->validated($request));

        return redirect()->back();
    }

    public function destroy(ShtabMetric $metric): RedirectResponse
    {
        $metric->delete();

        return redirect()->back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'object_id' => ['nullable', 'integer', 'exists:shtab_objects,id'],
            'name' => ['required', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['green', 'yellow', 'red'])],
            'value_text' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
