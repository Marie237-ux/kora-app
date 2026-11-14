<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\DeliveryMissions;
use App\Domain\Delivery\MissionProofs;
use App\Domain\Delivery\SupplierCollections;
use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The courier's own missions only; the route middleware rechecks the assignment before any replay. */
final class CourierMissionsController extends OperationalController
{
    public function index(Request $http, DeliveryMissions $missions)
    {
        $d = $http->validate(['scope' => ['nullable', 'in:current,history']]);
        $courier = $http->user();

        return app(ReadSnapshot::class)->run(function () use ($http, $d, $missions, $courier) {
            $q = DB::table('delivery_missions')->where('courier_id', $courier->id);
            if (($d['scope'] ?? 'current') === 'history') {
                $q->whereIn('status', ['delivered', 'cancelled'])->orderByDesc('updated_at');
            } else {
                $q->whereNotIn('status', ['delivered', 'cancelled'])
                    ->orderByRaw('sequence IS NULL')
                    ->orderBy('sequence');
            }
            $q->orderBy('id');

            return $this->page($q, $http, fn ($m) => $missions->courierView($m, $courier));
        });
    }

    public function show(Request $http, string $id, DeliveryMissions $missions)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $missions->courierView(Records::row('delivery_missions', $id, false), $http->user()),
            ),
        ];
    }

    public function accept(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command(
            $http,
            'courier.accept:'.$id,
            $data,
            fn ($d) => $missions->accept($id, $d, $http->user()),
        );
    }

    public function decline(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'courier.decline:'.$id,
            $data,
            fn ($d) => $missions->decline($id, $d, $http->user()),
        );
    }

    public function pickup(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'packages' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->command(
            $http,
            'courier.pickup:'.$id,
            $data,
            fn ($d) => $missions->pickup($id, $d, $http->user()),
        );
    }

    public function incident(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:'.implode(',', DeliveryMissions::INCIDENTS)],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'courier.incident:'.$id,
            $data,
            fn ($d) => $missions->reportIncident($id, $d, $http->user()),
            201,
        );
    }

    public function handover(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            ...$this->controlRules(),
        ]);
        $response = $this->command(
            $http,
            'courier.handover:'.$id,
            $data,
            fn ($d) => $missions->handover($id, $d, $http->user()),
        );
        $result = $response->getData(true)['data'];
        if (! isset($result['rejected'])) {
            return $response;
        }

        // The failed attempt is committed and counted; the response still says the handover did not happen.
        return response()->json(
            [
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => __('api.VALIDATION_FAILED'),
                    'fields' => ['code' => [__('missions.'.$result['rejected'])]],
                    'request_id' => $http->attributes->get('request_id'),
                ],
                'data' => ['attempts_left' => $result['attempts_left'], 'mission' => $result['mission']],
            ],
            422,
        );
    }

    public function alternative(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'in:'.implode(',', DeliveryMissions::ALTERNATIVES)],
            'note' => ['required', 'string', 'max:2000'],
            ...$this->controlRules(),
        ]);

        return $this->command(
            $http,
            'courier.alternative:'.$id,
            $data,
            fn ($d) => $missions->requestAlternative($id, $d, $http->user()),
            201,
        );
    }

    /** Optional photo during the mission: parcels at pickup or handover, damage, incident. */
    public function photo(Request $http, string $id)
    {
        $data = $http->validate([
            'kind' => ['required', 'in:'.implode(',', MissionProofs::KINDS)],
            'note' => ['nullable', 'string', 'max:1000'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        return response()->json(
            ['data' => app(MissionProofs::class)->addPhoto($id, $data['file'], $data, $http->user())],
            201,
        );
    }

    /** Photo of the shop's slip, taken by the courier for a direct collection of their own mission. */
    public function shopPhoto(Request $http, string $id)
    {
        $data = $http->validate([
            'collection_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:10240'],
        ]);
        $collection = $this->directCollection($id, $data['collection_id']);

        return response()->json(
            [
                'data' => app(PrivateDocuments::class)->upload(
                    $data['file'],
                    'stock_evidence',
                    DB::table('purchase_orders')->where('id', $collection->purchase_order_id)->value('supplier_id'),
                    $http->user(),
                    true,
                ),
            ],
            201,
        );
    }

    /** Receipt at the shop by the courier: units received, issued to the order and added to the load. */
    public function shopReceipt(Request $http, string $id, DeliveryMissions $missions)
    {
        $data = $http->validate([
            'collection_id' => ['required', 'uuid'],
            ...SupplierCollectionsController::directReceiptRules(),
        ]);
        $this->directCollection($id, $data['collection_id']);

        return $this->command($http, 'courier.shop_receipt:'.$data['collection_id'], $data, function ($d) use (
            $id,
            $http,
            $missions,
        ) {
            app(SupplierCollections::class)->receiveDirect($d['collection_id'], $d, $http->user());

            return $missions->courierView(Records::row('delivery_missions', $id, false), $http->user());
        });
    }

    /** A direct collection of this mission's order; anything else looks absent to the courier. */
    private function directCollection(string $missionId, string $collectionId): object
    {
        $mission = Records::row('delivery_missions', $missionId, false);
        $collection = DB::table('supplier_collections')
            ->where('id', $collectionId)
            ->where('destination', 'customer')
            ->where('order_id', $mission->order_id)
            ->first();
        abort_unless($collection, 404);

        return $collection;
    }

    private function controlRules(): array
    {
        return [
            'packages' => ['required', 'integer', 'min:1', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['array:order_line_id,quantity,identifiers'],
            'items.*.order_line_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.identifiers' => ['present', 'array', 'max:1000'],
            'items.*.identifiers.*' => ['string', 'max:100'],
        ];
    }
}
