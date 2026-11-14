<?php

namespace App\Http\Controllers;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use Illuminate\Http\Request;

final class PrivateDocumentController extends OperationalController
{
    public function store(Request $request, PrivateDocuments $documents)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'purpose' => [
                'required',
                'in:supplier_contract,supplier_invoice,payment_evidence,stock_evidence,customer_payment_evidence,accounting_evidence',
            ],
            // Customer payment and manual accounting evidence concern no supplier.
            'supplier_id' => [
                'required_unless:purpose,customer_payment_evidence,accounting_evidence',
                'prohibited_if:purpose,customer_payment_evidence,accounting_evidence',
                'uuid',
                'exists:suppliers,id',
            ],
        ]);

        return response()->json(
            [
                'data' => $documents->upload(
                    $data['file'],
                    $data['purpose'],
                    $data['supplier_id'] ?? null,
                    $request->user(),
                ),
            ],
            201,
        );
    }

    public function show(Request $request, string $id, PrivateDocuments $documents)
    {
        $row = Records::row('private_documents', $id, false);
        $documents->authorize($request->user(), $row->purpose, false);

        return ['data' => $documents->dto($row)];
    }

    public function download(Request $request, string $id, PrivateDocuments $documents)
    {
        return $documents->download(Records::row('private_documents', $id, false), $request->user());
    }

    public function rescan(Request $request, string $id, PrivateDocuments $documents)
    {
        return ['data' => $documents->rescan(Records::row('private_documents', $id, false), $request->user())];
    }
}
