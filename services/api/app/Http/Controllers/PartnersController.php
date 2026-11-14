<?php

namespace App\Http\Controllers;

use App\Domain\Partners\Partners;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PartnersController extends OperationalController
{
    /** Public page: published partners only; an empty list lets the site show an honest invitation. */
    public function published(Partners $partners)
    {
        $rows = DB::table('partners')->where('status', 'published')->orderBy('name')->orderBy('id')->get();

        return [
            'data' => $rows->map(fn ($p) => $partners->partnerDto($p, true))->values(),
            'meta' => ['has_partners' => $rows->isNotEmpty()],
        ];
    }

    public function apply(Request $http, Partners $partners)
    {
        $data = $http->validate([
            'contact_name' => ['required', 'string', 'max:150'],
            'business_name' => ['required', 'string', 'max:150'],
            'activity' => ['required', 'string', 'max:2000'],
            // Digits with the usual separators, as people write numbers in Douala.
            'phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9][0-9 ().-]{5,38}$/'],
            'email' => ['nullable', 'email', 'max:254'],
            'city' => ['required', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'product_types' => ['required', 'array', 'min:1', 'max:10'],
            'product_types.*' => ['string', 'max:80'],
            'message' => ['nullable', 'string', 'max:4000'],
            'consent' => ['required', 'accepted'],
            // Left empty by people; filled by robots.
            'company_site' => ['nullable', 'string', 'max:300'],
        ]);

        return response()->json(['data' => DB::transaction(fn () => $partners->apply($data), 3)], 202);
    }

    public function applications(Request $http, Partners $partners)
    {
        $d = $http->validate(['status' => ['nullable', 'in:submitted,in_review,accepted,rejected,converted']]);

        return app(ReadSnapshot::class)->run(function () use ($http, $d, $partners) {
            $q = DB::table('partner_applications')->orderBy('created_at')->orderBy('id');
            if (isset($d['status'])) {
                $q->where('status', $d['status']);
            }

            return $this->page($q, $http, fn ($a) => $partners->applicationDto($a));
        });
    }

    public function application(string $id, Partners $partners)
    {
        return ['data' => $partners->applicationDto(Records::row('partner_applications', $id, false))];
    }

    public function note(Request $http, string $id, Partners $partners)
    {
        $data = $http->validate(['body' => ['required', 'string', 'max:4000']]);

        return $this->command(
            $http,
            'partner.note:'.$id,
            $data,
            fn ($d) => $partners->note($id, $d, $http->user()),
            201,
        );
    }

    public function attach(Request $http, string $id, Partners $partners)
    {
        $data = $http->validate(['file' => ['required', 'file', 'max:10240']]);

        return response()->json(['data' => $partners->attach($id, $data['file'], $http->user())], 201);
    }

    public function decide(Request $http, string $id, Partners $partners)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:accept,reject'],
            'note' => ['required', 'string', 'max:4000'],
        ]);

        return $this->command(
            $http,
            'partner.decide:'.$id,
            $data,
            fn ($d) => $partners->decide($id, $d, $http->user()),
        );
    }

    public function convert(Request $http, string $id, Partners $partners)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'supplier_id' => ['nullable', 'uuid'],
            'shop' => ['nullable', 'array:slug,name,description'],
        ]);

        return $this->command(
            $http,
            'partner.convert:'.$id,
            $data,
            fn ($d) => $partners->convert($id, $d, $http->user()),
        );
    }

    public function partners(Request $http, Partners $partners)
    {
        return $this->page(
            DB::table('partners')->orderBy('name')->orderBy('id'),
            $http,
            fn ($p) => $partners->partnerDto($p, false),
        );
    }

    public function save(Request $http, Partners $partners, ?string $id = null)
    {
        $text = ['required', 'array:fr,en'];
        $data = $http->validate([
            ...$id
                ? ['version' => ['required', 'integer', 'min:1']]
                : [
                    'application_id' => ['nullable', 'uuid'],
                    'supplier_id' => ['nullable', 'uuid', 'exists:suppliers,id'],
                    'shop_id' => ['nullable', 'uuid', 'exists:shops,id'],
                ],
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'unique:partners,slug'.($id ? ','.$id : ''),
            ],
            'domain' => $text,
            'domain.*' => ['nullable', 'string', 'max:150'],
            'presentation' => $text,
            'presentation.*' => ['nullable', 'string', 'max:3000'],
            'advantages' => $text,
            'advantages.*' => ['nullable', 'string', 'max:2000'],
            'logo_media_id' => ['nullable', 'uuid', 'exists:media_assets,id'],
            'website' => ['nullable', 'url:https', 'max:300'],
        ]);

        return $this->command(
            $http,
            'partner.save:'.($id ?? 'new'),
            $data,
            fn ($d) => $partners->save($id, $d, $http->user()),
            $id ? 200 : 201,
        );
    }

    public function status(Request $http, string $id, Partners $partners)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:draft,published,archived'],
        ]);

        return $this->command(
            $http,
            'partner.status:'.$id,
            $data,
            fn ($d) => $partners->status($id, $d, $http->user()),
        );
    }
}
