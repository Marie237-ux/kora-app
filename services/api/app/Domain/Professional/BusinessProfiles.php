<?php

namespace App\Domain\Professional;

use App\Domain\Delivery\CustomerAddresses;
use App\Domain\Delivery\DeliveryZones;
use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Company membership never grants a KORA staff role, payment credit or access to another company. */
final class BusinessProfiles
{
    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('professional.'.$code)]);
    }

    public function authorized(
        string $id,
        User $actor,
        bool $write = false,
        bool $owner = false,
        bool $lock = false,
    ): object {
        $profile = Records::row('professional_profiles', $id, $lock);
        $member = DB::table('professional_members')->where('profile_id', $id)->where('user_id', $actor->id)->first();
        abort_unless($member, 404);
        abort_if(($write && $member->role === 'viewer') || ($owner && $member->role !== 'owner'), 403);

        return $profile;
    }

    public function create(array $identity, User $actor): array
    {
        $id = Records::id();
        DB::table('professional_profiles')->insert([
            'id' => $id,
            'billing_identity' => Records::json($identity),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('professional_members')->insert([
            'profile_id' => $id,
            'user_id' => $actor->id,
            'role' => 'owner',
            'created_at' => now(),
        ]);
        Records::audit($actor, 'professional.profile_created', $id);

        return $this->dto(Records::row('professional_profiles', $id, false));
    }

    public function update(object $profile, array $identity, int $version, User $actor): array
    {
        abort_unless((int) $profile->version === $version, 409);
        DB::table('professional_profiles')
            ->where('id', $profile->id)
            ->update([
                'billing_identity' => Records::json($identity),
                'version' => $version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'professional.profile_updated', $profile->id);

        return $this->dto(Records::row('professional_profiles', $profile->id, false));
    }

    public function member(object $profile, string $email, string $role, int $version, User $actor): array
    {
        abort_unless((int) $profile->version === $version, 409);
        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->whereNotNull('email_verified_at')
            ->first();
        if (! $user) {
            self::fail('email', 'VERIFIED_CONTACT_REQUIRED');
        }
        $existing = DB::table('professional_members')
            ->where('profile_id', $profile->id)
            ->where('user_id', $user->id)
            ->first();
        if ($existing?->role === 'owner') {
            self::fail('member', 'OWNER_PROTECTED');
        }
        DB::table('professional_members')->updateOrInsert(
            ['profile_id' => $profile->id, 'user_id' => $user->id],
            [
                'role' => $role,
                'created_at' => $existing?->created_at ?? now(),
            ],
        );
        $this->bump($profile);
        Records::audit($actor, 'professional.member_authorized', $profile->id, [
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $this->dto(Records::row('professional_profiles', $profile->id, false));
    }

    public function removeMember(object $profile, int $userId, int $version, User $actor): array
    {
        abort_unless((int) $profile->version === $version, 409);
        $member = DB::table('professional_members')
            ->where('profile_id', $profile->id)
            ->where('user_id', $userId)
            ->first();
        abort_unless($member, 404);
        if ($member->role === 'owner') {
            self::fail('member', 'OWNER_PROTECTED');
        }
        DB::table('professional_members')->where('profile_id', $profile->id)->where('user_id', $userId)->delete();
        $this->bump($profile);
        Records::audit($actor, 'professional.member_revoked', $profile->id, ['user_id' => $userId]);

        return $this->dto(Records::row('professional_profiles', $profile->id, false));
    }

    private function bump(object $profile): void
    {
        DB::table('professional_profiles')
            ->where('id', $profile->id)
            ->update(['version' => $profile->version + 1, 'updated_at' => now()]);
    }

    public function address(object $profile, string $id): object
    {
        $row = DB::table('professional_addresses')
            ->where('profile_id', $profile->id)
            ->where('id', $id)
            ->whereNull('archived_at')
            ->first();
        abort_unless($row, 404);

        return $row;
    }

    public function saveAddress(object $profile, ?object $address, array $data, User $actor): array
    {
        if ($address) {
            abort_unless((int) $address->version === $data['version'], 409);
        } elseif (
            DB::table('professional_addresses')->where('profile_id', $profile->id)->whereNull('archived_at')->count() >=
            CustomerAddresses::MAX_ACTIVE
        ) {
            self::fail('address', 'ADDRESS_LIMIT');
        }
        $id = $address?->id ?? Records::id();
        $values = app(CustomerAddresses::class)->values($data);
        foreach (['latitude', 'longitude'] as $coordinate) {
            $values[$coordinate] = $values[$coordinate] === null ? null : (float) $values[$coordinate];
        }
        DB::table('professional_addresses')->updateOrInsert(
            ['id' => $id],
            [
                'profile_id' => $profile->id,
                'address' => Records::json($values),
                'version' => $address ? $address->version + 1 : 1,
                'created_at' => $address?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
        Records::audit($actor, 'professional.address_saved', $id);

        return $this->addressDto(Records::row('professional_addresses', $id, false));
    }

    public function addressDto(object $row): array
    {
        $address = json_decode($row->address, true);

        return [
            'id' => $row->id,
            'version' => (int) $row->version,
            'address' => $address,
            'served' => app(DeliveryZones::class)->resolve($address['district']) !== null,
        ];
    }

    public function dto(object $profile): array
    {
        return [
            'id' => $profile->id,
            'version' => (int) $profile->version,
            'billing_identity' => json_decode($profile->billing_identity, true),
            'identity_verified' => false,
            'members' => DB::table('professional_members AS m')
                ->join('users AS u', 'u.id', '=', 'm.user_id')
                ->where('m.profile_id', $profile->id)
                ->orderBy('u.id')
                ->get(['u.id', 'u.name', 'u.email', 'm.role']),
            'addresses' => DB::table('professional_addresses')
                ->where('profile_id', $profile->id)
                ->whereNull('archived_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => $this->addressDto($row)),
        ];
    }
}
