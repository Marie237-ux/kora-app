<?php

namespace Tests\Unit;

use App\Domain\Delivery\RoundPlanner;
use PHPUnit\Framework\TestCase;

final class RoundPlannerTest extends TestCase
{
    private function mission(
        string $n,
        array $pickups,
        string $area,
        string $district,
        ?string $slot,
        bool $held = false,
    ): array {
        return [
            'id' => 'm'.$n,
            'number' => 'MIS-'.$n,
            'held' => $held,
            'pickups' => array_map(
                fn ($p) => ['place' => 'W:'.$p[0], 'warehouse_id' => 'w'.$p[0], 'area' => $p[1]],
                $pickups,
            ),
            'area' => $area,
            'district' => $district,
            'slot' => $slot,
        ];
    }

    private function steps(array $plan): array
    {
        return array_map(
            fn ($s) => ($s['kind'] === 'pickup' ? 'P ' : 'D ').
                $s['mission_id'].
                ($s['warehouse_id'] ? '@'.$s['warehouse_id'] : ''),
            $plan,
        );
    }

    public function test_the_courier_delivers_in_the_district_of_the_next_shop_before_collecting_there(): void
    {
        // Shop A in zone 1, shop B in zone 2. Mission 1 goes from A to a customer in zone 2.
        $plan = (new RoundPlanner)->plan([
            $this->mission('1', [['A', 'Z1']], 'Z2', 'Bonapriso', '2026-11-11 10:00:00'),
            $this->mission('2', [['B', 'Z2']], 'Z3', 'Bonamoussadi', '2026-11-11 11:00:00'),
            $this->mission('3', [['A', 'Z1']], 'Z1', 'Akwa', '2026-11-11 12:00:00'),
        ]);
        $this->assertSame(
            [
                // At shop A: collect everything for the day there, deliver the neighbour in zone 1.
                'P m1@wA',
                'P m3@wA',
                'D m3',
                // In zone 2: hand over the parcel on board, then collect at shop B.
                'D m1',
                'P m2@wB',
                'D m2',
            ],
            $this->steps($plan),
        );
    }

    public function test_parcels_already_on_board_are_not_collected_again_and_urgent_slots_lead(): void
    {
        $plan = (new RoundPlanner)->plan([
            $this->mission('1', [['A', 'Z1']], 'Z1', 'Akwa', '2026-11-11 15:00:00'),
            $this->mission('2', [], 'Z2', 'Deido', '2026-11-11 09:00:00', true),
            $this->mission('3', [['A', 'Z1'], ['C', 'Z3']], 'Z2', 'Bali', null),
        ]);
        $this->assertSame(['D m2', 'P m1@wA', 'P m3@wA', 'D m1', 'P m3@wC', 'D m3'], $this->steps($plan));
    }

    public function test_inside_a_zone_located_stops_follow_the_nearest_neighbour(): void
    {
        // One depot and three customers in the same zone, on a line going east: districts alone would give
        // Akwa, Bali, Deido; distance gives Deido (closest), then Bali, then Akwa.
        $at = fn (array $m, float $lng) => [...$m, 'lat' => 4.05, 'lng' => $lng];
        $depot = fn (array $m) => [...$m, 'pickups' => [[...$m['pickups'][0], 'lat' => 4.05, 'lng' => 9.7]]];
        $plan = (new RoundPlanner)->plan([
            $depot($at($this->mission('1', [['W', 'Z']], 'Z', 'Akwa', null), 9.73)),
            $depot($at($this->mission('2', [['W', 'Z']], 'Z', 'Bali', null), 9.72)),
            $depot($at($this->mission('3', [['W', 'Z']], 'Z', 'Deido', null), 9.71)),
            // Without coordinates: after the located ones, in district order.
            $depot($this->mission('4', [['W', 'Z']], 'Z', 'Aéroport', null)),
        ]);
        $this->assertSame(
            ['P m1@wW', 'P m2@wW', 'P m3@wW', 'P m4@wW', 'D m3', 'D m2', 'D m1', 'D m4'],
            $this->steps($plan),
        );
    }

    public function test_every_delivery_follows_all_pickups_of_its_mission(): void
    {
        mt_srand(20261110);
        for ($run = 0; $run < 200; $run++) {
            $missions = [];
            for ($i = 0; $i < mt_rand(1, 8); $i++) {
                $pickups = [];
                for ($p = 0; $p < mt_rand(0, 3); $p++) {
                    $pickups[] = [chr(65 + mt_rand(0, 4)), 'Z'.mt_rand(1, 4)];
                }
                $missions[] = $this->mission(
                    (string) $i,
                    $pickups,
                    'Z'.mt_rand(1, 4),
                    'D'.mt_rand(1, 3),
                    mt_rand(0, 3)
                        ? '2026-11-11 '.str_pad((string) mt_rand(8, 18), 2, '0', STR_PAD_LEFT).':00:00'
                        : null,
                    $pickups === [],
                );
            }
            $plan = (new RoundPlanner)->plan($missions);
            $expected = array_sum(array_map(fn ($m) => count($m['pickups']) + 1, $missions));
            $this->assertCount($expected, $plan);
            $delivered = [];
            foreach ($plan as $stop) {
                if ($stop['kind'] === 'delivery') {
                    $this->assertArrayNotHasKey($stop['mission_id'], $delivered);
                    $delivered[$stop['mission_id']] = true;
                } else {
                    $this->assertArrayNotHasKey($stop['mission_id'], $delivered, 'Pickup after its delivery');
                }
            }
            $this->assertCount(count($missions), $delivered);
        }
    }
}
