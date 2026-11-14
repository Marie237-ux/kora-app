<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AccountNotificationsController extends OperationalController
{
    public function index(Request $http, PublicCatalogue $catalogue)
    {
        $data = $http->validate(['unread' => ['nullable', 'boolean']]);
        $q = DB::table('account_notifications')
            ->where('user_id', $http->user()->id)
            ->orderByDesc('created_at')
            ->orderBy('id');
        $unread = (clone $q)->whereNull('read_at')->count();
        if (! empty($data['unread'])) {
            $q->whereNull('read_at');
        }
        $fr = [
            'product_request_available' => 'Le produit recherché est publié sur le site.',
            'product_request_proposal' => 'Une alternative est à examiner.',
            'product_request_message' => 'KORA a répondu à votre demande.',
        ];
        $en = [
            'product_request_available' => 'The requested product is now listed on the site.',
            'product_request_proposal' => 'An alternative is ready to review.',
            'product_request_message' => 'KORA replied to your request.',
        ];

        return [
            ...$this->page(
                $q,
                $http,
                fn ($r) => [
                    'id' => $r->id,
                    'kind' => $r->kind,
                    'title' => (app()->getLocale() === 'fr' ? $fr : $en)[$r->kind],
                    'request_id' => $r->request_id,
                    'resource_url' => '/api/v1/product-requests/'.$r->request_id,
                    'offer' => $r->offer_id ? $catalogue->offer($r->offer_id, app()->getLocale()) : null,
                    'read_at' => Records::iso($r->read_at),
                    'created_at' => Records::iso($r->created_at),
                ],
            ),
            'unread_total' => $unread,
        ];
    }

    public function read(Request $http, string $id)
    {
        return $this->command($http, 'account_notification.read:'.$id, [], function () use ($http, $id) {
            $r = DB::table('account_notifications')
                ->where('id', $id)
                ->where('user_id', $http->user()->id)
                ->lockForUpdate()
                ->first();
            abort_unless($r, 404);
            if (! $r->read_at) {
                DB::table('account_notifications')
                    ->where('id', $id)
                    ->update(['read_at' => now()]);
            }

            return ['id' => $id, 'read_at' => Records::iso($r->read_at ?? now()->toDateTimeString())];
        });
    }
}
