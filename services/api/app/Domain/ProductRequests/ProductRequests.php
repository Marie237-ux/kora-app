<?php

namespace App\Domain\ProductRequests;

use App\Domain\Catalogue\CatalogueManager;
use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Domain\Shared\SessionIdentity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProductRequests
{
    public const FINAL_STATES = ['closed', 'withdrawn'];

    public function owned(Request $http, string $id, bool $lock = false): object
    {
        $hash = app(SessionIdentity::class)->hash($http, false);
        $user = $http->user();
        $q = DB::table('product_requests')
            ->where('id', $id)
            ->where(function ($q) use ($hash, $user) {
                if ($user) {
                    $q->where('user_id', $user->id);
                } else {
                    $q->whereRaw('1 = 0');
                }
                if ($hash) {
                    $q->orWhere(fn ($guest) => $guest->whereNull('user_id')->where('guest_owner_hash', $hash));
                }
            });
        if ($lock) {
            $q->lockForUpdate();
        }
        $row = $q->first();
        abort_unless($row, 404);
        if ($row->state === 'draft' && $row->draft_expires_at && now()->greaterThanOrEqualTo($row->draft_expires_at)) {
            Money::fail('draft', 'REQUEST_DRAFT_EXPIRED');
        }

        return $row;
    }

    public function staff(string $id, bool $lock = true): object
    {
        $q = DB::table('product_requests')->where('id', $id)->where('state', '!=', 'draft');
        if ($lock) {
            $q->lockForUpdate();
        }
        $r = $q->first();
        abort_unless($r, 404);

        return $r;
    }

    public function create(Request $http, array $data, string $ownerHash): array
    {
        $id = Records::id();
        $reference =
            'PR-'.now('Africa/Douala')->format('Ymd').'-'.strtoupper(substr(str_replace('-', '', $id), 0, 12));
        $ttl = max(1, min(90, (int) config('kora.request_draft_days', 7)));
        DB::table('product_requests')->insert([
            'id' => $id,
            'reference' => $reference,
            'user_id' => $http->user()?->id,
            'guest_owner_hash' => $ownerHash,
            'locale' => $data['locale'],
            'details' => Records::json($data['details']),
            'draft_expires_at' => now()->addDays($ttl),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $r = Records::row('product_requests', $id);
        $this->event($r, 'draft_created', $http->user(), 'customer', []);

        return $this->dto($r);
    }

    public function updateDraft(Request $http, string $id, array $data): array
    {
        $r = $this->owned($http, $id, true);
        $this->version($r, $data['version']);
        if ($r->state !== 'draft') {
            Money::fail('state', 'REQUEST_DRAFT_ONLY');
        }
        $r = $this->change($r, ['details' => Records::json($data['details']), 'locale' => $data['locale']]);
        $this->event($r, 'draft_updated', $http->user(), 'customer', []);

        return $this->dto($r);
    }

    public function submit(Request $http, string $id, array $data): array
    {
        $r = $this->owned($http, $id, true);
        $this->version($r, $data['version']);
        if ($r->state !== 'draft') {
            Money::fail('state', 'REQUEST_DRAFT_ONLY');
        }
        $details = json_decode($r->details, true);
        $photos = DB::table('product_request_photos')->where('request_id', $id)->where('revoked', false)->count();
        if (
            empty($details['quantity']) ||
            (mb_strlen($details['description'] ?? '') < 10 &&
                ! ($photos && (! empty($details['name']) || ! empty($details['description']))))
        ) {
            Money::fail('details', 'REQUEST_DETAILS_REQUIRED');
        }
        $r = $this->change($r, [
            'user_id' => $http->user()->id,
            'guest_owner_hash' => null,
            'state' => 'submitted',
            'submitted_at' => now(),
            'draft_expires_at' => null,
        ]);
        $this->event($r, 'submitted', $http->user(), 'customer', ['state' => 'submitted']);
        Records::audit($http->user(), 'product_request.submitted', $id);

        return $this->dto($r);
    }

    public function withdraw(Request $http, string $id, int $version): array
    {
        $r = $this->owned($http, $id, true);
        $this->version($r, $version);
        $this->open($r);
        $r = $this->change($r, ['state' => 'withdrawn', 'closed_at' => now()]);
        $this->event($r, 'withdrawn', $http->user(), 'customer', ['state' => 'withdrawn']);
        Records::audit($http->user(), 'product_request.withdrawn', $id);

        return $this->dto($r);
    }

    public function message(
        object $r,
        int $version,
        string $body,
        string $locale,
        string $visibility,
        User $actor,
        bool $staff,
    ): array {
        $this->version($r, $version);
        $this->open($r);
        abort_if($r->state === 'draft', 422);
        if (! $staff) {
            $visibility = 'customer';
            $locale = $r->locale;
        }
        $id = Records::id();
        DB::table('product_request_messages')->insert([
            'id' => $id,
            'request_id' => $r->id,
            'author_id' => $actor->id,
            'author_kind' => $staff ? 'staff' : 'customer',
            'visibility' => $visibility,
            'locale' => $locale,
            'body' => trim($body),
            'created_at' => now(),
        ]);
        $state = ! $staff && in_array($r->state, ['needs_details', 'not_found'], true) ? 'reviewing' : $r->state;
        $r = $this->change($r, ['state' => $state]);
        $this->event($r, 'message_added', $actor, $visibility, ['message_id' => $id, 'state' => $state]);
        if ($staff && $visibility === 'customer') {
            Records::event('ProductRequestMessageAdded', $id, ['request_id' => $r->id, 'message_id' => $id]);
        }
        Records::audit($actor, 'product_request.message_added', $r->id, [
            'message_id' => $id,
            'visibility' => $visibility,
        ]);

        return ['id' => $id, 'request_version' => (int) $r->version, 'state' => $r->state];
    }

    public function transition(string $id, array $data, User $actor): array
    {
        $r = $this->staff($id);
        $this->version($r, $data['version']);
        $this->open($r);
        $allowed = [
            'reviewing' => ['submitted', 'needs_details', 'sourcing', 'identified', 'preparing', 'not_found'],
            'needs_details' => ['submitted', 'reviewing', 'sourcing', 'identified'],
            'sourcing' => ['submitted', 'reviewing', 'needs_details', 'identified', 'preparing'],
            'preparing' => ['identified'],
            'not_found' => ['submitted', 'reviewing', 'needs_details', 'sourcing', 'identified', 'preparing'],
            'closed' => ['published', 'not_found', 'reviewing', 'sourcing', 'needs_details', 'identified', 'preparing'],
        ];
        if (! in_array($r->state, $allowed[$data['state']] ?? [], true)) {
            Money::fail('state', 'REQUEST_TRANSITION_INVALID');
        }
        if ($data['state'] === 'preparing' && ! $r->draft_product_id && ! $r->linked_offer_id) {
            Money::fail('state', 'REQUEST_LINK_REQUIRED');
        }
        if (in_array($data['state'], ['needs_details', 'not_found', 'closed'], true) && empty($data['message'])) {
            Money::fail('message', 'REQUEST_MESSAGE_REQUIRED');
        }
        $changes = ['state' => $data['state']];
        if (in_array($data['state'], ['not_found', 'closed'], true)) {
            $changes['resolution'] = $data['message'];
        }
        if ($data['state'] === 'closed') {
            $changes['closed_at'] = now();
        }
        $r = $this->change($r, $changes);
        $this->event($r, 'state_changed', $actor, 'customer', [
            'state' => $r->state,
            'message' => $data['message'] ?? null,
        ]);
        if (! empty($data['message'])) {
            $messageId = Records::id();
            DB::table('product_request_messages')->insert([
                'id' => $messageId,
                'request_id' => $id,
                'author_id' => $actor->id,
                'author_kind' => 'staff',
                'visibility' => 'customer',
                'locale' => $r->locale,
                'body' => $data['message'],
                'created_at' => now(),
            ]);
            Records::event('ProductRequestMessageAdded', $messageId, ['request_id' => $id, 'message_id' => $messageId]);
        }
        Records::audit($actor, 'product_request.state_changed', $id, ['state' => $r->state]);

        return $this->dto($r, $actor);
    }

    public function assign(string $id, array $data, User $actor): array
    {
        $r = $this->staff($id);
        $this->version($r, $data['version']);
        $this->open($r);
        if ($data['assignee_id'] !== null) {
            $assignee = User::find($data['assignee_id']);
            if (! $assignee || ! $assignee->isStaff() || ! in_array('requests.write', $assignee->permissions(), true)) {
                Money::fail('assignee_id', 'REQUEST_ASSIGNEE_INVALID');
            }
        }
        if ($data['group_id'] !== null) {
            Records::row('product_request_groups', $data['group_id'], false);
        }
        $r = $this->change($r, array_intersect_key($data, array_flip(['assignee_id', 'priority', 'group_id'])));
        $this->event($r, 'assignment_updated', $actor, 'internal', [
            'assignee_id' => $r->assignee_id,
            'priority' => $r->priority,
            'group_id' => $r->group_id,
        ]);
        Records::audit($actor, 'product_request.assignment_updated', $id);

        return $this->dto($r, $actor);
    }

    public function candidate(string $id, array $data, User $actor): array
    {
        $r = $this->staff($id);
        $this->version($r, $data['version']);
        $this->open($r);
        if (! empty($data['supplier_id'])) {
            Records::row('suppliers', $data['supplier_id'], false);
        }
        if (! empty($data['offer_id'])) {
            $offer = Records::row('offers', $data['offer_id'], false);
            $shop = Records::row('shops', $offer->shop_id, false);
            if (! empty($data['supplier_id']) && $shop->supplier_id !== $data['supplier_id']) {
                Money::fail('supplier_id', 'SUPPLIER_MISMATCH');
            }
        }
        $candidateId = Records::id();
        DB::table('product_request_candidates')->insert([
            'id' => $candidateId,
            'request_id' => $id,
            'supplier_id' => $data['supplier_id'] ?? null,
            'offer_id' => $data['offer_id'] ?? null,
            'quoted_amount' => isset($data['quoted_amount']) ? Money::parse($data['quoted_amount']) : null,
            'notes' => $data['notes'],
            'reference' => $data['reference'] ?? null,
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        $r = $this->change($r, []);
        $this->event($r, 'candidate_added', $actor, 'internal', ['candidate_id' => $candidateId]);
        Records::audit($actor, 'product_request.candidate_added', $id, ['candidate_id' => $candidateId]);

        return ['id' => $candidateId, 'request_version' => (int) $r->version];
    }

    public function linkOffer(string $id, array $data, User $actor): array
    {
        $r = $this->staff($id);
        $this->version($r, $data['version']);
        $this->open($r);
        $offer = Records::row('offers', $data['offer_id'], false);
        abort_unless($offer->status !== 'archived', 422);
        if (
            ! app(PublicCatalogue::class)->offer($offer->id, $r->locale) &&
            ! in_array('catalogue.read', $actor->permissions(), true)
        ) {
            abort(404);
        }
        if (! $data['is_alternative'] && empty($data['identity_verified'])) {
            Money::fail('identity_verified', 'REQUEST_IDENTITY_REQUIRED');
        }
        $r = $this->change($r, [
            'linked_offer_id' => $offer->id,
            'is_alternative' => (bool) $data['is_alternative'],
            'alternative_accepted_at' => null,
            'state' => $data['is_alternative'] ? 'identified' : 'preparing',
        ]);
        $this->event($r, 'offer_linked', $actor, 'customer', [
            'state' => $r->state,
            'is_alternative' => (bool) $r->is_alternative,
        ]);
        Records::event('ProductRequestOfferReady', $id.':'.$r->version, [
            'request_id' => $id,
            'offer_id' => $offer->id,
            'proposal' => (bool) $r->is_alternative,
        ]);
        Records::audit($actor, 'product_request.offer_linked', $id, [
            'offer_id' => $offer->id,
            'is_alternative' => (bool) $r->is_alternative,
        ]);

        return $this->dto($r, $actor);
    }

    public function chooseAlternative(Request $http, string $id, array $data): array
    {
        $r = $this->owned($http, $id, true);
        $this->version($r, $data['version']);
        $this->open($r);
        if (! $r->is_alternative || ! $r->linked_offer_id || $r->state !== 'identified') {
            Money::fail('state', 'REQUEST_TRANSITION_INVALID');
        }
        if ($data['accept']) {
            $offer = app(PublicCatalogue::class)->offer($r->linked_offer_id, $r->locale);
            if (! $offer || $offer['availability'] === 'unavailable') {
                Money::fail('offer_id', 'REQUEST_OFFER_NOT_PUBLIC');
            }
            $r = $this->change($r, ['alternative_accepted_at' => now(), 'state' => 'preparing']);
            Records::event('ProductRequestOfferReady', $id.':'.$r->version, [
                'request_id' => $id,
                'offer_id' => $r->linked_offer_id,
                'proposal' => false,
            ]);
        } else {
            $r = $this->change($r, ['linked_offer_id' => null, 'is_alternative' => false, 'state' => 'sourcing']);
        }
        $this->event($r, 'alternative_chosen', $http->user(), 'customer', [
            'accepted' => (bool) $data['accept'],
            'state' => $r->state,
        ]);
        Records::audit($http->user(), 'product_request.alternative_chosen', $id, [
            'accepted' => (bool) $data['accept'],
        ]);

        return $this->dto($r);
    }

    public function createProductDraft(string $id, array $data, User $actor): array
    {
        $r = $this->staff($id);
        $this->version($r, $data['version']);
        $this->open($r);
        abort_unless(in_array('catalogue.write', $actor->permissions(), true), 403);
        if ($r->draft_product_id) {
            Money::fail('draft_product_id', 'REQUEST_DRAFT_EXISTS');
        }
        $details = json_decode($r->details, true);
        $locale = $r->locale;
        $name = $data['product']['name'] ?? ['fr' => '', 'en' => ''];
        $description = $data['product']['description'] ?? ['fr' => '', 'en' => ''];
        if (empty($name[$locale])) {
            $name[$locale] = mb_substr($details['name'] ?: $details['description'] ?? '', 0, 150);
        }
        if (empty($description[$locale])) {
            $description[$locale] = $details['description'] ?? '';
        }
        $product = app(CatalogueManager::class)->save(
            'products',
            null,
            null,
            [...$data['product'], 'name' => $name, 'description' => $description],
            $actor,
        );
        $r = $this->change($r, ['draft_product_id' => $product['id'], 'state' => 'preparing']);
        $this->event($r, 'product_draft_created', $actor, 'customer', ['state' => 'preparing']);
        Records::audit($actor, 'product_request.product_draft_created', $id, ['product_id' => $product['id']]);

        return ['request' => $this->dto($r, $actor), 'product' => $product];
    }

    public function version(object $r, int $version): void
    {
        abort_unless((int) $r->version === $version, 409);
    }

    public function open(object $r): void
    {
        if (in_array($r->state, self::FINAL_STATES, true)) {
            Money::fail('state', 'REQUEST_CLOSED');
        }
    }

    public function change(object $r, array $changes): object
    {
        DB::table('product_requests')
            ->where('id', $r->id)
            ->update([...$changes, 'version' => $r->version + 1, 'updated_at' => now()]);

        return Records::row('product_requests', $r->id);
    }

    public function event(object $r, string $action, ?User $actor, string $visibility, array $data): void
    {
        DB::table('product_request_events')->insert([
            'request_id' => $r->id,
            'version' => $r->version,
            'action' => $action,
            'actor_id' => $actor?->id,
            'visibility' => $visibility,
            'data' => Records::json($data),
            'created_at' => now(),
        ]);
    }

    public function dto(object $r, ?User $staff = null): array
    {
        $offer = $r->linked_offer_id
            ? app(PublicCatalogue::class)->offer($r->linked_offer_id, app()->getLocale())
            : null;
        $data = [
            'id' => $r->id,
            'reference' => $r->reference,
            'version' => (int) $r->version,
            'locale' => $r->locale,
            'state' => $r->state,
            'details' => json_decode($r->details, true),
            'resolution' => $r->resolution,
            'is_alternative' => (bool) $r->is_alternative,
            'alternative_accepted_at' => Records::iso($r->alternative_accepted_at),
            'offer' => $offer,
            'currently_on_site' => $offer !== null,
            'submitted_at' => Records::iso($r->submitted_at),
            'draft_expires_at' => Records::iso($r->draft_expires_at),
            'closed_at' => Records::iso($r->closed_at),
            'photos' => DB::table('product_request_photos')
                ->where('request_id', $r->id)
                ->where('revoked', false)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(function ($p) use ($staff) {
                    $photo = app(RequestPhotos::class)->dto($p);
                    if ($staff) {
                        $photo['url'] = '/api/v1/operations/product-requests/'.$p->request_id.'/photos/'.$p->id;
                    }

                    return $photo;
                })
                ->all(),
            'created_at' => Records::iso($r->created_at),
            'updated_at' => Records::iso($r->updated_at),
        ];
        if ($staff) {
            $data += [
                'customer_id' => $r->user_id,
                'priority' => $r->priority,
                'assignee_id' => $r->assignee_id,
                'group_id' => $r->group_id,
                'draft_product_id' => $r->draft_product_id,
                'linked_offer_id' => $r->linked_offer_id,
            ];
        }

        return $data;
    }
}
