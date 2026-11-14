<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\Records;
use App\Domain\ProductRequests\ProductRequests;
use App\Domain\ProductRequests\RequestInput;
use App\Domain\ProductRequests\RequestPhotos;
use App\Domain\Shared\SessionCommands;
use App\Domain\Shared\SessionIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProductRequestController extends OperationalController
{
    private function details(Request $http): array
    {
        $data = $http->validate([
            'locale' => ['required', 'in:fr,en'],
            'details' => ['present', 'array:name,description,brand,reference,variant,quantity,budget,reference_url'],
        ]);

        return ['locale' => $data['locale'], 'details' => app(RequestInput::class)->details($data['details'])];
    }

    public function create(Request $http, ProductRequests $requests, SessionCommands $commands)
    {
        $data = $this->details($http);
        $result = $commands->execute(
            $http,
            'product_request.draft_create',
            $data,
            fn () => $requests->create($http, $data, app(SessionIdentity::class)->hash($http)),
        );

        return response()->json(['data' => $result], 201);
    }

    public function update(Request $http, string $id, ProductRequests $requests, SessionCommands $commands)
    {
        $data = [...$this->details($http), ...$http->validate(['version' => ['required', 'integer', 'min:1']])];

        return [
            'data' => $commands->execute(
                $http,
                'product_request.draft_update:'.$id,
                $data,
                fn () => $requests->updateDraft($http, $id, $data),
            ),
        ];
    }

    public function index(Request $http, ProductRequests $requests)
    {
        $data = $http->validate([
            'state' => [
                'nullable',
                'in:draft,submitted,reviewing,needs_details,sourcing,identified,preparing,published,not_found,closed,withdrawn',
            ],
        ]);
        $q = DB::table('product_requests')
            ->where('user_id', $http->user()->id)
            ->orderByDesc('created_at')
            ->orderBy('id');
        if (! empty($data['state'])) {
            $q->where('state', $data['state']);
        }

        return $this->page($q, $http, fn ($r) => $requests->dto($r));
    }

    public function show(Request $http, string $id, ProductRequests $requests)
    {
        return ['data' => $requests->dto($requests->owned($http, $id))];
    }

    public function submit(Request $http, string $id, ProductRequests $requests)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reviewed' => ['required', 'accepted'],
        ]);

        return $this->command(
            $http,
            'product_request.submit:'.$id,
            $data,
            fn ($d) => $requests->submit($http, $id, $d),
        );
    }

    public function withdraw(Request $http, string $id, ProductRequests $requests)
    {
        $data = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command(
            $http,
            'product_request.withdraw:'.$id,
            $data,
            fn ($d) => $requests->withdraw($http, $id, $d['version']),
        );
    }

    public function message(Request $http, string $id, ProductRequests $requests)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        return $this->command(
            $http,
            'product_request.message:'.$id,
            $data,
            fn ($d) => $requests->message(
                $requests->owned($http, $id, true),
                $d['version'],
                $d['body'],
                app()->getLocale(),
                'customer',
                $http->user(),
                false,
            ),
            201,
        );
    }

    public function messages(Request $http, string $id, ProductRequests $requests)
    {
        $requests->owned($http, $id);

        return $this->page(
            DB::table('product_request_messages')
                ->where('request_id', $id)
                ->where('visibility', 'customer')
                ->orderBy('created_at')
                ->orderBy('id'),
            $http,
            fn ($r) => [
                'id' => $r->id,
                'author_kind' => $r->author_kind,
                'locale' => $r->locale,
                'body' => $r->body,
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }

    public function events(Request $http, string $id, ProductRequests $requests)
    {
        $requests->owned($http, $id);

        return $this->page(
            DB::table('product_request_events')
                ->where('request_id', $id)
                ->where('visibility', 'customer')
                ->orderBy('version'),
            $http,
            fn ($r) => [
                'version' => (int) $r->version,
                'action' => $r->action,
                'data' => json_decode($r->data, true),
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }

    public function photo(Request $http, string $id, RequestPhotos $photos)
    {
        $data = $http->validate(['file' => ['required', 'file', 'max:2048']]);

        return response()->json(['data' => $photos->upload($http, $id, $data['file'])], 201);
    }

    public function photoFile(
        Request $http,
        string $id,
        string $photoId,
        ProductRequests $requests,
        RequestPhotos $photos,
    ) {
        $requests->owned($http, $id);
        $p = DB::table('product_request_photos')->where('request_id', $id)->where('id', $photoId)->first();
        abort_unless($p, 404);

        return $photos->file($p);
    }

    public function removePhoto(
        Request $http,
        string $id,
        string $photoId,
        ProductRequests $requests,
        SessionCommands $commands,
    ) {
        $data = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return [
            'data' => $commands->execute($http, 'product_request.photo_remove:'.$photoId, $data, function () use (
                $data,
                $http,
                $id,
                $photoId,
                $requests,
            ) {
                $r = $requests->owned($http, $id, true);
                $requests->open($r);
                $requests->version($r, $data['version']);
                $p = DB::table('product_request_photos')
                    ->where('request_id', $id)
                    ->where('id', $photoId)
                    ->lockForUpdate()
                    ->first();
                abort_unless($p, 404);
                DB::table('product_request_photos')
                    ->where('id', $p->id)
                    ->update(['revoked' => true, 'updated_at' => now()]);
                $r = $requests->change($r, []);
                $requests->event($r, 'photo_removed', $http->user(), 'customer', ['photo_id' => $photoId]);

                return ['id' => $photoId, 'request_version' => (int) $r->version];
            }),
        ];
    }

    public function choose(Request $http, string $id, ProductRequests $requests)
    {
        $data = $http->validate(['version' => ['required', 'integer', 'min:1'], 'accept' => ['required', 'boolean']]);

        return $this->command(
            $http,
            'product_request.alternative:'.$id,
            $data,
            fn ($d) => $requests->chooseAlternative($http, $id, $d),
        );
    }
}
