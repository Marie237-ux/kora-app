<?php

namespace App\Domain\Boards;

use App\Domain\Cart\CartConditions;
use App\Domain\Cart\Carts;
use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BoardPins
{
    public function fromCart(Request $http, object $board, array $data): array
    {
        $carts = app(Carts::class);
        $cart = $carts->current($http, true);
        $carts->version($cart, $data['cart_version']);
        $line = $carts->line($cart, $data['line_id']);
        $boards = app(Boards::class);
        $existing = DB::table('board_pins')->where('board_id', $board->id)->where('offer_id', $line->offer_id)->first();
        if ($existing) {
            if (! $data['merge_quantity']) {
                $boards->fail('offer_id', 'PIN_ALREADY_EXISTS');
            }
            $quantity = $existing->wished_quantity + $line->quantity;
            if ($quantity > 1000000) {
                $boards->fail('quantity', 'PIN_QUANTITY_LIMIT');
            }
            $boards->editPin(
                $board,
                $existing->id,
                [
                    'section_id' => $existing->section_id,
                    'caption' => $existing->caption,
                    'wished_quantity' => $quantity,
                ],
                $http->user(),
            );
        } else {
            $boards->addPin(
                $board,
                [
                    'offer_id' => $line->offer_id,
                    'section_id' => $data['section_id'],
                    'wished_quantity' => (int) $line->quantity,
                    'caption' => '',
                    'personal_note' => $data['personal_note'] ?? '',
                ],
                $http->user(),
            );
        }
        if (! $data['copy']) {
            DB::table('cart_lines')->where('id', $line->id)->delete();
            $cart = $carts->bump($cart);
        }

        return [
            'board' => $boards->dto(Records::row('boards', $board->id), $http->user()),
            'cart' => $carts->dto($cart),
            'copied' => (bool) $data['copy'],
        ];
    }

    public function transfer(string $sourceId, string $pinId, array $data, User $actor): array
    {
        abort_if($sourceId === $data['target_board_id'], 422);
        DB::table('boards')
            ->whereIn('id', [$sourceId, $data['target_board_id']])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $boards = app(Boards::class);
        $source = $boards->access($sourceId, $actor, ! $data['copy']);
        $target = $boards->access($data['target_board_id'], $actor, true);
        $boards->version($source, $data['source_version']);
        $boards->version($target, $data['target_version']);
        $pin = $boards->pin($source, $pinId);
        $boards->section($target, $data['section_id']);
        if (DB::table('board_pins')->where('board_id', $target->id)->where('offer_id', $pin->offer_id)->exists()) {
            $boards->fail('offer_id', 'PIN_ALREADY_EXISTS');
        }
        if (DB::table('board_pins')->where('board_id', $target->id)->count() >= 500) {
            $boards->fail('pins', 'PIN_LIMIT');
        }
        $position = (int) DB::table('board_pins')->where('board_id', $target->id)->max('position') + 1;
        if ($data['copy']) {
            $id = Records::id();
            $values = (array) $pin;
            $values = [
                ...$values,
                'id' => $id,
                'board_id' => $target->id,
                'section_id' => $data['section_id'],
                'position' => $position,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            // Saved price retains its original date. The DTO separately exposes current live conditions.
            DB::table('board_pins')->insert($values);
            if (! empty($data['copy_personal_note'])) {
                $note = DB::table('board_pin_notes')->where('pin_id', $pinId)->where('user_id', $actor->id)->first();
                if ($note) {
                    DB::table('board_pin_notes')->insert([
                        'pin_id' => $id,
                        'user_id' => $actor->id,
                        'body' => $note->body,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        } else {
            $id = $pinId;
            if ($source->cover_pin_id === $pinId) {
                DB::table('boards')
                    ->where('id', $source->id)
                    ->update(['cover_pin_id' => null]);
            }
            DB::table('board_pins')
                ->where('id', $pinId)
                ->update([
                    'board_id' => $target->id,
                    'section_id' => $data['section_id'],
                    'position' => $position,
                    'updated_at' => now(),
                ]);
            $source = $boards->change($source, $actor, 'pin_moved_out', [], ['pin_id' => $pinId]);
        }
        $target = $boards->change(
            $target,
            $actor,
            $data['copy'] ? 'pin_copied_in' : 'pin_moved_in',
            [],
            ['pin_id' => $id],
        );

        return ['source_version' => (int) $source->version, 'target' => $boards->dto($target, $actor), 'pin_id' => $id];
    }

    public function toCart(Request $http, object $board, array $ids, int $cartVersion): array
    {
        $boards = app(Boards::class);
        $selected = [];
        foreach ($ids as $id) {
            $selected[] = $boards->pin($board, $id);
        }
        usort($selected, fn ($a, $b) => strcmp($a->offer_id, $b->offer_id));
        $carts = app(Carts::class);
        $cart = $carts->current($http, true);
        $carts->version($cart, $cartVersion);
        $skipped = [];
        foreach ($selected as $pin) {
            $current = app(CartConditions::class)->current($pin->offer_id, true);
            if (! $current || $current['availability'] === 'unavailable') {
                $skipped[] = ['pin_id' => $pin->id, 'reason' => 'unavailable'];

                continue;
            }
            $carts->add($cart, $pin->offer_id, (int) $pin->wished_quantity);
        }

        return [
            'cart' => $carts->dto($carts->bump($cart)),
            'skipped' => $skipped,
            'collection_fees' => null,
            'delivery_quote_required' => true,
        ];
    }
}
