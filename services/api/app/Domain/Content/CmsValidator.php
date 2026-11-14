<?php

namespace App\Domain\Content;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approved shapes for site settings, page blocks and campaign themes. Text is plain (no angle brackets, so no
 * HTML or script), links are internal paths or https, colours are hex tokens with a readable contrast (WCAG AA
 * 4.5:1 for text on its background), fonts come from the approved list, referenced records must exist.
 */
final class CmsValidator
{
    public const BLOCKS = [
        'product_selection',
        'shop_selection',
        'image_text',
        'category',
        'service_message',
        'banner',
        'editorial',
        'faq',
    ];

    public const FONTS = ['kora-display', 'kora-text', 'system'];

    private array $errors = [];

    private function error(string $path, string $code): void
    {
        $this->errors[$path][] = __('cms.'.$code);
    }

    private function done(): void
    {
        if ($this->errors) {
            $errors = $this->errors;
            $this->errors = [];
            throw ValidationException::withMessages($errors);
        }
    }

    private function text(string $path, mixed $value, int $max, bool $required = true): void
    {
        if ($value === null || $value === '') {
            if ($required) {
                $this->error($path, 'TEXT_REQUIRED');
            }

            return;
        }
        if (! is_string($value) || mb_strlen($value) > $max) {
            $this->error($path, 'TEXT_INVALID');
        } elseif (preg_match('/[<>]/', $value)) {
            $this->error($path, 'TEXT_MARKUP');
        }
    }

    private function translated(string $path, mixed $value, int $max, bool $required = true): void
    {
        if (! is_array($value) || array_diff(array_keys($value), ['fr', 'en'])) {
            $this->error($path, 'TRANSLATION_INVALID');

            return;
        }
        foreach (['fr', 'en'] as $locale) {
            $this->text($path.'.'.$locale, $value[$locale] ?? null, $max, $required);
        }
    }

