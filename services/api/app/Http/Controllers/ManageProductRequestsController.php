<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\Records;
use App\Domain\ProductRequests\ProductRequests;
use App\Domain\ProductRequests\RequestMetrics;
use App\Domain\ProductRequests\RequestPhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ManageProductRequestsController extends OperationalController
{
    public function metrics(Request $http, RequestMetrics $metrics)
    {
        $d = $http->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return ['data' => $metrics->report($d['from'] ?? null, $d['to'] ?? null)];
    }

    public function index(Request $http, ProductRequests $requests)
    {
        $data = $http->validate([
            'state' => [
                'nullable',
                'in:submitted,reviewing,needs_details,sourcing,identified,preparing,published,not_found,closed,withdrawn',
            ],
            'assignee_id' => ['nullable', 'integer', 'min:1'],
            'group_id' => ['nullable', 'uuid'],
            'priority' => ['nullable', 'in:normal,high,urgent'],
            'q' => ['nullable', 'string', 'max:150'],
        ]);
        $q = DB::table('product_requests')->where('state', '!=', 'draft')->orderByDesc('updated_at')->orderBy('id');
        foreach (['state', 'assignee_id', 'group_id', 'priority'] as $f) {
            if (! empty($data[$f])) {
                $q->where($f, $data[$f]);
            }
        }
        if (! empty($data['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']).'%';
            $q->where(
                fn ($q) => $q
                    ->where('reference', 'like', $term)
                    ->orWhere('details->name', 'like', $term)
                    ->orWhere('details->description', 'like', $term),
            );
        }

        return $this->page($q, $http, fn ($r) => $requests->dto($r, $http->user()));
    }

    public function show(Request $http, string $id, ProductRequests $requests)
    {
        return ['data' => $requests->dto($requests->staff($id, false), $http->user())];
    }

    public function transition(Request $http, string $id, ProductRequests $requests)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'state' => ['required', 'in:reviewing,needs_details,sourcing,preparing,not_found,closed'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->command(
            $http,
            'request.state:'.$id,
            $data,
            fn ($d) => $requests->transition($id, $d, $http->user()),
        );
    }

    public function assign(Request $http, string $id, ProductRequests $requests)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'assignee_id' => ['present', 'nullable', 'integer', 'min:1'],
            'priority' => ['required', 'in:normal,high,urgent'],
            'group_id' => ['present', 'nullable', 'uuid'],
        ]);

        return $this->command(
            $http,
            'request.assign:'.$id,
            $data,
            fn ($d) => $requests->assign($id, $d, $http->user()),
        );
    }

    public function messages(Request $http, string $id, ProductRequests $requests)
    {
        $requests->staff($id, false);

        return $this->page(
            DB::table('product_request_messages')->where('request_id', $id)->orderBy('created_at')->orderBy('id'),
            $http,
            fn ($r) => [
                'id' => $r->id,
                'author_id' => $r->author_id,
                'author_kind' => $r->author_kind,
                'visibility' => $r->visibility,
                'locale' => $r->locale,
                'body' => $r->body,
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }

    public function message(Request $http, string $id, ProductRequests $requests)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['required', 'in:customer,internal'],
            'locale' => ['required', 'in:fr,en'],
        ]);

        return $this->command(
            $http,
            'request.message:'.$id,
            $data,
            fn ($d) => $requests->message(
                $requests->staff($id),
                $d['version'],
                $d['body'],
                $d['locale'],
                $d['visibility'],
                $http->user(),
                true,
            ),
            201,
        );
    }

    public function events(Request $http, string $id, ProductRequests $requests)
    {
        $requests->staff($id, false);

        return $this->page(
            DB::table('product_request_events')->where('request_id', $id)->orderBy('version'),
            $http,
            fn ($r) => [
                'version' => (int) $r->version,
                'action' => $r->action,
                'visibility' => $r->visibility,
                'actor_id' => $r->actor_id,
                'data' => json_decode($r->data, true),
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }

    public function groups(Request $http)
    {
        return $this->page(
            DB::table('product_request_groups')->orderBy('title')->orderBy('id'),
            $http,
            fn ($r) => ['id' => $r->id, 'title' => $r->title],
        );
    }

    public function createGroup(Request $http)
    {
        $data = $http->validate(['title' => ['required', 'string', 'max:200']]);

        return $this->command(
            $http,
            'request.group_create',
            $data,
            function ($d) use ($http) {
                $id = Records::id();
                DB::table('product_request_groups')->insert([
                    'id' => $id,
                    'title' => $d['title'],
                    'created_by' => $http->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Records::audit($http->user(), 'product_request.group_created', $id);

                return ['id' => $id, 'title' => $d['title']];
            },
            201,
        );
    }

    public function candidate(Request $http, string $id, ProductRequests $requests)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'supplier_id' => ['nullable', 'uuid'],
            'offer_id' => ['nullable', 'uuid'],
            'quoted_amount' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['required', 'string', 'max:5000'],
        ]);

        return $this->command(
            $http,
            'request.candidate:'.$id,
            $data,
            fn ($d) => $requests->candidate($id, $d, $http->user()),
            201,
        );
    }

    public function candidates(Request $http, string $id, ProductRequests $requests)
    {
        $requests->staff($id, false);

        return $this->page(
            DB::table('product_request_candidates')->where('request_id', $id)->orderBy('created_at')->orderBy('id'),
            $http,
            fn ($r) => [
                'id' => $r->id,
                'supplier_id' => $r->supplier_id,
                'offer_id' => $r->offer_id,
                'notes' => $r->notes,
                'reference' => $r->reference,
                'quoted_amount' => in_array('purchases.read', $http->user()->permissions(), true) && $r->quoted_amount !== null
                        ? (string) $r->quoted_amount
                        : null,
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }

    public function linkOffer(Request $http, string $id, ProductRequests $requests)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'offer_id' => ['required', 'uuid'],
            'is_alternative' => ['required', 'boolean'],
            'identity_verified' => ['nullable', 'boolean'],
        ]);

        return $this->command(
            $http,
            'request.offer_link:'.$id,
            $data,
            fn ($d) => $requests->linkOffer($id, $d, $http->user()),
        );
    }

    public function createProduct(Request $http, string $id, ProductRequests $requests)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'product' => ['required', 'array:category_id,slug,name,description,variants'],
        ]);

        return $this->command(
            $http,
            'request.product_draft:'.$id,
            $data,
            fn ($d) => $requests->createProductDraft($id, $d, $http->user()),
            201,
        );
    }

    public function photo(Request $http, string $id, string $photoId, ProductRequests $requests, RequestPhotos $photos)
    {
        $requests->staff($id, false);
        $p = DB::table('product_request_photos')->where('request_id', $id)->where('id', $photoId)->first();
        abort_unless($p, 404);

        return $photos->file($p);
    }
}
