<?php

declare(strict_types=1);

/**
 * Bot travel helpers.
 *
 * Thin wrappers around App\Libraries\FleetsLib so the bot can compute the
 * same values the in-game UI shows (distance, duration, fuel, cargo
 * capacity), instead of inventing numbers like "1800s, fuel 0".
 *
 * What we wrap:
 *  - botTravelDistance()      : FleetsLib::targetDistance()
 *  - botTravelDuration()      : FleetsLib::missionDuration() with the slowest
 *                               ship in the mix as the speed cap.
 *  - botTravelConsumption()   : FleetsLib::fleetConsumption()
 *  - botCargoCapacity()       : FleetsLib::getMaxStorage() per ship.
 *  - botPickCargoMix()        : decide how many small + big cargos to use to
 *                               carry a payload, preferring big ones when
 *                               available (more efficient + more humanlike).
 *
 * All inputs are ints/arrays with explicit semantics so we don't need the
 * full $user object the controllers pass around.
 */

require_once __DIR__ . '/../../config/constants.php';

if (!function_exists('botTravelUserShape')) {
    /**
     * FleetsLib expects a "user" array with research_* keys. The bot already
     * carries that information in $researchRow; this helper massages it
     * into the exact shape FleetsLib reads.
     *
     * Falls back to 0 for any missing key so the lib never warns.
     *
     * @param array<string, mixed> $researchRow
     * @return array<string, int>
     */
    function botTravelUserShape(array $researchRow): array
    {
        $keys = [
            'research_combustion_drive',
            'research_impulse_drive',
            'research_hyperspace_drive',
            'research_hyperspace_technology',
            'research_cargo_optimization',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = (int) ($researchRow[$k] ?? 0);
        }

        return $out;
    }
}

if (!function_exists('botTravelDistance')) {
    /**
     * Returns the in-game distance (in "distance units") between two coords.
     *
     * @param array{galaxy:int, system:int, planet:int} $from
     * @param array{galaxy:int, system:int, planet:int} $to
     */
    function botTravelDistance(array $from, array $to): int
    {
        if (!class_exists('\App\Libraries\FleetsLib')) {
            return 5; // safe fallback (same planet)
        }

        return \App\Libraries\FleetsLib::targetDistance(
            (int) $from['galaxy'],
            (int) $to['galaxy'],
            (int) $from['system'],
            (int) $to['system'],
            (int) $from['planet'],
            (int) $to['planet']
        );
    }
}

if (!function_exists('botTravelShipMaxSpeed')) {
    /**
     * Wrapper around FleetsLib::fleetMaxSpeed() that returns the effective
     * max speed for a single ship id, taking research bonuses into account.
     *
     * @param array<string, mixed> $researchRow
     */
    function botTravelShipMaxSpeed(int $shipId, array $researchRow): int
    {
        if (!class_exists('\App\Libraries\FleetsLib')) {
            return 5000;
        }
        $user = botTravelUserShape($researchRow);
        $speed = \App\Libraries\FleetsLib::fleetMaxSpeed(null, $shipId, $user);
        if (is_array($speed)) {
            $speed = $shipId > 0 && isset($speed[$shipId]) ? $speed[$shipId] : 0;
        }

        return max(1, (int) $speed);
    }
}

if (!function_exists('botTravelDuration')) {
    /**
     * Returns the in-game mission duration in seconds.
     *
     * The fleet flies at the speed of its slowest ship. We pass percentage=10
     * (full speed) because bots don't slow down to deceive enemies; if you
     * ever want a stealth feature, expose this argument.
     *
     * @param array<int, int> $shipMix Map shipId => count, e.g. [202 => 5]
     * @param array<string, mixed> $researchRow
     * @return int Seconds (rounded up so we never underestimate).
     */
    function botTravelDuration(int $distance, array $shipMix, array $researchRow, float $universeSpeed): int
    {
        if (empty($shipMix) || $distance <= 0) {
            return 1;
        }
        $minSpeed = PHP_INT_MAX;
        foreach ($shipMix as $shipId => $count) {
            if ((int) $count <= 0) {
                continue;
            }
            $s = botTravelShipMaxSpeed((int) $shipId, $researchRow);
            if ($s < $minSpeed) {
                $minSpeed = $s;
            }
        }
        if ($minSpeed === PHP_INT_MAX) {
            return 1;
        }

        if (!class_exists('\App\Libraries\FleetsLib')) {
            // Approximation if class missing.
            return (int) ceil((35000 / 10 * sqrt($distance * 10 / max(1, $minSpeed)) + 10) / max(0.1, $universeSpeed));
        }

        $duration = \App\Libraries\FleetsLib::missionDuration(
            10,
            $minSpeed,
            $distance,
            (int) max(1, $universeSpeed)
        );

        return (int) max(1, ceil($duration));
    }
}

