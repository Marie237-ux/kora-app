<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Couriers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CouriersController extends OperationalController
{
    public function index(Request $http, Couriers $couriers)
    {
        $d = $http->validate(['active' => ['nullable', 'boolean']]);
        $q = DB::table('courier_profiles')->orderBy('public_name')->orderBy('user_id');
        if (isset($d['active'])) {
            $q->where('active', (bool) $d['active']);
        }

        return $this->page($q, $http, fn ($p) => $couriers->dto($p));
    }

    public function register(Request $http, Couriers $couriers)
    {
        $data = $http->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'public_name' => ['required', 'string', 'max:80'],
            'badge' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        return $this->command($http, 'courier.register', $data, fn ($d) => $couriers->register($d, $http->user()), 201);
    }

    public function update(Request $http, int $id, Couriers $couriers)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'public_name' => ['required', 'string', 'max:80'],
            'active' => ['required', 'boolean'],
        ]);

        return $this->command(
            $http,
            'courier.update:'.$id,
            $data,
            fn ($d) => $couriers->update($id, $d, $http->user()),
        );
    }
}