    private function link(string $path, mixed $value, bool $required = false): void
    {
        if ($value === null || $value === '') {
            if ($required) {
                $this->error($path, 'LINK_INVALID');
            }

            return;
        }
        $internal = is_string($value) && preg_match('#^/(?!/)[A-Za-z0-9/_\-.?=&%]*$#', $value);
        $https = is_string($value) && str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL);
        if (! $internal && ! $https) {
            $this->error($path, 'LINK_INVALID');
        }
    }

    private function exists(string $path, string $table, mixed $id): void
    {
        if (
            ! is_string($id) ||
            ! preg_match('/^[0-9a-f-]{36}$/i', $id) ||
            ! DB::table($table)->where('id', $id)->exists()
        ) {
            $this->error($path, 'REFERENCE_UNKNOWN');
        }
    }

    private function ids(string $path, mixed $ids, string $table, int $max): void
    {
        if (
            ! is_array($ids) ||
            ! array_is_list($ids) ||
            count($ids) < 1 ||
            count($ids) > $max ||
            count(array_unique($ids)) !== count($ids)
        ) {
            $this->error($path, 'SELECTION_INVALID');

            return;
        }
        foreach ($ids as $i => $id) {
            $this->exists($path.'.'.$i, $table, $id);
        }
    }

    private function keys(string $path, mixed $value, array $allowed): bool
    {
        if (! is_array($value) || array_diff(array_keys($value), $allowed)) {
            $this->error($path, 'SHAPE_INVALID');

            return false;
        }

        return true;
    }

    public function settings(array $c): array
    {
        if (
            $this->keys('content', $c, [
                'company',
                'logo_media_id',
                'logo_dark_media_id',
                'favicon_media_id',
                'theme',
                'fonts',
                'menus',
                'social',
            ])
        ) {
            if (
                $this->keys('content.company', $c['company'] ?? null, [
                    'name',
                    'tagline',
                    'phone',
                    'email',
                    'address',
                    'hours',
                ])
            ) {
                $this->text('content.company.name', $c['company']['name'] ?? null, 100);
                $this->translated(
                    'content.company.tagline',
                    $c['company']['tagline'] ?? ['fr' => '', 'en' => ''],
                    200,
                    false,
                );
                $this->text('content.company.phone', $c['company']['phone'] ?? null, 40, false);
                if (! empty($c['company']['email']) && ! filter_var($c['company']['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->error('content.company.email', 'TEXT_INVALID');
                }
                $this->text('content.company.address', $c['company']['address'] ?? null, 300, false);
                $this->translated(
                    'content.company.hours',
                    $c['company']['hours'] ?? ['fr' => '', 'en' => ''],
                    300,
                    false,
                );
            }
            foreach (['logo_media_id', 'logo_dark_media_id', 'favicon_media_id'] as $key) {
                if (! empty($c[$key])) {
                    $this->exists('content.'.$key, 'media_assets', $c[$key]);
                }
            }
            $this->theme('content.theme', $c['theme'] ?? null, true);
            if ($this->keys('content.fonts', $c['fonts'] ?? null, ['heading', 'body'])) {
                foreach (['heading', 'body'] as $key) {
                    if (! in_array($c['fonts'][$key] ?? null, self::FONTS, true)) {
                        $this->error('content.fonts.'.$key, 'FONT_NOT_APPROVED');
                    }
                }
            }
            if ($this->keys('content.menus', $c['menus'] ?? [], ['header', 'footer'])) {
                foreach (['header', 'footer'] as $menu) {
                    foreach (array_values($c['menus'][$menu] ?? []) as $i => $item) {
                        if ($this->keys("content.menus.$menu.$i", $item, ['label', 'href'])) {
                            $this->translated("content.menus.$menu.$i.label", $item['label'] ?? null, 60);
                            $this->link("content.menus.$menu.$i.href", $item['href'] ?? null, true);
                        }
                    }
                }
            }
            foreach (array_values($c['social'] ?? []) as $i => $item) {
                if ($this->keys("content.social.$i", $item, ['network', 'url'])) {
                    $this->text("content.social.$i.network", $item['network'] ?? null, 40);
                    if (! str_starts_with((string) ($item['url'] ?? ''), 'https://')) {
                        $this->error("content.social.$i.url", 'LINK_INVALID');
                    }
                }
            }
        }
        $this->done();

        return $c;
    }

    /** Light and dark token sets; partial overrides allowed for campaigns, then contrast is checked on the result. */
    public function theme(string $path, mixed $theme, bool $complete, ?array $base = null): void
    {
        $tokens = ['primary', 'on_primary', 'background', 'text', 'accent'];
        if (! $this->keys($path, $theme, ['light', 'dark'])) {
            return;
        }
        foreach (['light', 'dark'] as $mode) {
            $set = $theme[$mode] ?? [];
            if (! $this->keys("$path.$mode", $set, $tokens)) {
                continue;
            }
            foreach ($set as $token => $value) {
                if (! is_string($value) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                    $this->error("$path.$mode.$token", 'COLOR_INVALID');
                }
            }
            $merged = [...$base[$mode] ?? [], ...$set];
            if ($complete && array_diff($tokens, array_keys($merged))) {
                $this->error("$path.$mode", 'THEME_INCOMPLETE');

                continue;
            }
            foreach ([['text', 'background'], ['on_primary', 'primary']] as [$fg, $bg]) {
                $hex = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v);
                if (
                    $hex($merged[$fg] ?? null) &&
                    $hex($merged[$bg] ?? null) &&
                    $this->contrast($merged[$fg], $merged[$bg]) < 4.5
                ) {
                    $this->error("$path.$mode.$fg", 'CONTRAST_TOO_LOW');
                }
            }
        }
    }

    public function contrast(string $a, string $b): float
    {
        $l = function (string $hex): float {
            $c = array_map(fn ($i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);
            $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$x, $y] = [$l($a), $l($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    public function page(array $c): array
    {
        if ($this->keys('content', $c, ['blocks'])) {
            $blocks = $c['blocks'] ?? null;
            if (! is_array($blocks) || ! array_is_list($blocks) || count($blocks) > 40) {
                $this->error('content.blocks', 'SHAPE_INVALID');
            } else {
                foreach ($blocks as $i => $block) {
                    $this->block("content.blocks.$i", $block);
                }
            }
        }
        $this->done();

        return $c;
    }

    private function block(string $p, mixed $b): void
    {
        $type = is_array($b) ? $b['type'] ?? null : null;
        if (! in_array($type, self::BLOCKS, true)) {
            $this->error($p.'.type', 'BLOCK_UNKNOWN');

            return;
        }
        $shape = [
            'product_selection' => ['type', 'title', 'product_ids'],
            'shop_selection' => ['type', 'title', 'shop_ids'],
            'image_text' => ['type', 'media_id', 'title', 'text', 'link'],
            'category' => ['type', 'category_id', 'title'],
            'service_message' => ['type', 'text', 'tone'],
            'banner' => ['type', 'media_id', 'text', 'link', 'title', 'cta'],
            'editorial' => ['type', 'title', 'paragraphs'],
            'faq' => ['type', 'items'],
        ][$type];
        if (! $this->keys($p, $b, $shape)) {
            return;
        }
        match ($type) {
            'product_selection' => [
                $this->translated("$p.title", $b['title'] ?? null, 120),
                $this->ids("$p.product_ids", $b['product_ids'] ?? null, 'products', 24),
            ],
            'shop_selection' => [
                $this->translated("$p.title", $b['title'] ?? null, 120),
                $this->ids("$p.shop_ids", $b['shop_ids'] ?? null, 'shops', 12),
            ],
            'image_text' => [
                $this->exists("$p.media_id", 'media_assets', $b['media_id'] ?? null),
                $this->translated("$p.title", $b['title'] ?? null, 120),
                $this->translated("$p.text", $b['text'] ?? null, 600),
                $this->link("$p.link", $b['link'] ?? null),
            ],
            'category' => [
                $this->exists("$p.category_id", 'categories', $b['category_id'] ?? null),
                $this->translated("$p.title", $b['title'] ?? ['fr' => '', 'en' => ''], 120, false),
            ],
            'service_message' => [
                $this->translated("$p.text", $b['text'] ?? null, 300),
                in_array($b['tone'] ?? null, ['info', 'warning', 'success'], true) ?:
                $this->error("$p.tone", 'SHAPE_INVALID'),
            ],
            'banner' => [
                $this->exists("$p.media_id", 'media_assets', $b['media_id'] ?? null),
                $this->translated("$p.text", $b['text'] ?? null, 200),
                $this->link("$p.link", $b['link'] ?? null),
                // Optional: small label above the title ("À la une") and the button text ("Découvrir").
                isset($b['title']) ? $this->translated("$p.title", $b['title'], 40, false) : null,
                isset($b['cta']) ? $this->translated("$p.cta", $b['cta'], 30, false) : null,
            ],
            'editorial' => $this->editorial($p, $b),
            'faq' => $this->faq($p, $b['items'] ?? null),
        };
    }

    private function editorial(string $p, array $b): void
    {
        $this->translated("$p.title", $b['title'] ?? null, 160);
        $paragraphs = $b['paragraphs'] ?? null;
        if (! $this->keys("$p.paragraphs", $paragraphs, ['fr', 'en'])) {
            return;
        }
        foreach (['fr', 'en'] as $locale) {
            $list = $paragraphs[$locale] ?? null;
            if (! is_array($list) || ! array_is_list($list) || count($list) < 1 || count($list) > 30) {
                $this->error("$p.paragraphs.$locale", 'SHAPE_INVALID');

                continue;
            }
            foreach ($list as $i => $text) {
                $this->text("$p.paragraphs.$locale.$i", $text, 2000);
            }
        }
    }

    private function faq(string $p, mixed $items): void
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) < 1 || count($items) > 50) {
            $this->error("$p.items", 'SHAPE_INVALID');

            return;
        }
        foreach ($items as $i => $item) {
            if ($this->keys("$p.items.$i", $item, ['q', 'a'])) {
                $this->translated("$p.items.$i.q", $item['q'] ?? null, 200);
                $this->translated("$p.items.$i.a", $item['a'] ?? null, 1500);
            }
        }
    }

    /** Media shown publicly must already be public in the media library. */
    public function publicMedia(array $content): void
    {
        $ids = [];
        array_walk_recursive($content, function ($value, $key) use (&$ids) {
            if (is_string($key) && str_ends_with($key, 'media_id') && $value) {
                $ids[] = $value;
            }
        });
        foreach (array_unique($ids) as $id) {
            if (
                ! DB::table('media_assets')
                    ->where('id', $id)
                    ->where('is_public', true)
                    ->where('revoked', false)
                    ->exists()
            ) {
                $this->error('content', 'MEDIA_NOT_PUBLIC');
            }
        }
        $this->done();
    }

    public function campaignTheme(mixed $theme, array $base): void
    {
        $this->theme('theme', $theme, false, $base);
        $this->done();
    }
}