if (!function_exists('botTravelConsumption')) {
    /**
     * Returns the deuterium fuel consumption for a fleet trip (one-way).
     *
     * @param array<int, int> $shipMix Map shipId => count
     * @param array<string, mixed> $researchRow
     */
    function botTravelConsumption(array $shipMix, int $distance, int $duration, array $researchRow, float $universeSpeed): int
    {
        if (empty($shipMix) || $distance <= 0 || $duration <= 0) {
            return 0;
        }
        if (!class_exists('\App\Libraries\FleetsLib')) {
            return 0;
        }
        $user = botTravelUserShape($researchRow);
        $cleanMix = [];
        foreach ($shipMix as $shipId => $count) {
            $count = (int) $count;
            if ($shipId > 0 && $count > 0) {
                $cleanMix[(int) $shipId] = $count;
            }
        }
        if (empty($cleanMix)) {
            return 0;
        }
        $value = \App\Libraries\FleetsLib::fleetConsumption(
            $cleanMix,
            (int) max(1, $universeSpeed),
            (int) $duration,
            (int) $distance,
            $user
        );

        return (int) max(0, (int) $value);
    }
}

if (!function_exists('botCargoCapacity')) {
    /**
     * Effective per-ship storage in resources, accounting for hyperspace
     * tech and cargo optimization (cargo ships only).
     */
    function botCargoCapacity(int $shipId, array $researchRow): int
    {
        $priceList = $GLOBALS['pricelist'] ?? [];
        $base = (int) ($priceList[$shipId]['capacity'] ?? 0);
        if ($base <= 0) {
            return 0;
        }
        if (!class_exists('\App\Libraries\FleetsLib')) {
            return $base;
        }

        return \App\Libraries\FleetsLib::getMaxStorage(
            $base,
            (int) ($researchRow['research_hyperspace_technology'] ?? 0),
            (int) ($researchRow['research_cargo_optimization'] ?? 0),
            $shipId
        );
    }
}

if (!function_exists('botPickCargoMix')) {
    /**
     * Decide how many small + big cargos to use to carry $payload, given the
     * available stocks. Strategy:
     *   1. Use big cargos first (more efficient: 25k base vs 5k base).
     *   2. Fall back to small cargos for whatever's left.
     *   3. Always allocate at least 1 ship if any payload remains and any
     *      cargo is available, even if the per-ship capacity overshoots.
     *
     * Returns null if no cargo at all is available or payload is 0.
     *
     * @param array<string, mixed> $researchRow
     * @return array{small:int, big:int, capacity:int}|null
     */
    function botPickCargoMix(int $payload, int $availableSmall, int $availableBig, array $researchRow): ?array
    {
        if ($payload <= 0) {
            return null;
        }
        if ($availableSmall <= 0 && $availableBig <= 0) {
            return null;
        }

        $smallCap = botCargoCapacity(202, $researchRow);
        $bigCap = botCargoCapacity(203, $researchRow);
        if ($smallCap <= 0 && $bigCap <= 0) {
            return null;
        }

        $remaining = $payload;
        $useBig = 0;
        if ($bigCap > 0 && $availableBig > 0) {
            $useBig = min($availableBig, (int) ceil($remaining / $bigCap));
            $remaining -= $useBig * $bigCap;
            if ($remaining < 0) {
                $remaining = 0;
            }
        }

        $useSmall = 0;
        if ($remaining > 0 && $smallCap > 0 && $availableSmall > 0) {
            $useSmall = min($availableSmall, (int) ceil($remaining / $smallCap));
            $remaining -= $useSmall * $smallCap;
            if ($remaining < 0) {
                $remaining = 0;
            }
        }

        // If still leftover and we underused big cargos because they were
        // capped by stock, we accept partial coverage. The caller will adjust
        // the resource amounts to fit the actual capacity.
        $totalCap = ($useBig * $bigCap) + ($useSmall * $smallCap);
        if ($totalCap <= 0) {
            return null;
        }

        return [
            'small' => $useSmall,
            'big' => $useBig,
            'capacity' => $totalCap,
        ];
    }
}

