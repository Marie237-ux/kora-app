<?php

namespace App\Domain\Boards;

use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Boards
{
    public function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('boards.'.$code)]);
    }

    public function role(object $board, User $actor): ?string
    {
        if ((int) $board->owner_id === $actor->id) {
            return 'owner';
        }

        return DB::table('board_members')
            ->where('board_id', $board->id)
            ->where('user_id', $actor->id)
            ->whereNull('revoked_at')
            ->value('role');
    }

    public function access(
        string $id,
        User $actor,
        bool $write = false,
        bool $owner = false,
        bool $lock = false,
        bool $allowArchived = false,
    ): object {
        $q = DB::table('boards')->where('id', $id);
        if (! $allowArchived) {
            $q->whereNull('archived_at');
        }
        if ($lock) {
            $q->lockForUpdate();
        }
        $board = $q->first();
        abort_unless($board, 404);
        $role = $this->role($board, $actor);
        abort_unless($role, 404);
        abort_if(($owner && $role !== 'owner') || ($write && $role === 'viewer'), 403);

        return $board;
    }

    public function publicAccess(string $id, ?string $token = null): object
    {
        $board = DB::table('boards')->where('id', $id)->whereNull('archived_at')->first();
        abort_unless($board, 404);
        $linkValid =
            $board->visibility === 'unlisted' &&
            $token &&
            $board->share_token_hash &&
            $board->share_expires_at &&
            now()->lessThan($board->share_expires_at) &&
            hash_equals($board->share_token_hash, hash('sha256', $token));
        abort_unless($token !== null ? $linkValid : $board->visibility === 'public', 404);

        return $board;
    }

    public function version(object $board, int $version): void
    {
        abort_unless((int) $board->version === $version, 409);
    }

    public function change(object $board, User $actor, string $action, array $changes = [], array $data = []): object
    {
        DB::table('boards')
            ->where('id', $board->id)
            ->update([...$changes, 'version' => $board->version + 1, 'updated_at' => now()]);
        $next = Records::row('boards', $board->id);
        $this->event($next, $actor, $action, $data);

        return $next;
    }

    private function event(object $board, User $actor, string $action, array $data = []): void
    {
        DB::table('board_events')->insert([
            'board_id' => $board->id,
            'version' => $board->version,
            'actor_id' => $actor->id,
            'action' => $action,
            'data' => Records::json($data),
            'created_at' => now(),
        ]);
    }

    public function create(array $data, User $actor): array
    {
        DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
        if (DB::table('boards')->where('owner_id', $actor->id)->whereNull('archived_at')->count() >= 100) {
            $this->fail('boards', 'BOARD_LIMIT');
        }
        $id = Records::id();
        DB::table('boards')->insert([
            'id' => $id,
            'owner_id' => $actor->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? '',
            'kind' => $data['kind'],
            'gift_date' => $data['kind'] === 'gift' ? $data['gift_date'] ?? null : null,
            'hide_gift_purchases' => $data['hide_gift_purchases'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $board = Records::row('boards', $id);
        $this->event($board, $actor, 'created');

        return $this->dto($board, $actor);
    }

    public function update(object $board, array $data, User $actor): array
    {
        if (! empty($data['cover_pin_id'])) {
            $this->pin($board, $data['cover_pin_id']);
        }
        $changes = array_intersect_key(
            $data,
            array_flip(['title', 'description', 'kind', 'gift_date', 'hide_gift_purchases', 'cover_pin_id']),
        );
        if (($changes['kind'] ?? $board->kind) !== 'gift') {
            $changes['gift_date'] = null;
        }

        return $this->dto($this->change($board, $actor, 'updated', $changes), $actor);
    }

    public function section(object $board, ?string $id): ?object
    {
        if (! $id) {
            return null;
        }
        $section = DB::table('board_sections')->where('board_id', $board->id)->where('id', $id)->first();
        abort_unless($section, 404);

        return $section;
    }

    public function pin(object $board, string $id): object
    {
        $pin = DB::table('board_pins')->where('board_id', $board->id)->where('id', $id)->first();
        abort_unless($pin, 404);

        return $pin;
    }

    public function addSection(object $board, string $title, User $actor): array
    {
        if (DB::table('board_sections')->where('board_id', $board->id)->count() >= 30) {
            $this->fail('sections', 'SECTION_LIMIT');
        }
        $id = Records::id();
        $position = (int) DB::table('board_sections')->where('board_id', $board->id)->max('position') + 1;
        DB::table('board_sections')->insert([
            'id' => $id,
            'board_id' => $board->id,
            'title' => $title,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->dto($this->change($board, $actor, 'section_added', [], ['section_id' => $id]), $actor);
    }

    public function removeSection(object $board, string $id, User $actor): array
    {
        $this->section($board, $id);
        DB::table('board_pins')
            ->where('section_id', $id)
            ->update(['section_id' => null, 'updated_at' => now()]);
        DB::table('board_sections')->where('id', $id)->delete();

        return $this->dto($this->change($board, $actor, 'section_removed', [], ['section_id' => $id]), $actor);
    }

    public function addPin(object $board, array $data, User $actor): array
    {
        $existing = DB::table('board_pins')
            ->where('board_id', $board->id)
            ->where('offer_id', $data['offer_id'])
            ->first();
        if ($existing) {
            return ['board' => $this->dto($board, $actor), 'pin_id' => $existing->id, 'already_pinned' => true];
        }
        $this->section($board, $data['section_id'] ?? null);
        if (DB::table('board_pins')->where('board_id', $board->id)->count() >= 500) {
            $this->fail('pins', 'PIN_LIMIT');
        }
        $offer = app(PublicCatalogue::class)->offer($data['offer_id'], app()->getLocale());
        if (! $offer) {
            $this->fail('offer_id', 'OFFER_UNAVAILABLE');
        }
        $product = DB::table('offers')
            ->join('variants', 'variants.id', '=', 'offers.variant_id')
            ->join('products', 'products.id', '=', 'variants.product_id')
            ->where('offers.id', $data['offer_id'])
            ->first(['products.name', 'variants.sku']);
        $id = Records::id();
        $position = (int) DB::table('board_pins')->where('board_id', $board->id)->max('position') + 1;
        DB::table('board_pins')->insert([
            'id' => $id,
            'board_id' => $board->id,
            'section_id' => $data['section_id'] ?? null,
            'offer_id' => $data['offer_id'],
            'position' => $position,
            'wished_quantity' => $data['wished_quantity'],
            'caption' => $data['caption'] ?? '',
            'saved_offer' => Records::json(['name' => json_decode($product->name, true), 'sku' => $product->sku]),
            'saved_unit_price' => $offer['price']['amount'],
            'price_saved_at' => now(),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->note($id, $actor, $data['personal_note'] ?? '');

        return [
            'board' => $this->dto($this->change($board, $actor, 'pin_added', [], ['pin_id' => $id]), $actor),
            'pin_id' => $id,
            'already_pinned' => false,
        ];
    }

    private function note(string $id, User $actor, string $body): void
    {
        if ($body === '') {
            DB::table('board_pin_notes')->where('pin_id', $id)->where('user_id', $actor->id)->delete();

            return;
        }
        DB::table('board_pin_notes')->updateOrInsert(
            ['pin_id' => $id, 'user_id' => $actor->id],
            ['body' => $body, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function editPin(object $board, string $id, array $data, User $actor): array
    {
        $this->pin($board, $id);
        $this->section($board, $data['section_id']);
        DB::table('board_pins')
            ->where('id', $id)
            ->update([
                'section_id' => $data['section_id'],
                'wished_quantity' => $data['wished_quantity'],
                'caption' => $data['caption'] ?? '',
                'updated_at' => now(),
            ]);
        if (array_key_exists('personal_note', $data)) {
            $this->note($id, $actor, $data['personal_note'] ?? '');
        }

        return $this->dto($this->change($board, $actor, 'pin_updated', [], ['pin_id' => $id]), $actor);
    }

    public function removePin(object $board, string $id, User $actor): array
    {
        $this->pin($board, $id);
        DB::table('board_pins')->where('id', $id)->delete();

        return $this->dto($this->change($board, $actor, 'pin_removed', [], ['pin_id' => $id]), $actor);
    }

    public function reorder(object $board, string $kind, array $ids, ?string $sectionId, User $actor): array
    {
        $table = $kind === 'sections' ? 'board_sections' : 'board_pins';
        if ($kind === 'pins') {
            $this->section($board, $sectionId);
        }
        $q = DB::table($table)->where('board_id', $board->id);
        if ($kind === 'pins') {
            $q->where('section_id', $sectionId);
        }
        $expected = $q->pluck('id')->all();
        $actual = $ids;
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            $this->fail('ids', 'REORDER_INVALID');
        }
        foreach ($ids as $i => $id) {
            DB::table($table)
                ->where('id', $id)
                ->update(['position' => $i + 1, 'updated_at' => now()]);
        }

        return $this->dto($this->change($board, $actor, $kind.'_reordered'), $actor);
    }

    public function dto(object $board, ?User $actor = null, bool $public = false): array
    {
        $pins = DB::table('board_pins')->where('board_id', $board->id)->orderBy('position')->orderBy('id')->get();
        $catalogue = app(PublicCatalogue::class);
        $offers = $pins->isEmpty()
            ? collect()
            : $catalogue->query()->whereIn('offers.id', $pins->pluck('offer_id'))->get()->keyBy('id');
        $notes =
            ! $public && $actor
                ? DB::table('board_pin_notes')
                    ->where('user_id', $actor->id)
                    ->whereIn('pin_id', $pins->pluck('id'))
                    ->pluck('body', 'pin_id')
                : collect();

        return [
            'id' => $board->id,
            'title' => $board->title,
            'description' => $board->description,
            'kind' => $board->kind,
            'visibility' => $board->visibility,
            'version' => (int) $board->version,
            'gift_date' => $board->gift_date,
            'hide_gift_purchases' => (bool) $board->hide_gift_purchases,
            'cover_pin_id' => $board->cover_pin_id,
            'role' => $public ? 'public_reader' : $this->role($board, $actor),
            'sections' => DB::table('board_sections')
                ->where('board_id', $board->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'position' => (int) $r->position]),
            'pins' => $pins->map(function ($pin) use ($offers, $catalogue, $notes, $public) {
                $saved = json_decode($pin->saved_offer, true);
                $row = $offers->get($pin->offer_id);

                return [
                    'id' => $pin->id,
                    'offer_id' => $pin->offer_id,
                    'section_id' => $pin->section_id,
                    'position' => (int) $pin->position,
                    'wished_quantity' => (int) $pin->wished_quantity,
                    'caption' => $pin->caption,
                    'saved_name' => $saved['name'][app()->getLocale()] ?? '',
                    'sku' => $saved['sku'],
                    'price_at_save' => ['amount' => (int) $pin->saved_unit_price, 'currency' => 'XAF'],
                    'price_saved_at' => Records::iso($pin->price_saved_at),
                    'offer' => $row ? $catalogue->dto($row, app()->getLocale()) : null,
                    'purchased_quantity' => null,
                    ...$public ? [] : ['personal_note' => $notes->get($pin->id, '')],
                ];
            }),
            'purchase_tracking_available' => false,
            'recipient_delivery_available' => false,
            'created_at' => Records::iso($board->created_at),
            'updated_at' => Records::iso($board->updated_at),
        ];
    }
}
