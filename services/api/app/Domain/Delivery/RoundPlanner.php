<?php

namespace App\Domain\Delivery;

/**
 * Suggested order of a courier's stops, without a distance service: work is grouped by zone (a set of
 * districts). In the zone where the courier is, what is on board for that zone is handed over first, then the
 * pickups there are made, then what was just collected for that same zone is handed over. The courier then
 * moves to the zone of the most urgent customer slot among the goods on board, or else to the next pickup.
 * A task's final stop (customer delivery, depot drop) never comes before all of its pickups.
 *
 * Inside a zone, when the stops have coordinates, the nearest one to the courier's last position comes next;
 * stops without coordinates keep the district/slot order after them. The dispatcher can still reorder by hand.
 */
final class RoundPlanner
{
    /**
     * @param  list<array{id: string, number: string, held: bool, pickups: list<array{place: string, warehouse_id: ?string, area: string, lat?: ?float, lng?: ?float}>, area: string, district: string, slot: ?string, lat?: ?float, lng?: ?float}>  $tasks
     * @return list<array{mission_id: string, kind: string, warehouse_id: ?string}>
     */
    public function plan(array $tasks): array
    {
        $pickups = [];
        $waiting = [];
        $finals = [];
        foreach ($tasks as $t) {
            $finals[$t['id']] = $t;
            $waiting[$t['id']] = 0;
            if ($t['held']) {
                continue;
            }
            foreach ($t['pickups'] as $p) {
                $pickups[] = [...$p, 'task' => $t];
                $waiting[$t['id']]++;
            }
        }
        $out = [];
        $position = null;
        while ($pickups || $finals) {
            $area = $this->nextArea($pickups, $finals, $waiting);
            $this->finishIn($area, $finals, $waiting, $out, $position);
            $here = array_values(array_filter($pickups, fn ($p) => $p['area'] === $area));
            usort(
                $here,
                fn ($a, $b) => [$a['place'], $this->slotKey($a['task']), $a['task']['number']] <=> [
                    $b['place'],
                    $this->slotKey($b['task']),
                    $b['task']['number'],
                ],
            );
            foreach ($this->nearestFirst($here, $position) as $p) {
                $out[] = [
                    'mission_id' => $p['task']['id'],
                    'kind' => 'pickup',
                    'warehouse_id' => $p['warehouse_id'],
                    'collection_id' => $p['collection_id'] ?? null,
                ];
                $waiting[$p['task']['id']]--;
                $position = $this->at($p) ?? $position;
            }
            $pickups = array_values(array_filter($pickups, fn ($p) => $p['area'] !== $area));
            $this->finishIn($area, $finals, $waiting, $out, $position);
        }

        return $out;
    }

    private function nextArea(array $pickups, array $finals, array $waiting): string
    {
        $ready = array_filter($finals, fn ($t) => $waiting[$t['id']] === 0);
        if ($ready) {
            usort(
                $ready,
                fn ($a, $b) => [$this->slotKey($a), $a['area'], $a['number']] <=> [
                    $this->slotKey($b),
                    $b['area'],
                    $b['number'],
                ],
            );

            return $ready[0]['area'];
        }
        usort(
            $pickups,
            fn ($a, $b) => [$this->slotKey($a['task']), $a['place'], $a['task']['number']] <=> [
                $this->slotKey($b['task']),
                $b['place'],
                $b['task']['number'],
            ],
        );

        return $pickups[0]['area'];
    }

    private function finishIn(string $area, array &$finals, array $waiting, array &$out, ?array &$position): void
    {
        $here = array_values(array_filter($finals, fn ($t) => $t['area'] === $area && $waiting[$t['id']] === 0));
        usort(
            $here,
            fn ($a, $b) => [$a['district'], $this->slotKey($a), $a['number']] <=> [
                $b['district'],
                $this->slotKey($b),
                $b['number'],
            ],
        );
        foreach ($this->nearestFirst($here, $position) as $t) {
            $out[] = [
                'mission_id' => $t['id'],
                'kind' => 'delivery',
                'warehouse_id' => $t['warehouse_id'] ?? null,
                'collection_id' => null,
            ];
            unset($finals[$t['id']]);
            $position = $this->at($t) ?? $position;
        }
    }

    /**
     * Greedy nearest neighbour from the current position over the located stops, in their given order when
     * no position is known yet; unlocated stops follow in the given order.
     */
    private function nearestFirst(array $stops, ?array $position): array
    {
        $located = array_values(array_filter($stops, fn ($s) => $this->at($s) !== null));
        $rest = array_values(array_filter($stops, fn ($s) => $this->at($s) === null));
        $ordered = [];
        while ($located) {
            $best = 0;
            if ($position !== null) {
                foreach ($located as $i => $s) {
                    if (
                        $this->distance($position, $this->at($s)) <
                        $this->distance($position, $this->at($located[$best]))
                    ) {
                        $best = $i;
                    }
                }
            }
            $ordered[] = $located[$best];
            $position = $this->at($located[$best]);
            array_splice($located, $best, 1);
        }

        return [...$ordered, ...$rest];
    }

    private function at(array $stop): ?array
    {
        return isset($stop['lat'], $stop['lng']) ? [(float) $stop['lat'], (float) $stop['lng']] : null;
    }

    /** Great-circle distance in metres. */
    private function distance(array $a, array $b): float
    {
        [$lat1, $lng1] = array_map('deg2rad', $a);
        [$lat2, $lng2] = array_map('deg2rad', $b);
        $h = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(($lng2 - $lng1) / 2) ** 2;

        return 2 * 6371000 * asin(min(1.0, sqrt($h)));
    }

    /** Tasks without a slot come after every dated one. */
    private function slotKey(array $task): string
    {
        return $task['slot'] ?? '9999';
    }
}