if (!function_exists('botPickRaidMix')) {
    /**
     * Decide how many cargo + escort ships to commit to a raid.
     *
     * Differs from botPickCargoMix in that:
     *   - It also picks light combat escort (light fighter, cruiser).
     *   - It applies BOT_ATTACK_FLEET_RESERVE_PCT to every available
     *     ship type so the source planet keeps a residual home defense.
     *   - The escort budget grows with $expectedDefense to give the
     *     simulator a reasonable shot at winning vs the defender.
     *
     * @param int $expectedLoot Loot estimate (m+c+d sum).
     * @param int $expectedDefense Estimated defender force value (m+c+d
     *                              sum of defender ships+defenses build cost).
     * @param array<int, int> $availableShipsByType Map shipId => count
     *                                              currently parked at source.
     * @param array<string, mixed> $researchRow
     * @return array{
     *     by_id: array<int, int>,
     *     cargo_capacity: int,
     *     fleet_value: int
     * }|null
     */
    function botPickRaidMix(int $expectedLoot, int $expectedDefense, array $availableShipsByType, array $researchRow): ?array
    {
        if ($expectedLoot <= 0) {
            return null;
        }
        $reserve = defined('BOT_ATTACK_FLEET_RESERVE_PCT') ? (float) BOT_ATTACK_FLEET_RESERVE_PCT : 0.20;
        $reserve = max(0.0, min(0.9, $reserve));

        $usable = [];
        foreach ($availableShipsByType as $shipId => $count) {
            $shipId = (int) $shipId;
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }
            $cap = (int) floor($count * (1.0 - $reserve));
            if ($cap > 0) {
                $usable[$shipId] = $cap;
            }
        }

        if (empty($usable)) {
            return null;
        }

        // Cargo allocation: half loot is the worst-case attacker carry need.
        $cargoNeed = (int) ceil($expectedLoot / 2);
        $smallAvail = (int) ($usable[202] ?? 0);
        $bigAvail = (int) ($usable[203] ?? 0);
        $cargoMix = botPickCargoMix($cargoNeed, $smallAvail, $bigAvail, $researchRow);
        if ($cargoMix === null && ($smallAvail > 0 || $bigAvail > 0)) {
            // No cargo strictly needed (cargoNeed=0), still bring everything.
            $cargoMix = ['small' => $smallAvail, 'big' => $bigAvail, 'capacity' => 0];
        }
        if ($cargoMix === null) {
            // No cargo at all: still allow combat-only raids on weak targets,
            // but cap loot capacity to 0 so the caller knows it can't plunder.
            $cargoMix = ['small' => 0, 'big' => 0, 'capacity' => 0];
        }

        $byId = [];
        if (($cargoMix['big'] ?? 0) > 0) {
            $byId[203] = (int) $cargoMix['big'];
        }
        if (($cargoMix['small'] ?? 0) > 0) {
            $byId[202] = (int) $cargoMix['small'];
        }

        // Escort budget: scale with defender value so we bring more force
        // when the target is harder. Bring "everything we can" up to the
        // reserve cap when defense is unknown (== 0).
        $escortCandidates = [204, 205, 206, 207, 211, 213, 215];
        foreach ($escortCandidates as $shipId) {
            $available = (int) ($usable[$shipId] ?? 0);
            if ($available <= 0) {
                continue;
            }
            $byId[$shipId] = $available;
        }

        if (empty($byId)) {
            return null;
        }

        $priceList = $GLOBALS['pricelist'] ?? [];
        $fleetValue = 0;
        foreach ($byId as $shipId => $count) {
            $price = $priceList[$shipId] ?? null;
            if (!is_array($price)) {
                continue;
            }
            $unitCost = (int) ($price['metal'] ?? 0)
                + (int) ($price['crystal'] ?? 0)
                + (int) ($price['deuterium'] ?? 0);
            $fleetValue += $unitCost * $count;
        }

        return [
            'by_id' => $byId,
            'cargo_capacity' => (int) ($cargoMix['capacity'] ?? 0),
            'fleet_value' => $fleetValue,
        ];
    }
}

if (!function_exists('botTravelEstimate')) {
    /**
     * Convenience wrapper that returns a single struct with everything the
     * caller needs to insert a fleets row: duration, consumption, ship mix
     * (only ships with count > 0), and the chosen capacity.
     *
     * Used for transport (mission 3); for colony (mission 7) just call the
     * helpers directly because the mix is fixed (1 colony ship).
     *
     * @param array{galaxy:int, system:int, planet:int} $from
     * @param array{galaxy:int, system:int, planet:int} $to
     * @param array<int, int> $shipMix
     * @param array<string, mixed> $researchRow
     * @return array{distance:int, duration:int, consumption:int}
     */
    function botTravelEstimate(array $from, array $to, array $shipMix, array $researchRow, float $universeSpeed): array
    {
        $distance = botTravelDistance($from, $to);
        $duration = botTravelDuration($distance, $shipMix, $researchRow, $universeSpeed);
        $consumption = botTravelConsumption($shipMix, $distance, $duration, $researchRow, $universeSpeed);

        return [
            'distance' => $distance,
            'duration' => $duration,
            'consumption' => $consumption,
        ];
    }
}
