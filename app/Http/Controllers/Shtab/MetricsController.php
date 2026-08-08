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

        DB::transaction(function () use ($metric, $data): void {
            $from = $metric->status;
            $metric->update([
                'status' => $data['status'],
                'value_text' => $data['value_text'] ?? $metric->value_text,
            ]);

            ShtabEvent::record('metric_status_changed', [
                'metric_id' => $metric->id,
                'object_id' => $metric->object_id,
                'payload' => [
                    'from' => $from,
                    'to' => $data['status'],
                    'value_text' => $metric->refresh()->value_text,
                ],
                'comment' => $data['comment'] ?? null,
            ]);
        });

        return redirect()->back();
    }
}
