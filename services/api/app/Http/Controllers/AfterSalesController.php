<?php

namespace App\Http\Controllers;

use App\Domain\AfterSales\AfterSales;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Customer side: own purchases, warranties and after-sales cases only. */
final class AfterSalesController extends OperationalController
{
    public function purchases(Request $http, AfterSales $sav)
    {
        return ['data' => app(ReadSnapshot::class)->run(fn () => $sav->purchases($http->user()))];
    }

    public function index(Request $http, AfterSales $sav)
    {
        return app(ReadSnapshot::class)->run(
            fn () => $this->page(
                DB::table('after_sales_cases')
                    ->where('user_id', $http->user()->id)
                    ->orderByDesc('created_at')
                    ->orderBy('id'),
                $http,
                fn ($c) => $sav->customerView($c),
            ),
        );
    }

    public function show(Request $http, string $id, AfterSales $sav)
    {
        return ['data' => app(ReadSnapshot::class)->run(fn () => $sav->customerView($sav->owned($id, $http->user())))];
    }

    public function open(Request $http, AfterSales $sav)
    {
        $data = $http->validate([
            'order_line_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:'.implode(',', AfterSales::KINDS)],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'identifiers' => ['present', 'array', 'max:1000'],
            'identifiers.*' => ['string', 'max:100', 'distinct'],
            'description' => ['required', 'string', 'max:4000'],
        ]);

        return $this->command($http, 'after_sales.open', $data, fn ($d) => $sav->open($d, $http->user()), 201);
    }

    public function message(Request $http, string $id, AfterSales $sav)
    {
        $data = $http->validate(['body' => ['required', 'string', 'max:4000']]);

        return $this->command(
            $http,
            'after_sales.customer_message:'.$id,
            $data,
            fn ($d) => $sav->message($id, $d, $http->user(), false),
            201,
        );
    }

    public function attach(Request $http, string $id, AfterSales $sav)
    {
        $data = $http->validate(['file' => ['required', 'file', 'max:10240']]);

        return response()->json(['data' => $sav->attach($id, $data['file'], $http->user())], 201);
    }

    public function decide(Request $http, string $id, AfterSales $sav)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'accept' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);

        return $this->command(
            $http,
            'after_sales.decide:'.$id,
            $data,
            fn ($d) => $sav->decide($id, $d, $http->user()),
        );
    }
}
