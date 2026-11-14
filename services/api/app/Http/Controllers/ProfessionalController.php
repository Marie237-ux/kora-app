<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Carts;
use App\Domain\Procurement\Records;
use App\Domain\Professional\BusinessProfiles;
use App\Domain\Professional\ProfessionalQuotes;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProfessionalController extends OperationalController
{
    public function profiles(Request $http, BusinessProfiles $profiles)
    {
        return [
            'data' => DB::table('professional_profiles')
                ->whereIn(
                    'id',
                    DB::table('professional_members')->where('user_id', $http->user()->id)->select('profile_id'),
                )
                ->orderBy('id')
                ->get()
                ->map(fn ($profile) => $profiles->dto($profile)),
        ];
    }

    private function identity(Request $http, bool $update): array
    {
        return $http->validate([
            ...$update ? ['version' => ['required', 'integer', 'min:1']] : [],
            'legal_name' => ['required', 'string', 'max:200'],
            'billing_address' => ['required', 'string', 'max:1000'],
            'niu' => ['nullable', 'string', 'max:100'],
            'rccm' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
    }

    public function createProfile(Request $http, BusinessProfiles $profiles)
    {
        $d = $this->identity($http, false);

        return $this->command(
            $http,
            'professional.profile_create',
            $d,
            fn ($data) => $profiles->create($data, $http->user()),
            201,
        );
    }

    public function showProfile(Request $http, string $id, BusinessProfiles $profiles)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(fn () => $profiles->dto($profiles->authorized($id, $http->user()))),
        ];
    }

    public function updateProfile(Request $http, string $id, BusinessProfiles $profiles)
    {
        $d = $this->identity($http, true);

        return $this->command(
            $http,
            'professional.profile_update:'.$id,
            $d,
            fn ($data) => $profiles->update(
                $profiles->authorized($id, $http->user(), true, true, true),
                array_diff_key($data, ['version' => true]),
                $data['version'],
                $http->user(),
            ),
        );
    }

    public function saveMember(Request $http, string $id, BusinessProfiles $profiles)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', 'in:buyer,viewer'],
        ]);

        return $this->command(
            $http,
            'professional.member_save:'.$id,
            $d,
            fn ($data) => $profiles->member(
                $profiles->authorized($id, $http->user(), true, true, true),
                $data['email'],
                $data['role'],
                $data['version'],
                $http->user(),
            ),
        );
    }

    public function removeMember(Request $http, string $id, int $userId, BusinessProfiles $profiles)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command(
            $http,
            'professional.member_remove:'.$id.':'.$userId,
            $d,
            fn ($data) => $profiles->removeMember(
                $profiles->authorized($id, $http->user(), true, true, true),
                $userId,
                $data['version'],
                $http->user(),
            ),
        );
    }

    private function addressData(Request $http, bool $update): array
    {
        return $http->validate([
            ...$update ? ['version' => ['required', 'integer', 'min:1']] : [],
            'label' => ['required', 'string', 'max:80'],
            'district' => ['required', 'string', 'max:120'],
            'landmark' => ['required', 'string', 'max:300'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{6,20}$/'],
            'alternate_name' => ['nullable', 'required_with:alternate_phone', 'string', 'max:150'],
            'alternate_phone' => ['nullable', 'required_with:alternate_name', 'string', 'regex:/^\+?[0-9 ]{6,20}$/'],
        ]);
    }

    public function saveAddress(Request $http, string $id, BusinessProfiles $profiles, ?string $addressId = null)
    {
        $d = $this->addressData($http, $addressId !== null);

        return $this->command(
            $http,
            'professional.address_save:'.$id.':'.($addressId ?? 'new'),
            $d,
            function ($data) use ($http, $id, $addressId, $profiles) {
                $profile = $profiles->authorized($id, $http->user(), true, false, true);

                return $profiles->saveAddress(
                    $profile,
                    $addressId ? $profiles->address($profile, $addressId) : null,
                    $data,
                    $http->user(),
                );
            },
            $addressId ? 200 : 201,
        );
    }

    public function archiveAddress(Request $http, string $id, string $addressId, BusinessProfiles $profiles)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command($http, 'professional.address_archive:'.$addressId, $d, function ($data) use (
            $http,
            $id,
            $addressId,
            $profiles,
        ) {
            $profile = $profiles->authorized($id, $http->user(), true, false, true);
            $address = $profiles->address($profile, $addressId);
            abort_unless((int) $address->version === $data['version'], 409);
            DB::table('professional_addresses')
                ->where('id', $addressId)
                ->update(['archived_at' => now(), 'version' => $address->version + 1, 'updated_at' => now()]);
            Records::audit($http->user(), 'professional.address_archived', $addressId);

            return ['id' => $addressId, 'archived' => true];
        });
    }

    public function quotes(Request $http, string $id, BusinessProfiles $profiles, ProfessionalQuotes $quotes)
    {
        $profiles->authorized($id, $http->user());

        return $this->page(
            DB::table('professional_quotes')->where('profile_id', $id)->orderByDesc('created_at')->orderBy('id'),
            $http,
            fn ($quote) => $quotes->dto($quote),
        );
    }

    public function requestQuote(
        Request $http,
        string $id,
        BusinessProfiles $profiles,
        ProfessionalQuotes $quotes,
        Carts $carts,
    ) {
        $d = $http->validate([
            'cart_version' => ['required', 'integer', 'min:1'],
            'address_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        return $this->command(
            $http,
            'professional.quote_request:'.$id,
            $d,
            function ($data) use ($http, $id, $profiles, $quotes, $carts) {
                $profile = $profiles->authorized($id, $http->user(), true, false, true);
                $cart = $carts->current($http, true);
                $carts->version($cart, $data['cart_version']);

                return $quotes->request(
                    $profile,
                    $cart,
                    $profiles->address($profile, $data['address_id']),
                    $data['notes'] ?? '',
                    $http->user(),
                );
            },
            201,
        );
    }

    public function showQuote(
        Request $http,
        string $id,
        string $quoteId,
        BusinessProfiles $profiles,
        ProfessionalQuotes $quotes,
    ) {
        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $quotes->dto($quotes->owned($profiles->authorized($id, $http->user()), $quoteId)),
            ),
        ];
    }

    public function accept(
        Request $http,
        string $id,
        string $quoteId,
        BusinessProfiles $profiles,
        ProfessionalQuotes $quotes,
    ) {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'revision_id' => ['required', 'uuid'],
            'accepted_total' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/'],
            'supplier_unreserved_acknowledged' => ['required', 'accepted'],
        ]);

        return $this->decision($http, $id, $quoteId, $d, true, $profiles, $quotes);
    }

    public function decline(
        Request $http,
        string $id,
        string $quoteId,
        BusinessProfiles $profiles,
        ProfessionalQuotes $quotes,
    ) {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->decision($http, $id, $quoteId, $d, false, $profiles, $quotes);
    }

    private function decision(
        Request $http,
        string $id,
        string $quoteId,
        array $data,
        bool $accept,
        BusinessProfiles $profiles,
        ProfessionalQuotes $quotes,
    ) {
        return $this->command(
            $http,
            'professional.quote_'.($accept ? 'accept:' : 'decline:').$quoteId,
            $data,
            function ($d) use ($http, $id, $quoteId, $accept, $profiles, $quotes) {
                $profile = $profiles->authorized($id, $http->user(), true, false, true);

                return $quotes->decide($quotes->owned($profile, $quoteId, true), $d, $accept, $http->user());
            },
        );
    }

    public function preview(
        Request $http,
        string $id,
        string $quoteId,
        BusinessProfiles $profiles,
        ProfessionalQuotes $quotes,
    ) {
        return $this->command($http, 'professional.quote_preview:'.$quoteId, [], function () use (
            $http,
            $id,
            $quoteId,
            $profiles,
            $quotes,
        ) {
            $profile = $profiles->authorized($id, $http->user(), true, false, true);

            return $quotes->preview($quotes->owned($profile, $quoteId, true), $http->user());
        });
    }

    public function convert(
        Request $http,
        string $id,
        string $quoteId,
        BusinessProfiles $profiles,
        ProfessionalQuotes $quotes,
    ) {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'accepted_total' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/'],
            'rule_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->command(
            $http,
            'professional.quote_convert:'.$quoteId,
            $d,
            function ($data) use ($http, $id, $quoteId, $profiles, $quotes) {
                $profile = $profiles->authorized($id, $http->user(), true, false, true);

                return $quotes->convert($quotes->owned($profile, $quoteId, true), $data, $http->user());
            },
            201,
        );
    }

    public function sharedOrder(
        Request $http,
        string $id,
        string $orderId,
        BusinessProfiles $profiles,
        ProfessionalQuotes $quotes,
    ) {
        $profiles->authorized($id, $http->user());
        abort_unless(
            DB::table('professional_quotes')->where('profile_id', $id)->where('order_id', $orderId)->exists(),
            404,
        );

        return ['data' => $quotes->orderView(Records::row('orders', $orderId, false), $http->user())];
    }

    public function manageIndex(Request $http, ProfessionalQuotes $quotes)
    {
        return $this->page(
            DB::table('professional_quotes')->orderByDesc('created_at')->orderBy('id'),
            $http,
            fn ($quote) => $quotes->dto($quote, in_array('pricing.read', $http->user()->permissions(), true)),
        );
    }

    public function manageShow(Request $http, string $quoteId, ProfessionalQuotes $quotes)
    {
        return [
            'data' => $quotes->dto(
                Records::row('professional_quotes', $quoteId, false),
                in_array('pricing.read', $http->user()->permissions(), true),
            ),
        ];
    }

    public function issue(Request $http, string $quoteId, BusinessProfiles $profiles, ProfessionalQuotes $quotes)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'valid_until' => ['required', 'date', 'after:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'conditions' => ['required', 'array:fr,en'],
            'conditions.fr' => ['required', 'string', 'max:3000'],
            'conditions.en' => ['required', 'string', 'max:3000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:offer_id,unit_price'],
            'lines.*.offer_id' => ['required', 'uuid', 'distinct'],
            'lines.*.unit_price' => ['required', 'string'],
            'loss_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->command($http, 'professional.quote_issue:'.$quoteId, $d, function ($data) use (
            $http,
            $quoteId,
            $quotes,
        ) {
            $initial = Records::row('professional_quotes', $quoteId, false);
            $profile = Records::row('professional_profiles', $initial->profile_id);

            return $quotes->issue($profile, Records::row('professional_quotes', $quoteId), $data, $http->user());
        });
    }
}
