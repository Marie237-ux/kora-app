<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\PayableAlerts;
use App\Domain\Procurement\PayableForecast;
use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Domain\Shared\ReadSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PayableMonitoringController extends OperationalController
{
    public function policy(PayableAlerts $alerts)
    {
        return ['data' => $alerts->policy()];
    }

    public function savePolicy(Request $request, PayableAlerts $alerts)
    {
        $data = $request->validate([
            'version' => ['nullable', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
            'due_soon_days' => ['required', 'integer', 'min:0', 'max:365'],
            'local_time' => ['required', 'date_format:H:i'],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'min:1', 'max:7', 'distinct'],
            'repeat_after_days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $request,
            'payable_reminders.policy',
            $data,
            fn ($d) => $alerts->savePolicy($d, $request->user()),
        );
    }

    public function index(Request $request, PayableAlerts $alerts)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'uuid'],
            'contract_id' => ['nullable', 'uuid'],
            'state' => ['nullable', 'in:active,resolved'],
            'severity' => ['nullable', 'in:due_soon,due_today,past_due,credit_limit'],
        ]);
        $q = DB::table('operational_alerts')
            ->where('state', $data['state'] ?? 'active')
            ->orderByDesc('updated_at')
            ->orderBy('id');
        foreach (['supplier_id', 'contract_id', 'severity'] as $f) {
            if (! empty($data[$f])) {
                $q->where($f, $data[$f]);
            }
        }

        return [
            ...$this->page($q, $request, fn ($r) => $alerts->dto($r)),
            'reminder_policy_configured' => $alerts->policy() !== null,
        ];
    }

    public function show(string $id, PayableAlerts $alerts)
    {
        return ['data' => $alerts->dto(Records::row('operational_alerts', $id, false))];
    }

    public function forecast(Request $request, PayableForecast $forecast, ReadSnapshot $snapshot)
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'supplier_id' => ['nullable', 'uuid', 'exists:suppliers,id'],
        ]);
        if (CarbonImmutable::parse($data['from'], 'Africa/Douala')->addDays(366)->toDateString() < $data['to']) {
            Money::fail('to', 'FORECAST_RANGE_INVALID');
        }

        return [
            'data' => $snapshot->run(
                fn () => $forecast->calculate($data['from'], $data['to'], $data['supplier_id'] ?? null),
            ),
        ];
    }
}
