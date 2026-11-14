<?php

namespace App\Domain\Content;

use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Media\MediaLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SceneContent
{
    public static function rules(bool $publishing = false): array
    {
        $required = $publishing ? 'required' : 'nullable';

        return [
            'kind' => ['required', Rule::in(['product', 'collection', 'shop', 'campaign'])],
            'target_id' => ['nullable', 'uuid'],
            'category' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'title' => ['required', 'array:fr,en'],
            'description' => ['required', 'array:fr,en'],
            'label' => ['required', 'array:fr,en'],
            'cta' => ['required', 'array:fr,en'],
            'alt' => ['required', 'array:fr,en'],
            'title.fr' => [$required, 'string', 'max:100'],
            'title.en' => [$required, 'string', 'max:100'],
            'description.fr' => [$required, 'string', 'max:300'],
            'description.en' => [$required, 'string', 'max:300'],
            'label.fr' => [$required, 'string', 'max:40'],
            'label.en' => [$required, 'string', 'max:40'],
            'cta.fr' => [$required, 'string', 'max:50'],
            'cta.en' => [$required, 'string', 'max:50'],
            'alt.fr' => [$required, 'string', 'max:160'],
            'alt.en' => [$required, 'string', 'max:160'],
            'media' => [
                $required,
                'string',
                'regex:~^(/brand/logo\\.png|/api/v1/media/[a-f0-9-]{36}/public|/media/[a-zA-Z0-9_/-]+\\.(png|webp|avif|jpg))$~',
                'max:200',
            ],
            'mobile_media' => [
                'nullable',
                'string',
                'regex:~^(/brand/logo\\.png|/api/v1/media/[a-f0-9-]{36}/public|/media/[a-zA-Z0-9_/-]+\\.(png|webp|avif|jpg))$~',
                'max:200',
            ],
            'decor' => ['required', Rule::in(['bands', 'circle', 'arch', 'none'])],
            'preset' => ['required', Rule::in(['signature', 'quiet'])],
            'hold_ms' => ['required', 'integer', 'min:8000', 'max:30000'],
            'autoplay' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'priority' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function validatePublication(array $content): array
    {
        $data = Validator::make($content, self::rules(true))->validate();
        foreach (['media', 'mobile_media'] as $field) {
            if (! empty($data[$field])) {
                Validator::make(
                    [$field => $data[$field]],
                    [
                        $field => [
                            function ($attribute, $value, $fail) {
                                if (! app(MediaLibrary::class)->approvedPath($value)) {
                                    $fail(__('api.MEDIA_UNAVAILABLE'));
                                }
                            },
                        ],
                    ],
                )->validate();
            }
        }
        if ($data['kind'] === 'product') {
            Validator::make($data, [
                'target_id' => ['required', 'uuid', Rule::exists('offers', 'id')->where('status', 'published')],
            ])->validate();
            abort_unless(app(PublicCatalogue::class)->offer($data['target_id'], 'fr'), 422);
        }
        if ($data['kind'] === 'shop') {
            Validator::make($data, [
                'target_id' => ['required', Rule::exists('shops', 'id')->where('published', true)],
            ])->validate();
        }
        if (! empty($data['category'])) {
            Validator::make($data, [
                'category' => [Rule::exists('categories', 'slug')->where('published', true)],
            ])->validate();
        }

        return $data;
    }

    public function publicScene(string $id, array $content, string $locale): ?array
    {
        $library = app(MediaLibrary::class);
        if (! empty($content['media']) && ! $library->approvedPath($content['media'])) {
            return null;
        }
        if (! empty($content['mobile_media']) && ! $library->approvedPath($content['mobile_media'])) {
            $content['mobile_media'] = null;
        }
        if (
            ! empty($content['category']) &&
            ! DB::table('categories')->where('slug', $content['category'])->where('published', true)->exists()
        ) {
            return null;
        }
        $offer = null;
        $shop = null;
        if ($content['kind'] === 'product') {
            $offer = app(PublicCatalogue::class)->offer($content['target_id'] ?? '', $locale);
            if (! $offer || $offer['availability'] === 'unavailable') {
                return null;
            }
        }
        if ($content['kind'] === 'shop') {
            $row = DB::table('shops')
                ->where('id', $content['target_id'])
                ->where('published', true)
                ->first(['slug', 'name']);
            if (! $row) {
                return null;
            }
            $shop = ['slug' => $row->slug, 'name' => $row->name];
        }
        $href = $offer ? '/'.$locale.'/products/'.$offer['id'] : '/'.$locale.'/catalogue';
        if ($shop) {
            $href = '/'.$locale.'/shops/'.$shop['slug'];
        }
        if (! $offer && ! $shop && ! empty($content['category'])) {
            $href .= '?category='.urlencode($content['category']);
        }

        return [
            'id' => $id,
            'kind' => $content['kind'],
            'title' => $content['title'][$locale] ?? '',
            'description' => $content['description'][$locale] ?? '',
            'label' => $content['label'][$locale] ?? '',
            'cta' => $content['cta'][$locale] ?? '',
            'alt' => $content['alt'][$locale] ?? '',
            'media' => $content['media'] ?? null,
            'mobile_media' => $content['mobile_media'] ?? null,
            'decor' => $content['decor'],
            'preset' => $content['preset'],
            'hold_ms' => $content['hold_ms'],
            'autoplay' => $content['autoplay'],
            'href' => $href,
            'offer' => $offer,
            'shop' => $shop,
        ];
    }
}
