<?php

namespace App\Domain\Delivery;

use App\Domain\Procurement\Records;
use App\Domain\Search\SearchText;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeliveryZones
{
    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('delivery.'.$code)]);
    }

    /** Accents, case and punctuation are ignored: "Bonapriso", "bonaprisó" and "BONAPRISO " are one district. */
    public function normalize(string $district): string
    {
        return implode(' ', app(SearchText::class)->tokens($district));
    }

    public function resolve(string $district): ?object
    {
        $wanted = $this->normalize($district);
        if ($wanted === '') {
            return null;
        }
        foreach (DB::table('delivery_zones')->where('active', true)->orderBy('code')->get() as $zone) {
            foreach (json_decode($zone->districts, true) as $name) {
                if ($this->normalize($name) === $wanted) {
                    return $zone;
                }
            }
        }

        return null;
    }

    public function save(?object $zone, array $data, User $actor): array
    {
        $districts = [];
        foreach ($data['districts'] as $name) {
            $key = $this->normalize($name);
            if ($key === '' || isset($districts[$key])) {
                self::fail('districts', 'DISTRICT_DUPLICATE');
            }
            $districts[$key] = trim($name);
        }
        if ($data['active']) {
            $this->assertDistrictsFree(array_keys($districts), $zone?->id);
        }
        $values = [
            'code' => $data['code'],
            'name' => Records::json($data['name']),
            'districts' => Records::json(array_values($districts)),
            'active' => $data['active'],
            'updated_at' => now(),
        ];
        if ($zone) {
            DB::table('delivery_zones')
                ->where('id', $zone->id)
                ->update([...$values, 'version' => $zone->version + 1]);
            $id = $zone->id;
        } else {
            $id = Records::id();
            DB::table('delivery_zones')->insert([...$values, 'id' => $id, 'created_at' => now()]);
        }
        Records::audit($actor, $zone ? 'delivery.zone_updated' : 'delivery.zone_created', $id, [
            'code' => $data['code'],
        ]);

        return $this->dto(Records::row('delivery_zones', $id, false));
    }

    /** A district may belong to a single active zone, otherwise the zone of an address would be ambiguous. */
    private function assertDistrictsFree(array $keys, ?string $exceptId): void
    {
        $others = DB::table('delivery_zones')
            ->where('active', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->lockForUpdate()
            ->get();
        foreach ($others as $other) {
            foreach (json_decode($other->districts, true) as $name) {
                if (in_array($this->normalize($name), $keys, true)) {
                    self::fail('districts', 'DISTRICT_IN_OTHER_ZONE');
                }
            }
        }
    }

    public function dto(object $zone, bool $public = false): array
    {
        $locale = app()->getLocale();
        $name = json_decode($zone->name, true);
        $base = [
            'id' => $zone->id,
            'name' => $name[$locale] ?? $name['fr'],
            'districts' => json_decode($zone->districts, true),
        ];

        return $public
            ? $base
            : [
                ...$base,
                'code' => $zone->code,
                'name' => $name,
                'active' => (bool) $zone->active,
                'version' => (int) $zone->version,
                'updated_at' => Records::iso($zone->updated_at),
            ];
    }
}
