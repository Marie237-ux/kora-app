<?php

namespace App\Domain\Notifications;

use App\Domain\Procurement\PayableAlerts;
use App\Domain\Procurement\Records;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PayableReminderHandler
{
    public const TYPE = 'SupplierPayableReminder';

    public const NAME = 'internal.payable-reminder';

    public function handle(object $event): string
    {
        $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'alert_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'uuid'],
            'cycle' => ['required', 'integer', 'min:1'],
            'severity' => ['required', 'in:due_soon,due_today,past_due,credit_limit'],
            'scheduled_on' => ['required', 'date_format:Y-m-d'],
        ]);
        if ($validator->fails()) {
            throw new \InvalidArgumentException('INVALID_PAYLOAD');
        }
        $p = $validator->validated();
        $initial = DB::table('operational_alerts')->where('id', $p['alert_id'])->first();
        if (! $initial || $initial->supplier_id !== $p['supplier_id']) {
            throw new \InvalidArgumentException('INVALID_PAYLOAD');
        }
        // Re-evaluate under the same supplier lock as settlements before delivery.
        app(PayableAlerts::class)->syncSupplier($initial->supplier_id, false);
        $alert = Records::row('operational_alerts', $p['alert_id']);
        $policy = app(PayableAlerts::class)->policy();
        if (
            ! $policy ||
            ! $policy['enabled'] ||
            $alert->state !== 'active' ||
            (int) $alert->cycle !== $p['cycle'] ||
            $alert->severity !== $p['severity']
        ) {
            return 'suppressed';
        }
        DB::table('operational_notifications')->insertOrIgnore([
            'id' => Records::id(),
            'event_id' => $event->id,
            'alert_id' => $alert->id,
            'audience_permission' => 'purchases.read',
            'kind' => 'payable_reminder',
            'created_at' => now(),
        ]);

        return 'delivered';
    }
}
