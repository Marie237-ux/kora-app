<?php

namespace App\Http\Controllers;

use App\Domain\Documents\CustomerDocuments;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Orders\Orders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A customer sees only the issued documents of their own orders; access is checked on every download. */
final class CustomerDocumentsController extends Controller
{
    public function index(Request $http, string $id, Orders $orders, CustomerDocuments $documents)
    {
        $orders->owned($id, $http->user());

        return [
            'data' => DB::table('customer_documents')
                ->where('order_id', $id)
                ->where('status', 'issued')
                ->orderBy('issued_at')
                ->get()
                ->map(fn ($d) => $documents->dto($d)),
        ];
    }

    public function pdf(Request $http, string $id, string $documentId, Orders $orders, DocumentRenderer $renderer)
    {
        $locale = $http->validate(['locale' => ['nullable', 'in:fr,en']])['locale'] ?? app()->getLocale();
        $orders->owned($id, $http->user());
        $document = DB::table('customer_documents')
            ->where('id', $documentId)
            ->where('order_id', $id)
            ->where('status', 'issued')
            ->first();
        abort_unless($document, 404);

        return response($renderer->pdf(json_decode($document->snapshot, true), $locale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->number.'-'.$locale.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
