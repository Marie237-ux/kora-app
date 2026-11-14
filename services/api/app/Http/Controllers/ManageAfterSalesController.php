<?php

namespace App\Http\Controllers;

use App\Domain\AfterSales\AfterSales;
use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Support side: every case, internal notes, remedies, refunds, returns and their control. */
final class ManageAfterSalesController extends OperationalController
{
    public function index(Request $http, AfterSales $sav)
    {
        $d = $http->validate([
            'status' => ['nullable', 'in:open,in_review,proposed,accepted,declined,resolved,rejected'],
            'assignee_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return app(ReadSnapshot::class)->run(function () use ($http, $d, $sav) {
            $q = DB::table('after_sales_cases')->orderBy('created_at')->orderBy('id');
            foreach (['status', 'assignee_id'] as $field) {
                if (isset($d[$field])) {
                    $q->where($field, $d[$field]);
                }
            }

            return $this->page($q, $http, fn ($c) => $sav->staffView($c));
        });
    }

    public function show(string $id, AfterSales $sav)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $sav->staffView(Records::row('after_sales_cases', $id, false)),
            ),
        ];
    }

    public function attachment(Request $http, string $id, string $messageId)
    {
        $message = DB::table('after_sales_messages')->where('id', $messageId)->where('case_id', $id)->first();
        abort_unless($message && $message->document_id, 404);

        return app(PrivateDocuments::class)->download(
            Records::row('private_documents', $message->document_id, false),
            $http->user(),
        );
    }

    public function message(Request $http, string $id, AfterSales $sav)
    {
        $data = $http->validate([
            'body' => ['required', 'string', 'max:4000'],
            'visibility' => ['required', 'in:customer,internal'],
        ]);

        return $this->command(
            $http,
            'after_sales.staff_message:'.$id,
            $data,
            fn ($d) => $sav->message($id, $d, $http->user(), true),
            201,
        );
    }

    public function assign(Request $http, string $id, AfterSales $sav)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'assignee_id' => ['required', 'integer', 'min:1'],
        ]);

        return $this->command(
            $http,
            'after_sales.assign:'.$id,
            $data,
            fn ($d) => $sav->assign($id, $d, $http->user()),
        );
    }

    public function propose(Request $http, string $id, AfterSales $sav)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'resolution' => ['required', 'in:'.implode(',', AfterSales::RESOLUTIONS)],
            'amount' => ['nullable', 'integer', 'min:1', 'max:1000000000000'],
            'responsibility' => ['required', 'in:'.implode(',', AfterSales::RESPONSIBILITIES)],
            'responsibility_note' => ['required', 'string', 'max:4000'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        return $this->command(
            $http,
            'after_sales.propose:'.$id,
            $data,
            fn ($d) => $sav->propose($id, $d, $http->user()),
        );
    }

    public function reject(Request $http, string $id, AfterSales $sav)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        return $this->command(
            $http,
            'after_sales.reject:'.$id,
            $data,
            fn ($d) => $sav->reject($id, $d, $http->user()),
        );
    }

    public function refund(Request $http, string $id, AfterSales $sav)
    {
        $data = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command(
            $http,
            'after_sales.refund:'.$id,
            $data,
            fn ($d) => $sav->refund($id, $d, $http->user()),
        );
    }

    public function receiveReturn(Request $http, string $id, AfterSales $sav)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'location_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'identifiers' => ['present', 'array', 'max:1000'],
            'identifiers.*' => ['string', 'max:100', 'distinct'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'after_sales.return:'.$id,
            $data,
            fn ($d) => $sav->receiveReturn($id, $d, $http->user()),
            201,
        );
    }

    public function control(Request $http, string $id, AfterSales $sav)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'result' => ['required', 'in:resellable,defective'],
            'location_id' => ['nullable', 'uuid'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'after_sales.control:'.$id,
            $data,
            fn ($d) => $sav->control($id, $d, $http->user()),
        );
    }

    public function resolve(Request $http, string $id, AfterSales $sav)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        return $this->command(
            $http,
            'after_sales.resolve:'.$id,
            $data,
            fn ($d) => $sav->resolve($id, $d, $http->user()),
        );
    }
}
