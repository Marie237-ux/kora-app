<?php

namespace App\Domain\Delivery;

use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Addresses are described by district and landmark; coordinates are optional and a map is never required. */
final class CustomerAddresses
{
    public const MAX_ACTIVE = 20;

    private const FIELDS = [
        'label',
        'district',
        'landmark',
        'instructions',
        'latitude',
        'longitude',
        'contact_name',
        'contact_phone',
        'alternate_name',
        'alternate_phone',
    ];

    public function owned(string $id, User $actor, bool $lock = false): object
    {
        $q = DB::table('customer_addresses')->where('id', $id)->where('user_id', $actor->id)->whereNull('archived_at');
        if ($lock) {
            $q->lockForUpdate();
        }
        $address = $q->first();
        abort_unless($address, 404);

        return $address;
    }

    public function create(array $data, User $actor): array
    {
        DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
        if (
            DB::table('customer_addresses')->where('user_id', $actor->id)->whereNull('archived_at')->count() >=
            self::MAX_ACTIVE
        ) {
            DeliveryZones::fail('addresses', 'ADDRESS_LIMIT');
        }
        $id = Records::id();
        DB::table('customer_addresses')->insert([
            ...$this->values($data),
            'id' => $id,
            'user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->dto(Records::row('customer_addresses', $id, false));
    }

    public function update(object $address, array $data): array
    {
        abort_unless((int) $address->version === $data['version'], 409);
        DB::table('customer_addresses')
            ->where('id', $address->id)
            ->update([...$this->values($data), 'version' => $address->version + 1, 'updated_at' => now()]);

        return $this->dto(Records::row('customer_addresses', $address->id, false));
    }

    /** Archived rather than deleted: past quotes and orders keep their exact delivery address. */
    public function archive(object $address, int $version): array
    {
        abort_unless((int) $address->version === $version, 409);
        DB::table('customer_addresses')
            ->where('id', $address->id)
            ->update(['archived_at' => now(), 'version' => $address->version + 1, 'updated_at' => now()]);

        return ['id' => $address->id, 'archived' => true];
    }

    public function values(array $data): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = $data[$field] ?? null;
        }
        $values['instructions'] ??= '';

        return $values;
    }

    public function snapshot(object $address): array
    {
        return array_intersect_key((array) $address, array_flip(['id', ...self::FIELDS]));
    }

    public function dto(object $address): array
    {
        $zone = app(DeliveryZones::class)->resolve($address->district);

        return [
            'id' => $address->id,
            'label' => $address->label,
            'district' => $address->district,
            'landmark' => $address->landmark,
            'instructions' => $address->instructions,
            'latitude' => $address->latitude === null ? null : (float) $address->latitude,
            'longitude' => $address->longitude === null ? null : (float) $address->longitude,
            'contact_name' => $address->contact_name,
            'contact_phone' => $address->contact_phone,
            'alternate_name' => $address->alternate_name,
            'alternate_phone' => $address->alternate_phone,
            'served' => $zone !== null,
            'zone' => $zone ? app(DeliveryZones::class)->dto($zone, true) : null,
            'version' => (int) $address->version,
            'updated_at' => Records::iso($address->updated_at),
        ];
    }
}
