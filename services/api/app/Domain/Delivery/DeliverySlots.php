<?php

namespace App\Domain\Delivery;

use App\Domain\Procurement\Records;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Slots are weekly rules expanded per date in Africa/Douala. A place is taken by a quote that holds the slot
 * until the quote expires; once the order exists the hold becomes confirmed. Expired holds stop counting by
 * themselves, so no clean-up job is needed to give the place back.
 */
final class DeliverySlots
{
    public function timezone(): string
    {
        return (string) config('kora.business_timezone', 'Africa/Douala');
    }

    /** Bookable slots for a zone over the next days, given the readiness delay of the parcel. */
    public function available(
        string $zoneId,
        int $readyAfterMinutes,
        ?string $ignoreQuoteId = null,
        bool $includeFull = false,
    ): array {
        $tz = $this->timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $days = max(1, min(31, (int) config('kora.delivery_slot_days', 7)));
        $rules = DB::table('delivery_slot_rules')
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('zone_id')->orWhere('zone_id', $zoneId))
            ->orderBy('weekday')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
        $slots = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $today->addDays($i);
            foreach ($rules->where('weekday', $date->dayOfWeekIso) as $rule) {
                $slot = $this->occurrence($rule, $date->toDateString(), $readyAfterMinutes, $ignoreQuoteId);
                if ($slot && ($includeFull || $slot['remaining'] > 0)) {
                    $slots[] = $slot;
                }
            }
        }

        return $slots;
    }

    /** Null when the slot is closed, past its cut-off or starts before the parcel can be ready. */
    public function occurrence(
        object $rule,
        string $date,
        int $readyAfterMinutes,
        ?string $ignoreQuoteId = null,
    ): ?array {
        $tz = $this->timezone();
        $start = CarbonImmutable::parse($date.' '.$rule->starts_at, $tz);
        if ($start->dayOfWeekIso !== (int) $rule->weekday) {
            return null;
        }
        $lead = max((int) $rule->cutoff_minutes, $readyAfterMinutes);
        if (CarbonImmutable::now($tz)->addMinutes($lead)->greaterThan($start)) {
            return null;
        }
        $capacity = $this->capacity($rule, $date);
        $remaining = max(0, $capacity - $this->used($rule->id, $date, $ignoreQuoteId));

        return [
            'rule_id' => $rule->id,
            'date' => $date,
            'starts_at' => $rule->starts_at,
            'ends_at' => $rule->ends_at,
            'starts_at_utc' => $start->utc()->toIso8601String(),
            'capacity' => $capacity,
            'remaining' => $remaining,
        ];
    }

    private function capacity(object $rule, string $date): int
    {
        $closures = DB::table('delivery_slot_closures')
            ->where('date', $date)
            ->where(fn ($q) => $q->whereNull('rule_id')->orWhere('rule_id', $rule->id))
            ->pluck('capacity');

        // The most restrictive closure wins; a closure never raises the configured capacity.
        return (int) min([(int) $rule->capacity, ...$closures->map(fn ($c) => (int) $c)->all()]);
    }

    public function used(string $ruleId, string $date, ?string $ignoreQuoteId = null): int
    {
        return DB::table('delivery_quotes')
            ->where('slot_rule_id', $ruleId)
            ->where('slot_date', $date)
            ->when($ignoreQuoteId, fn ($q) => $q->where('id', '!=', $ignoreQuoteId))
            ->where(
                fn ($q) => $q
                    ->where('slot_status', 'confirmed')
                    ->orWhere(fn ($h) => $h->where('slot_status', 'held')->where('expires_at', '>', now())),
            )
            ->count();
    }

    /**
     * Takes a place for the quote under a per-occurrence lock. The quote row must already be locked by the
     * caller; locks are always taken quote first, then slot, so concurrent holds cannot deadlock.
     */
    public function hold(object $quote, string $ruleId, string $date): void
    {
        $rule = DB::table('delivery_slot_rules')->where('id', $ruleId)->where('active', true)->first();
        if (! $rule || ($rule->zone_id !== null && $rule->zone_id !== $quote->zone_id)) {
            DeliveryZones::fail('rule_id', 'SLOT_UNAVAILABLE');
        }
        DB::table('delivery_slot_locks')->insertOrIgnore([
            'rule_id' => $ruleId,
            'date' => $date,
            'created_at' => now(),
        ]);
        DB::table('delivery_slot_locks')->where('rule_id', $ruleId)->where('date', $date)->lockForUpdate()->first();
        $slot = $this->occurrence($rule, $date, (int) $quote->ready_after_minutes, $quote->id);
        if (! $slot || $slot['remaining'] < 1) {
            DeliveryZones::fail('rule_id', 'SLOT_UNAVAILABLE');
        }
        DB::table('delivery_quotes')
            ->where('id', $quote->id)
            ->update([
                'slot_rule_id' => $ruleId,
                'slot_date' => $date,
                'slot_status' => 'held',
                'version' => $quote->version + 1,
                'updated_at' => now(),
            ]);
    }

    public function ruleDto(object $rule): array
    {
        return [
            'id' => $rule->id,
            'zone_id' => $rule->zone_id,
            'weekday' => (int) $rule->weekday,
            'starts_at' => $rule->starts_at,
            'ends_at' => $rule->ends_at,
            'capacity' => (int) $rule->capacity,
            'cutoff_minutes' => (int) $rule->cutoff_minutes,
            'active' => (bool) $rule->active,
            'version' => (int) $rule->version,
        ];
    }

    public function closureDto(object $closure): array
    {
        return [
            'id' => $closure->id,
            'date' => $closure->date,
            'rule_id' => $closure->rule_id,
            'capacity' => (int) $closure->capacity,
            'reason' => $closure->reason,
            'created_at' => Records::iso($closure->created_at),
        ];
    }
}
