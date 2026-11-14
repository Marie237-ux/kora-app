<?php

namespace App\Domain\Delivery;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Evidence around a mission: optional photos taken by the courier, and the courier's identity check by the
 * customer. The check uses a token bound to the mission, the courier and the current assignment, so it stops
 * working by itself on reassignment, delivery, cancellation or suspension; it reveals only the public name,
 * the badge and the mission reference.
 */
final class MissionProofs
{
    public const KINDS = ['pickup', 'handover', 'damage', 'incident', 'other'];

    private const LIVE = ['assigned', 'accepted', 'in_transit', 'incident', 'awaiting_approval'];

    public function addPhoto(string $missionId, UploadedFile $file, array $data, User $courier): array
    {
        // Checked before storing the file, then again under lock before linking it.
        $this->assertLive(Records::row('delivery_missions', $missionId, false), $courier);
        $document = app(PrivateDocuments::class)->upload($file, 'delivery_evidence', null, $courier, true);

        return DB::transaction(function () use ($missionId, $document, $data, $courier) {
            $initial = Records::row('delivery_missions', $missionId, false);
            Records::row('orders', $initial->order_id);
            $mission = Records::row('delivery_missions', $missionId);
            $this->assertLive($mission, $courier);
            $id = Records::id();
            DB::table('delivery_mission_photos')->insert([
                'id' => $id,
                'mission_id' => $mission->id,
                'document_id' => $document['id'],
                'kind' => $data['kind'],
                'note' => $data['note'] ?? null,
                'taken_by' => $courier->id,
                'created_at' => now(),
            ]);
            DB::table('delivery_mission_events')->insert([
                'mission_id' => $mission->id,
                'action' => 'photo_added',
                'actor_id' => $courier->id,
                'data' => Records::json(['photo_id' => $id, 'kind' => $data['kind']]),
                'created_at' => now(),
            ]);
            Records::audit($courier, 'delivery.photo_added', $mission->id, [
                'photo_id' => $id,
                'kind' => $data['kind'],
            ]);

            return $this->photoDto(Records::row('delivery_mission_photos', $id, false));
        }, 3);
    }

    private function assertLive(object $mission, User $courier): void
    {
        abort_unless((int) $mission->courier_id === $courier->id, 404);
        if (! in_array($mission->status, self::LIVE, true)) {
            DeliveryMissions::fail('mission', 'MISSION_STATE');
        }
    }

    public function photos(string $missionId): array
    {
        return DB::table('delivery_mission_photos')
            ->where('mission_id', $missionId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => $this->photoDto($p))
            ->all();
    }

    public function photoDto(object $p): array
    {
        return [
            'id' => $p->id,
            'kind' => $p->kind,
            'note' => $p->note,
            'document_id' => $p->document_id,
            'taken_by' => (int) $p->taken_by,
            'created_at' => Records::iso($p->created_at),
        ];
    }

    private function token(object $mission): string
    {
        return substr(
            hash_hmac(
                'sha256',
                'courier-verification|'.$mission->id.'|'.$mission->courier_id.'|'.$mission->assignment,
                (string) config('app.key'),
            ),
            0,
            32,
        );
    }

    /** What the courier shows as a QR code, only while the mission is live, theirs and their profile active. */
    public function verification(object $mission): ?array
    {
        if (! $this->checkable($mission)) {
            return null;
        }
        $query = http_build_query(['reference' => $mission->number, 'token' => $this->token($mission)]);

        return [
            'reference' => $mission->number,
            'token' => $this->token($mission),
            'url' => rtrim((string) config('kora.storefront_url'), '/').'/fr/verifier-livreur?'.$query,
        ];
    }

    private function checkable(object $mission): bool
    {
        return in_array($mission->status, self::LIVE, true) &&
            $mission->courier_id !== null &&
            (bool) DB::table('courier_profiles')->where('user_id', $mission->courier_id)->value('active');
    }

    /** Public check from the QR code: the courier's public identity for this mission, or nothing at all. */
    public function verify(string $reference, string $token): ?array
    {
        $mission = DB::table('delivery_missions')->where('number', $reference)->first();
        if (! $mission || ! $this->checkable($mission) || ! hash_equals($this->token($mission), $token)) {
            return null;
        }
        $identity = app(Couriers::class)->publicIdentity((int) $mission->courier_id);

        return [
            'valid' => true,
            'mission_reference' => $mission->number,
            'public_name' => $identity['public_name'],
            'badge' => $identity['badge'],
        ];
    }
}
