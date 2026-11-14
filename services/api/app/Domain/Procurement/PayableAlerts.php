<?php

namespace App\Domain\Procurement;

use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PayableAlerts
{
    public function policy(): ?array
    {
        $row = DB::table('business_settings')->where('key', 'payable_reminders')->first();

        return $row ? ['version' => (int) $row->version, ...json_decode($row->value, true)] : null;
    }

    public function savePolicy(array $data, User $actor): array
    {
        DB::table('business_settings')->insertOrIgnore([
            'key' => 'payable_reminders',
            'value' => '{}',
            'version' => 0,
            'updated_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = DB::table('business_settings')->where('key', 'payable_reminders')->lockForUpdate()->first();
        abort_unless(
            (int) $row->version === 0 ? empty($data['version']) : (int) $row->version === ($data['version'] ?? null),
            409,
        );
        $value = array_intersect_key(
            $data,
            array_flip(['enabled', 'due_soon_days', 'local_time', 'weekdays', 'repeat_after_days']),
        );
        $value['enabled'] = (bool) $value['enabled'];
        $value['due_soon_days'] = (int) $value['due_soon_days'];
        $value['repeat_after_days'] = (int) $value['repeat_after_days'];
        $value['weekdays'] = array_map('intval', $value['weekdays']);
        sort($value['weekdays']);
        $version = $row->version + 1;
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'payable_reminders'],
            [
                'value' => Records::json($value),
                'version' => $version,
                'updated_by' => $actor->id,
                'updated_at' => now(),
                'created_at' => $row?->created_at ?? now(),
            ],
        );
        Records::audit($actor, 'payable_reminders.policy_updated', 'payable_reminders', [
            'version' => $version,
            'policy' => $value,
            'reason' => $data['reason'],
        ]);

        return ['version' => $version, ...$value];
    }

    public function signals(string $supplierId, ?array $policy): array
    {
        $today = CarbonImmutable::now('Africa/Douala')->startOfDay();
        $until = $today->addDays($policy['due_soon_days'] ?? 0)->toDateString();
        $signals = [];
        $projection = app(PayableProjection::class);
        foreach (
            $projection->query($supplierId)->where('due_on', '<=', $until)->orderBy('due_on')->orderBy('id')->get() as $row
        ) {
            $remaining = $projection->remaining($row);
            if ($remaining < 1) {
                continue;
            }
            $severity =
                $row->due_on < $today->toDateString()
                    ? 'past_due'
                    : ($row->due_on === $today->toDateString()
                        ? 'due_today'
                        : 'due_soon');
            $signals['installment:'.$row->id] = [
                'kind' => 'supplier_installment',
                'severity' => $severity,
                'supplier_id' => $supplierId,
                'obligation_id' => $row->obligation_id,
                'installment_id' => $row->id,
                'contract_id' => $row->contract_id,
                'amount' => $remaining,
                'due_on' => $row->due_on,
                'context' => [
                    'schedule_version' => (int) $row->version,
                    'sequence' => (int) $row->sequence,
                    'purchase_order_id' => $row->purchase_order_id,
                ],
            ];
        }
        $commitment = null;
        foreach (
            DB::table('supply_contracts')
                ->where('supplier_id', $supplierId)
                ->where('status', 'accepted')
                ->where('starts_on', '<=', $today->toDateString())
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today->toDateString()))
                ->orderBy('id')
                ->get() as $contract
        ) {
            $terms = json_decode($contract->terms, true);
            if ($terms['mode'] !== 'credit' || $terms['credit_limit'] === null) {
                continue;
            }
            $commitment ??= app(Payables::class)->commitment($supplierId);
            $limit = Money::parse($terms['credit_limit']);
            if ($commitment <= $limit) {
                continue;
            }
            $signals['credit:'.$contract->id] = [
                'kind' => 'supplier_credit_limit',
                'severity' => 'credit_limit',
                'supplier_id' => $supplierId,
                'obligation_id' => null,
                'installment_id' => null,
                'contract_id' => $contract->id,
                'amount' => $commitment - $limit,
                'due_on' => null,
                'context' => [
                    'contract_version' => (int) $contract->version,
                    'limit' => (string) $limit,
                    'commitment' => (string) $commitment,
                ],
            ];
        }

        return $signals;
    }

    /** Call within a transaction. This method and all finance commands share the supplier lock. */
    public function syncSupplier(string $supplierId, bool $queueReminders = true): array
    {
        Records::supplier($supplierId);
        $policy = $this->policy();
        $signals = $this->signals($supplierId, $policy);
        $existing = DB::table('operational_alerts')
            ->where('supplier_id', $supplierId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('deduplication_key');
        $counts = ['active' => 0, 'resolved' => 0, 'queued' => 0];
        foreach ($signals as $key => $signal) {
            $old = $existing->get($key);
            $id = $old?->id ?? Records::id();
            $changed =
                ! $old ||
                $old->state !== 'active' ||
                $old->severity !== $signal['severity'] ||
                (int) $old->amount !== $signal['amount'] ||
                json_decode($old->context, true) !== $signal['context'];
            $values = [
                ...$signal,
                'context' => Records::json($signal['context']),
                'state' => 'active',
                'resolved_at' => null,
                'cycle' => $old ? $old->cycle + ($old->state === 'resolved' ? 1 : 0) : 1,
                'last_checked_at' => now(),
                'updated_at' => now(),
            ];
            if ($old && $old->state === 'resolved') {
                $values += ['last_queued_at' => null, 'last_queued_severity' => null];
            }
            if ($old) {
                DB::table('operational_alerts')->where('id', $id)->update($values);
            } else {
                DB::table('operational_alerts')->insert([
                    'id' => $id,
                    'deduplication_key' => $key,
                    'first_detected_at' => now(),
                    'created_at' => now(),
                    ...$values,
                ]);
            }
            if ($changed) {
                Records::audit(null, 'payable_alert.updated', $id, [
                    'severity' => $signal['severity'],
                    'amount' => (string) $signal['amount'],
                    'cycle' => $values['cycle'],
                ]);
            }
            $counts['active']++;
            if ($queueReminders && $this->queueReminder(Records::row('operational_alerts', $id), $policy)) {
                $counts['queued']++;
            }
        }
        foreach ($existing as $key => $old) {
            if ($old->state !== 'active' || isset($signals[$key])) {
                continue;
            }
            DB::table('operational_alerts')
                ->where('id', $old->id)
                ->update([
                    'state' => 'resolved',
                    'amount' => 0,
                    'resolved_at' => now(),
                    'last_checked_at' => now(),
                    'updated_at' => now(),
                ]);
            Records::audit(null, 'payable_alert.resolved', $old->id);
            $counts['resolved']++;
        }

        return $counts;
    }

    private function queueReminder(object $alert, ?array $policy): bool
    {
        if (! $policy || ! $policy['enabled']) {
            return false;
        }
        $local = CarbonImmutable::now('Africa/Douala');
        if (
            ! in_array($local->isoWeekday(), $policy['weekdays'], true) ||
            $local->format('H:i') < $policy['local_time']
        ) {
            return false;
        }
        $last = $alert->last_queued_at
            ? CarbonImmutable::parse($alert->last_queued_at, 'UTC')->setTimezone('Africa/Douala')
            : null;
        if ($last && $alert->last_queued_severity === $alert->severity && $alert->resolved_at === null) {
            if ($last->addDays($policy['repeat_after_days'])->toDateString() > $local->toDateString()) {
                return false;
            }
        }
        $key =
            'payable-reminder:'.
            $alert->id.
            ':'.
            $alert->cycle.
            ':'.
            $alert->severity.
            ':'.
            $local->toDateString();
        // A cycle/severity can be revisited in the same day; the event remains unique.
        if (
            DB::table('outbox_events')
                ->where('deduplication_key', 'SupplierPayableReminder:'.$key)
                ->exists()
        ) {
            return false;
        }
        Records::event('SupplierPayableReminder', $key, [
            'alert_id' => $alert->id,
            'supplier_id' => $alert->supplier_id,
            'cycle' => (int) $alert->cycle,
            'severity' => $alert->severity,
            'scheduled_on' => $local->toDateString(),
        ]);
        DB::table('operational_alerts')
            ->where('id', $alert->id)
            ->update(['last_queued_at' => now(), 'last_queued_severity' => $alert->severity]);

        return true;
    }

    public function dto(object $row): array
    {
        return [
            'id' => $row->id,
            'kind' => $row->kind,
            'severity' => $row->severity,
            'state' => $row->state,
            'supplier_id' => $row->supplier_id,
            'obligation_id' => $row->obligation_id,
            'installment_id' => $row->installment_id,
            'contract_id' => $row->contract_id,
            'amount' => (string) $row->amount,
            'due_on' => $row->due_on,
            'context' => json_decode($row->context, true),
            'cycle' => (int) $row->cycle,
            'first_detected_at' => Records::iso($row->first_detected_at),
            'last_checked_at' => Records::iso($row->last_checked_at),
            'resolved_at' => Records::iso($row->resolved_at),
            'last_queued_at' => Records::iso($row->last_queued_at),
        ];
    }
}
