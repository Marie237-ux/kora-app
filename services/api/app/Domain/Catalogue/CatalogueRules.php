<?php

namespace App\Domain\Catalogue;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CatalogueRules
{
    public function validate(string $entity, array $input, bool $publishing = false): array
    {
        $text = $publishing ? 'required' : 'nullable';
        $rules = match ($entity) {
            'suppliers' => [
                'name' => ['required', 'string', 'max:150'],
                'private_details' => ['required', 'array:contact_name,email,phone,address,legal_name'],
                'private_details.contact_name' => ['nullable', 'string', 'max:150'],
                'private_details.email' => ['nullable', 'email', 'max:254'],
                'private_details.phone' => ['nullable', 'string', 'max:40'],
                'private_details.address' => ['nullable', 'string', 'max:500'],
                'private_details.legal_name' => ['nullable', 'string', 'max:200'],
            ],
            'shops' => [
                'supplier_id' => ['required', 'uuid', Rule::exists('suppliers', 'id')->where('status', 'active')],
                'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                'name' => ['required', 'string', 'max:150'],
                'description' => ['required', 'array:fr,en'],
                'description.fr' => [$text, 'string', 'max:1500'],
                'description.en' => [$text, 'string', 'max:1500'],
                'city' => ['nullable', 'string', 'max:80'],
                'district' => ['nullable', 'string', 'max:120'],
                'avatar_path' => ['nullable', 'string', 'max:200'],
            ],
            'categories' => [
                'parent_id' => ['nullable', 'uuid', 'exists:categories,id'],
                'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                'name' => ['required', 'array:fr,en'],
                'name.fr' => [$text, 'string', 'max:100'],
                'name.en' => [$text, 'string', 'max:100'],
                'attributes' => ['present', 'array', 'max:40'],
                'attributes.*' => ['array:key,type,label,unit,required,filterable,options'],
                'attributes.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
                'attributes.*.type' => ['required', 'in:text,number,boolean,choice'],
                'attributes.*.label' => ['required', 'array:fr,en'],
                'attributes.*.label.fr' => ['required', 'string', 'max:100'],
                'attributes.*.label.en' => ['required', 'string', 'max:100'],
                'attributes.*.unit' => ['nullable', 'string', 'max:24'],
                'attributes.*.required' => ['required', 'boolean'],
                'attributes.*.filterable' => ['required', 'boolean'],
                'attributes.*.options' => ['present', 'array', 'max:100'],
                'attributes.*.options.*' => ['array:value,label'],
                'attributes.*.options.*.value' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
                'attributes.*.options.*.label' => ['required', 'array:fr,en'],
                'attributes.*.options.*.label.fr' => ['required', 'string', 'max:100'],
                'attributes.*.options.*.label.en' => ['required', 'string', 'max:100'],
            ],
            'products' => [
                'category_id' => ['required', 'uuid', 'exists:categories,id'],
                'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                'name' => ['required', 'array:fr,en'],
                'name.fr' => [$text, 'string', 'max:150'],
                'name.en' => [$text, 'string', 'max:150'],
                'description' => ['required', 'array:fr,en'],
                'description.fr' => [$text, 'string', 'max:10000'],
                'description.en' => [$text, 'string', 'max:10000'],
                'variants' => ['required', 'array', 'min:1', 'max:50'],
                'variants.*' => ['array:id,sku,attributes'],
                'variants.*.id' => ['nullable', 'uuid', 'distinct'],
                'variants.*.sku' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[a-zA-Z0-9_.-]+$/'],
                'variants.*.attributes' => ['present', 'array', 'max:80'],
            ],
            'offers' => [
                'shop_id' => ['required', 'uuid', 'exists:shops,id'],
                'variant_id' => ['required', 'uuid', 'exists:variants,id'],
                'reference_price' => ['required', 'integer', 'min:1', 'max:1000000000000'],
                'minimum_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
                'availability' => ['required', 'in:available,unconfirmed,unavailable'],
                'warranty' => ['required', 'array:fr,en'],
                'warranty.fr' => [$text, 'string', 'max:1500'],
                'warranty.en' => [$text, 'string', 'max:1500'],
                'image_path' => ['nullable', 'string', 'max:200'],
                'bulk_unit' => ['nullable', 'in:carton,lot,boite,sac,palette'],
                'bulk_unit_size' => ['nullable', 'required_with:bulk_unit', 'integer', 'min:2', 'max:100000'],
                'bulk_min_units' => ['nullable', 'required_with:bulk_unit', 'integer', 'min:1', 'max:100000'],
                'promotion' => [
                    'nullable',
                    'array:price,starts_at,ends_at,kora_funding,supplier_funding,contract_reference,negative_margin_reason',
                ],
                'promotion.price' => ['required_with:promotion', 'integer', 'min:1', 'max:1000000000000'],
                'promotion.starts_at' => ['required_with:promotion', 'date', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
                'promotion.ends_at' => [
                    'required_with:promotion',
                    'date',
                    'after:promotion.starts_at',
                    'regex:/T.*(Z|[+-]\d\d:\d\d)$/',
                ],
                'promotion.kora_funding' => ['required_with:promotion', 'integer', 'min:0', 'max:1000000000000'],
                'promotion.supplier_funding' => ['required_with:promotion', 'integer', 'min:0', 'max:1000000000000'],
                'promotion.contract_reference' => ['nullable', 'string', 'max:150'],
                'promotion.negative_margin_reason' => ['nullable', 'string', 'max:1000'],
            ],
            default => abort(404),
        };
        // Les champs inconnus ne sont jamais passés à une écriture en masse.
        $data = Validator::make($input, $rules)->validate();
        foreach (['name', 'description', 'warranty'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                foreach (['fr', 'en'] as $locale) {
                    $data[$field][$locale] = $data[$field][$locale] ?? '';
                }
            }
        }
        if ($entity === 'categories') {
            app(CategoryAttributes::class)->validateDefinitions($data['attributes']);
        }
        if ($entity === 'products') {
            foreach ($data['variants'] as &$variant) {
                $variant['id'] = $variant['id'] ?? (string) Str::uuid();
            }
            unset($variant);
        }
        if ($entity === 'offers' && ! empty($data['promotion'])) {
            $promotion = $data['promotion'];
            if (
                $promotion['price'] >= $data['reference_price'] ||
                $promotion['kora_funding'] + $promotion['supplier_funding'] !==
                    $data['reference_price'] - $promotion['price']
            ) {
                throw ValidationException::withMessages(['promotion' => __('catalogue.PROMOTION_FUNDING')]);
            }
            if ($promotion['supplier_funding'] > 0 && empty($promotion['contract_reference'])) {
                throw ValidationException::withMessages([
                    'promotion.contract_reference' => __('catalogue.SUPPLIER_FUNDING_AGREEMENT'),
                ]);
            }
        }

        return $data;
    }
}
