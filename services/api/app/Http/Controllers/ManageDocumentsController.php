<?php

namespace App\Http\Controllers;

use App\Domain\Documents\BillingIdentity;
use App\Domain\Documents\CustomerDocuments;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ManageDocumentsController extends OperationalController
{
    public function identity(BillingIdentity $identity)
    {
        return ['data' => $identity->current()];
    }

    public function saveIdentity(Request $http, BillingIdentity $identity)
    {
        $d = $http->validate([
            'version' => ['nullable', 'integer', 'min:1'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'trade_name' => ['required', 'string', 'max:100'],
            'legal_form' => ['nullable', 'string', 'max:100'],
            'niu' => ['nullable', 'string', 'max:40'],
            'rccm' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:300'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:254'],
            'tax_regime' => ['required', 'in:unset,vat,no_vat'],
            'vat_rate_basis_points' => ['nullable', 'required_if:tax_regime,vat', 'integer', 'min:1', 'max:5000'],
            'mentions' => ['required', 'array:fr,en'],
            'mentions.fr' => ['nullable', 'string', 'max:2000'],
            'mentions.en' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->command($http, 'billing.identity', $d, fn ($data) => $identity->save($data, $http->user()));
    }

    public function index(Request $http, CustomerDocuments $documents)
    {
        $d = $http->validate(['order_id' => ['nullable', 'uuid']]);

        return $this->page(
            DB::table('customer_documents')
                ->when($d['order_id'] ?? null, fn ($q, $id) => $q->where('order_id', $id))
                ->orderByDesc('created_at')
                ->orderBy('id'),
            $http,
            fn ($doc) => $documents->dto($doc),
        );
    }

    public function createInvoice(Request $http, string $id, CustomerDocuments $documents)
    {
        return $this->command(
            $http,
            'document.invoice:'.$id,
            [],
            fn () => $documents->createInvoice(Records::row('orders', $id), $http->user()),
            201,
        );
    }

    public function createCreditNote(Request $http, string $id, CustomerDocuments $documents)
    {
        return $this->command(
            $http,
            'document.credit_note:'.$id,
            [],
            function () use ($http, $id, $documents) {
                $orderId = DB::table('customer_refunds')->where('id', $id)->value('order_id');
                abort_unless($orderId, 404);
                // Same lock order as refunds: order, then refund.
                Records::row('orders', $orderId);

                return $documents->createCreditNote(Records::row('customer_refunds', $id), $http->user());
            },
            201,
        );
    }

    public function issue(Request $http, string $id, CustomerDocuments $documents)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command($http, 'document.issue:'.$id, $d, function ($data) use ($http, $id, $documents) {
            $orderId = DB::table('customer_documents')->where('id', $id)->value('order_id');
            abort_unless($orderId, 404);
            Records::row('orders', $orderId);
            $document = Records::row('customer_documents', $id);
            abort_unless((int) $document->version === $data['version'], 409);

            return $documents->issue($document, $http->user());
        });
    }

    /** Issued documents render their frozen snapshot; a draft renders a marked preview of what would be issued. */
    public function pdf(
        Request $http,
        string $id,
        CustomerDocuments $documents,
        DocumentRenderer $renderer,
        BillingIdentity $identity,
    ) {
        $locale = $http->validate(['locale' => ['nullable', 'in:fr,en']])['locale'] ?? app()->getLocale();
        $document = Records::row('customer_documents', $id, false);
        $draft = $document->status === 'draft';
        $current = $identity->current();
        $snapshot = $draft
            ? $documents->snapshot($document, $current, null, $current['missing'] !== [])
            : json_decode($document->snapshot, true);

        return response($renderer->pdf($snapshot, $locale, $draft), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.($document->number ?? 'brouillon').'-'.$locale.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
