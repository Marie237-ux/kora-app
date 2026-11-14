<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\DeliveryMissions;
use App\Domain\Delivery\MissionProofs;
use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DeliveryMissionsController extends OperationalController
{
    public function index(Request $http, DeliveryMissions $missions)
    {
        $d = $http->validate([
            'status' => [
                'nullable',
                'in:unassigned,assigned,accepted,in_transit,incident,awaiting_approval,delivered,cancelled',
            ],
            'courier_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return app(ReadSnapshot::class)->run(function () use ($http, $d, $missions) {
            $q = DB::table('delivery_missions')->orderBy('created_at')->orderBy('id');
            if (isset($d['status'])) {
                $q->where('status', $d['status']);
            }
            if (isset($d['courier_id'])) {
                $q->where('courier_id', $d['courier_id']);
            }

            return $this->page($q, $http, fn ($m) => $missions->dispatchView($m));
        });
    }

    public function show(string $id, DeliveryMissions $missions)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $missions->dispatchView(Records::row('delivery_missions', $id, false)),
            ),
        ];
    }

    /** A mission photo for the delivery team, who may not hold the general document permission. */
    public function photo(Request $http, string $id, string $photoId)
    {
        $photo = DB::table('delivery_mission_photos')->where('id', $photoId)->where('mission_id', $id)->first();
        abort_unless($photo, 404);

        return app(PrivateDocuments::class)->download(
            Records::row('private_documents', $photo->document_id, false),
            $http->user(),
        );
    }

    /** Public check of a courier from the QR code they show: minimal identity, or not found. */
    public function verify(Request $http, MissionProofs $proofs)
    {
        $d = $http->validate([
            'reference' => ['required', 'string', 'max:20'],
            'token' => ['required', 'string', 'size:32'],
        ]);
        $result = $proofs->verify($d['reference'], $d['token']);
        abort_unless($result, 404);

        return ['data' => $result];
    }

    public function create(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->command(
            $http,
            'delivery.mission_create:'.$id,
            $data,
            fn ($d) => $missions->create($id, $d, $http->user()),
            201,
        );
    }

    public function assign(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'courier_id' => ['required', 'integer', 'min:1'],
            'acceptance_required' => ['required', 'boolean'],
            'sequence' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'delivery.mission_assign:'.$id,
            $data,
            fn ($d) => $missions->assign($id, $d, $http->user()),
        );
    }

    public function cancel(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'delivery.mission_cancel:'.$id,
            $data,
            fn ($d) => $missions->cancel($id, $d, $http->user()),
        );
    }

    public function renewCode(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command(
            $http,
            'delivery.mission_code:'.$id,
            $data,
            fn ($d) => $missions->renewCode($id, $d, $http->user()),
        );
    }

    public function resolveIncident(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:retry,return_to_warehouse'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'delivery.mission_incident:'.$id,
            $data,
            fn ($d) => $missions->resolveIncident($id, $d, $http->user()),
        );
    }

    public function approveAlternative(Request $http, string $id, DeliveryMissions $missions)
    {
        return $this->review($http, $id, $missions, true);
    }

    public function rejectAlternative(Request $http, string $id, DeliveryMissions $missions)
    {
        return $this->review($http, $id, $missions, false);
    }

    private function review(Request $http, string $id, DeliveryMissions $missions, bool $approve)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'delivery.mission_alternative_'.($approve ? 'approve' : 'reject').':'.$id,
            $data,
            fn ($d) => $missions->reviewAlternative($id, $d, $http->user(), $approve),
        );
    }
}
