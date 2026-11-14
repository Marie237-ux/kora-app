<?php

namespace App\Domain\Delivery;

use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Professional identity of couriers: a public name and a badge the customer can check against the mission.
 * Identity papers stay outside the API. Only accounts holding the courier permission can be registered.
 */
final class Couriers
{
    public function register(array $data, User $actor): array
    {
        $user = User::query()->lockForUpdate()->findOrFail($data['user_id']);
        if (! in_array('missions.own', $user->permissions(), true)) {
            DeliveryMissions::fail('user_id', 'COURIER_ROLE_REQUIRED');
        }
        if (DB::table('courier_profiles')->where('user_id', $user->id)->exists()) {
            DeliveryMissions::fail('user_id', 'COURIER_EXISTS');
        }
        DB::table('courier_profiles')->insert([
            'user_id' => $user->id,
            'public_name' => trim($data['public_name']),
            'badge' => strtoupper(trim($data['badge'])),
            'active' => true,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($actor, 'courier.registered', (string) $user->id, ['badge' => strtoupper(trim($data['badge']))]);

        return $this->dto($this->row($user->id, false));
    }

    public function update(int $userId, array $data, User $actor): array
    {
        $profile = $this->row($userId);
        abort_unless((int) $profile->version === $data['version'], 409);
        DB::table('courier_profiles')
            ->where('user_id', $userId)
            ->update([
                'public_name' => trim($data['public_name']),
                'active' => $data['active'],
                'version' => $profile->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'courier.updated', (string) $userId, [
            'public_name' => trim($data['public_name']),
            'active' => $data['active'],
        ]);

        return $this->dto($this->row($userId, false));
    }

    public function row(int $userId, bool $lock = true): object
    {
        $query = DB::table('courier_profiles')->where('user_id', $userId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        abort_unless($row, 404);

        return $row;
    }

    /** A courier can receive missions only while active and still holding the courier permission. */
    public function assignable(int $userId): object
    {
        $profile = DB::table('courier_profiles')->where('user_id', $userId)->lockForUpdate()->first();
        $user = User::query()->find($userId);
        if (! $profile || ! $profile->active || ! $user || ! in_array('missions.own', $user->permissions(), true)) {
            DeliveryMissions::fail('courier_id', 'COURIER_NOT_ASSIGNABLE');
        }

        return $profile;
    }

    /** What the customer may see: no e-mail, phone or identity document. */
    public function publicIdentity(?int $userId): ?array
    {
        $profile = $userId ? DB::table('courier_profiles')->where('user_id', $userId)->first() : null;

        return $profile ? ['public_name' => $profile->public_name, 'badge' => $profile->badge] : null;
    }

    public function dto(object $profile): array
    {
        return [
            'user_id' => (int) $profile->user_id,
            'public_name' => $profile->public_name,
            'badge' => $profile->badge,
            'active' => (bool) $profile->active,
            'version' => (int) $profile->version,
            'active_missions' => DB::table('delivery_missions')
                ->where('courier_id', $profile->user_id)
                ->whereNotIn('status', ['delivered', 'cancelled'])
                ->count(),
        ];
    }
}
