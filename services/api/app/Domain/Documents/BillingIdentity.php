<?php

namespace App\Domain\Documents;

use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Identity of the real operator printed on customer documents. Nothing is assumed: until the legal name,
 * identifiers, address and tax regime are filled in, every document is a clearly marked demonstration with its
 * own numbering, and no VAT is ever charged by default.
 */
final class BillingIdentity
{
    private const REQUIRED = ['legal_name', 'niu', 'rccm', 'address', 'city'];

    public function current(): array
    {
        $row = DB::table('business_settings')->where('key', 'billing')->first();
        $value = $row ? json_decode($row->value, true) : [];

        return [
            'version' => $row ? (int) $row->version : null,
            'legal_name' => $value['legal_name'] ?? null,
            'trade_name' => $value['trade_name'] ?? 'KORA',
            'legal_form' => $value['legal_form'] ?? null,
            'niu' => $value['niu'] ?? null,
            'rccm' => $value['rccm'] ?? null,
            'address' => $value['address'] ?? null,
            'city' => $value['city'] ?? null,
            'phone' => $value['phone'] ?? null,
            'email' => $value['email'] ?? null,
            'tax_regime' => $value['tax_regime'] ?? 'unset',
            'vat_rate_basis_points' => $value['vat_rate_basis_points'] ?? null,
            'mentions' => $value['mentions'] ?? ['fr' => '', 'en' => ''],
            'missing' => $this->missing($value),
        ];
    }

    /** Fields still needed before documents can carry real numbers. */
    public function missing(array $value): array
    {
        $missing = array_values(array_filter(self::REQUIRED, fn ($field) => blank($value[$field] ?? null)));
        if (($value['tax_regime'] ?? 'unset') === 'unset') {
            $missing[] = 'tax_regime';
        }

        return $missing;
    }

    public function save(array $data, User $actor): array
    {
        $row = DB::table('business_settings')->where('key', 'billing')->lockForUpdate()->first();
        abort_unless($row ? (int) $row->version === ($data['version'] ?? null) : empty($data['version']), 409);
        $value = array_intersect_key(
            $data,
            array_flip([
                'legal_name',
                'trade_name',
                'legal_form',
                'niu',
                'rccm',
                'address',
                'city',
                'phone',
                'email',
                'tax_regime',
                'vat_rate_basis_points',
                'mentions',
            ]),
        );
        if ($value['tax_regime'] !== 'vat') {
            $value['vat_rate_basis_points'] = null;
        }
        $version = $row ? $row->version + 1 : 1;
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'billing'],
            [
                'value' => Records::json($value),
                'version' => $version,
                'updated_by' => $actor->id,
                'created_at' => $row?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
        // Issued documents keep their own frozen copy: this change only affects documents issued from now on.
        Records::audit($actor, 'billing.identity_updated', 'billing', ['version' => $version]);

        return $this->current();
    }
}
