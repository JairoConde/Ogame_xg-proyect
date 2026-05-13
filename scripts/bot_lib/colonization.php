<?php

declare(strict_types=1);

/**
 * Bot colonization module.
 *
 * Replaces the old tryColonize/findColonizationTarget/pickColonizationSourcePlanet
 * trio with a smarter, modular implementation:
 *
 *   - Search candidates from EVERY owned planet (not only home), so the bot
 *     uses the colony with the closest free spot as the source.
 *   - Optionally reach into other galaxies once the bot has hyperspace_drive
 *     or saturates its home galaxy. We only do this when the bot has actually
 *     researched the drive enough so the long flight is plausible.
 *   - Position preference favors the "big" positions (4, 5, 9, 10) without
 *     hard-banning the smaller allowed ones.
 *   - Soft preference for systems where the bot has no presence yet, but a
 *     single bot can have a couple of planets in the same system (more human
 *     than perfect spacing).
 *   - Personality bias: cazador/flotero are slightly more aggressive about
 *     expanding than minero/defensor.
 *   - Real travel duration + fuel via the travel module.
 *
 * Public entry point: botColonizationTryColonize().
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/strategy.php';
require_once __DIR__ . '/safety.php';
require_once __DIR__ . '/travel.php';

if (!function_exists('botColonizationMaxColonies')) {
    /**
     * Personality-flavoured cap on the number of additional colonies a bot
     * is willing to maintain. Returns the count of "extra planets beyond
     * the home" that we allow.
     *
     * Cazador/flotero get +1 over the raw astro-derived ceiling, minero/
     * defensor stay strict. We never exceed the game-engine's own limit
     * (FleetsLib::getMaxColonies, which is ceil(astro/2)).
     */
    function botColonizationMaxColonies(int $astrophysicsLevel, string $personality): int
    {
        if ($astrophysicsLevel <= 0) {
            return 0;
        }
        $base = (int) ceil($astrophysicsLevel / 2);
        $bonus = 0;
        if ($personality === 'cazador' || $personality === 'flotero') {
            $bonus = 1;
        } elseif ($personality === 'defensor') {
            // Defensor expands cautiously: stays one short of the theoretical
            // max so it always has astrophysics overhead for resilience.
            return max(0, $base - 1);
        }

        return $base + $bonus;
    }
}

if (!function_exists('botColonizationOrderedPositions')) {
    /**
     * Returns the colonizable positions, ordered by:
     *   1. Game-side big-size preference (4, 5, 9, 10 first).
     *   2. Per-bot deterministic shuffle within each tier (so two bots
     *      with the same archetype don't duplicate the exact ordering).
     *
     * @return array<int, int>
     */
    function botColonizationOrderedPositions(int $seed): array
    {
        $allowed = botColonizationAllowedPositions(); // [3,4,5,9,10,11,12]
        $preferred = [4, 5, 9, 10];
        $tier1 = [];
        $tier2 = [];
        foreach ($allowed as $p) {
            if (in_array($p, $preferred, true)) {
                $tier1[] = $p;
            } else {
                $tier2[] = $p;
            }
        }
        if ($seed > 0) {
            $tier1 = botRngShuffle($seed, 'colonize:positions:t1', $tier1);
            $tier2 = botRngShuffle($seed, 'colonize:positions:t2', $tier2);
        }

        return array_values(array_merge($tier1, $tier2));
    }
}

if (!function_exists('botColonizationCanReachOtherGalaxies')) {
    /**
     * The bot will scan beyond its home galaxy when:
     *   - it has hyperspace drive >= 5 (game stage where intergalactic flights
     *     are commonplace), AND
     *   - its home galaxy is saturated (no free spot found in N systems).
     *
     * The 5+ threshold is conservative: lower hyperspace levels lead to
     * absurdly long flights for a colonizer (speed=2500), which the in-game
     * UI penalises heavily.
     */
    function botColonizationCanReachOtherGalaxies(array $researchRow): bool
    {
        return (int) ($researchRow['research_hyperspace_drive'] ?? 0) >= 5;
    }
}

if (!function_exists('botColonizationCountPlanetsInSystem')) {
    /**
     * Count this bot's planets already located in (galaxy, system).
     * Used as a soft penalty (not a ban) when scoring candidate slots.
     *
     * @param array<int, array<string, mixed>> $planets
     */
    function botColonizationCountPlanetsInSystem(array $planets, int $galaxy, int $system): int
    {
        $n = 0;
        foreach ($planets as $p) {
            if ((int) $p['planet_galaxy'] === $galaxy
                && (int) $p['planet_system'] === $system
                && (int) ($p['planet_type'] ?? 1) === 1
                && (int) ($p['planet_destroyed'] ?? 0) === 0
            ) {
                $n++;
            }
        }

        return $n;
    }
}

if (!function_exists('botColonizationFindBestTarget')) {
    /**
     * Scan candidate galaxies/systems and return the best free slot, scored
     * by:
     *   + Position tier (big-size positions first).
     *   - Distance from the source (travel cost).
     *   - Soft penalty if we already have 2+ planets in that system.
     *   * Hard ban: 3+ planets in the same system (we said "couple at most").
     *
     * @param array<int, array<string, mixed>> $ownedPlanets
     * @return array{galaxy:int, system:int, planet:int, type:int, source:array}|null
     */
    function botColonizationFindBestTarget(
        mysqli $db,
        string $prefix,
        array $sourcePlanet,
        array $ownedPlanets,
        array $researchRow,
        ?array $state
    ): ?array {
        $homeGalaxy = (int) $sourcePlanet['planet_galaxy'];
        $maxSystems = (int) MAX_SYSTEM_IN_GALAXY;
        $reservedSystems = (int) BOT_RESERVED_SYSTEMS_PER_GALAXY;
        $usableSystems = max(0, $maxSystems - $reservedSystems);
        if ($usableSystems <= 0) {
            return null;
        }

        $seed = (int) ($state['bot_seed'] ?? 0);
        $startSystem = $reservedSystems + 1;
        if ($seed > 0) {
            $startSystem = botRngInt(
                $seed,
                'colonize:start_system',
                $reservedSystems + 1,
                max($reservedSystems + 1, $maxSystems)
            );
        }
        $startSystem = max($reservedSystems + 1, min($maxSystems, $startSystem));

        $orderedPositions = botColonizationOrderedPositions($seed);

        $galaxiesToScan = [$homeGalaxy];
        if (botColonizationCanReachOtherGalaxies($researchRow)) {
            // Add adjacent galaxies, deterministic per bot which side first.
            $direction = ($seed > 0 && botRng($seed, 'colonize:gx_dir') < 0.5) ? -1 : 1;
            for ($offset = 1; $offset <= 2; $offset++) {
                $g = $homeGalaxy + ($direction * $offset);
                if ($g >= 1 && $g <= MAX_GALAXY_IN_WORLD) {
                    $galaxiesToScan[] = $g;
                }
                $g = $homeGalaxy - ($direction * $offset);
                if ($g >= 1 && $g <= MAX_GALAXY_IN_WORLD) {
                    $galaxiesToScan[] = $g;
                }
            }
            $galaxiesToScan = array_values(array_unique($galaxiesToScan));
        }

        $best = null;
        $bestScore = -INF;

        // We score every candidate slot we encounter and keep the top one.
        // Scanning is bounded to keep the bot loop snappy; we stop early
        // once we found a "great" slot (score > 1.0e6) OR after N candidates.
        $maxCandidatesPerGalaxy = 12;

        foreach ($galaxiesToScan as $galaxy) {
            $candidates = 0;
            $isHomeGalaxy = ($galaxy === $homeGalaxy);
            for ($offset = 0; $offset < $usableSystems && $candidates < $maxCandidatesPerGalaxy; $offset++) {
                $system = ((($startSystem - $reservedSystems - 1) + $offset) % $usableSystems) + $reservedSystems + 1;
                if ($system <= $reservedSystems) {
                    continue;
                }

                $occupied = [];
                $result = $db->query(
                    "SELECT `planet_planet`
                     FROM `{$prefix}planets`
                     WHERE `planet_galaxy` = {$galaxy}
                       AND `planet_system` = {$system}
                       AND `planet_type` = 1
                       AND `planet_destroyed` = 0"
                );
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $occupied[(int) $row['planet_planet']] = true;
                    }
                    $result->free();
                }

                $ownedHere = botColonizationCountPlanetsInSystem($ownedPlanets, $galaxy, $system);
                if ($ownedHere >= 2) {
                    // Hard cap: a couple per system, no more.
                    continue;
                }

                foreach ($orderedPositions as $tierIndex => $position) {
                    if (isset($occupied[$position])) {
                        continue;
                    }
                    if (!botColonizationAllowed($galaxy, $system, $position)) {
                        continue;
                    }

                    $candidates++;
                    $target = [
                        'galaxy' => $galaxy,
                        'system' => $system,
                        'planet' => $position,
                        'type' => 1,
                    ];

                    // Distance penalty (use FleetsLib formula directly, not
                    // travel duration: cheaper to compute and good enough
                    // for ranking).
                    $distance = botTravelDistance(
                        [
                            'galaxy' => (int) $sourcePlanet['planet_galaxy'],
                            'system' => (int) $sourcePlanet['planet_system'],
                            'planet' => (int) $sourcePlanet['planet_planet'],
                        ],
                        $target
                    );

                    $tierBonus = 1000.0 / (1 + $tierIndex); // big positions first
                    $sameSystemPenalty = $ownedHere * 200.0; // discourage clustering
                    $homeGalaxyBonus = $isHomeGalaxy ? 300.0 : 0.0;
                    $distancePenalty = log(max(1, $distance)) * 50.0;

                    $score = $tierBonus + $homeGalaxyBonus - $sameSystemPenalty - $distancePenalty;

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = [
                            'galaxy' => $galaxy,
                            'system' => $system,
                            'planet' => $position,
                            'type' => 1,
                        ];
                    }

                    break; // one candidate per system is enough for ranking
                }

                if ($best !== null && $bestScore > 1000.0 + 300.0 && $isHomeGalaxy) {
                    // We already found a top-tier slot in the home galaxy:
                    // no need to scan further (or jump to other galaxies).
                    return $best;
                }
            }
        }

        return $best;
    }
}

if (!function_exists('botColonizationPickSource')) {
    /**
     * Picks the best source planet for a colonization wave, preferring
     * the one with most colony ships and the best deuterium reserves.
     * Returns null if no planet has any colony ship ready.
     *
     * @param array<int, array<string, mixed>> $planets
     */
    function botColonizationPickSource(mysqli $db, string $prefix, array $planets): ?array
    {
        if (empty($planets)) {
            return null;
        }
        $planetIds = [];
        foreach ($planets as $p) {
            $planetIds[] = (int) $p['planet_id'];
        }
        if (empty($planetIds)) {
            return null;
        }
        $idList = implode(',', array_map('intval', $planetIds));
        $shipsByPlanet = [];
        $result = $db->query(
            "SELECT `ship_planet_id`, `ship_colony_ship`
             FROM `{$prefix}ships`
             WHERE `ship_planet_id` IN ({$idList})"
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $shipsByPlanet[(int) $row['ship_planet_id']] = (int) $row['ship_colony_ship'];
            }
            $result->free();
        }

        $best = null;
        $bestScore = -1;
        foreach ($planets as $planet) {
            $pid = (int) $planet['planet_id'];
            $colonyShips = $shipsByPlanet[$pid] ?? 0;
            if ($colonyShips < 1) {
                continue;
            }
            // Score: ship count dominates; deuterium breaks ties so the bot
            // doesn't strand itself on a fuel-empty planet.
            $score = ($colonyShips * 1_000_000) + (int) min(1_000_000, (float) ($planet['planet_deuterium'] ?? 0));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $planet;
                $best['_colony_ships_available'] = $colonyShips;
            }
        }

        return $best;
    }
}

if (!function_exists('botColonizationTryColonize')) {
    /**
     * Public entry point: tries to send a colony ship and returns a log
     * line if it did, null otherwise.
     */
    function botColonizationTryColonize(
        mysqli $db,
        string $prefix,
        array $user,
        array $planets,
        array $researchRow,
        ?array $state,
        float $universeSpeed
    ): ?string {
        $userId = (int) $user['user_id'];
        $astroLevel = (int) ($researchRow['research_astrophysics'] ?? 0);
        $personality = (string) ($state['bot_personality'] ?? 'minero');
        $maxColonies = botColonizationMaxColonies($astroLevel, $personality);
        if ($maxColonies <= 0) {
            return null;
        }

        $planetCountResult = $db->query(
            "SELECT COUNT(*) AS total
             FROM `{$prefix}planets`
             WHERE `planet_user_id` = {$userId}
               AND `planet_type` = 1
               AND `planet_destroyed` = 0"
        );
        $planetCountRow = $planetCountResult ? $planetCountResult->fetch_assoc() : null;
        if ($planetCountResult) {
            $planetCountResult->free();
        }
        $planetCount = (int) ($planetCountRow['total'] ?? 0);
        $extraColonies = max(0, $planetCount - 1);
        if ($extraColonies >= $maxColonies) {
            return null;
        }

        // Don't pile colony ships in flight: at most one wave at a time.
        $inFlightResult = $db->query(
            "SELECT COUNT(*) AS total
             FROM `{$prefix}fleets`
             WHERE `fleet_owner` = {$userId}
               AND `fleet_mission` = 7
               AND `fleet_mess` = 0"
        );
        $inFlightRow = $inFlightResult ? $inFlightResult->fetch_assoc() : null;
        if ($inFlightResult) {
            $inFlightResult->free();
        }
        if ((int) ($inFlightRow['total'] ?? 0) > 0) {
            return null;
        }

        $source = botColonizationPickSource($db, $prefix, $planets);
        if ($source === null) {
            return null;
        }

        $target = botColonizationFindBestTarget($db, $prefix, $source, $planets, $researchRow, $state);
        if ($target === null) {
            return null;
        }

        // Real travel: distance, duration, fuel for one colony ship.
        $shipMix = [208 => 1];
        $sourceCoords = [
            'galaxy' => (int) $source['planet_galaxy'],
            'system' => (int) $source['planet_system'],
            'planet' => (int) $source['planet_planet'],
        ];
        $estimate = botTravelEstimate($sourceCoords, $target, $shipMix, $researchRow, $universeSpeed);
        $duration = $estimate['duration'];
        $fuel = $estimate['consumption'];

        // Sanity: must have enough deuterium to fuel the trip + tiny reserve.
        $availableDeut = (float) ($source['planet_deuterium'] ?? 0);
        if ($availableDeut < $fuel + 50) {
            return null;
        }

        $now = time();
        $fleetArray = '208,1;';
        $sourcePlanetId = (int) $source['planet_id'];

        $startGalaxy = (int) $source['planet_galaxy'];
        $startSystem = (int) $source['planet_system'];
        $startPlanet = (int) $source['planet_planet'];
        $startType = (int) ($source['planet_type'] ?? 1);
        $endGalaxy = (int) $target['galaxy'];
        $endSystem = (int) $target['system'];
        $endPlanet = (int) $target['planet'];
        $endType = (int) $target['type'];
        $endTime = $now + $duration;

        $fleetId = botFleetInsertTransactional(
            $db,
            $prefix,
            $sourcePlanetId,
            function (mysqli $db) use (
                $prefix,
                $userId,
                $fleetArray,
                $now,
                $startGalaxy,
                $startSystem,
                $startPlanet,
                $startType,
                $endTime,
                $endGalaxy,
                $endSystem,
                $endPlanet,
                $endType,
                $fuel,
                $sourcePlanetId
            ): ?int {
                $okInsert = $db->query(
                    "INSERT INTO `{$prefix}fleets` SET
                     `fleet_owner` = {$userId},
                     `fleet_mission` = 7,
                     `fleet_amount` = 1,
                     `fleet_array` = '" . $db->real_escape_string($fleetArray) . "',
                     `fleet_start_time` = {$now},
                     `fleet_start_galaxy` = {$startGalaxy},
                     `fleet_start_system` = {$startSystem},
                     `fleet_start_planet` = {$startPlanet},
                     `fleet_start_type` = {$startType},
                     `fleet_end_time` = {$endTime},
                     `fleet_end_stay` = 0,
                     `fleet_end_galaxy` = {$endGalaxy},
                     `fleet_end_system` = {$endSystem},
                     `fleet_end_planet` = {$endPlanet},
                     `fleet_end_type` = {$endType},
                     `fleet_target_obj` = 0,
                     `fleet_resource_metal` = 0,
                     `fleet_resource_crystal` = 0,
                     `fleet_resource_deuterium` = 0,
                     `fleet_fuel` = {$fuel},
                     `fleet_target_owner` = 0,
                     `fleet_group` = '0',
                     `fleet_mess` = 0,
                     `fleet_creation` = {$now}"
                );
                if (!$okInsert) {
                    return null;
                }
                $fleetId = (int) $db->insert_id;
                if ($fleetId <= 0) {
                    return null;
                }

                $okShips = $db->query(
                    "UPDATE `{$prefix}ships`
                     SET `ship_colony_ship` = `ship_colony_ship` - 1
                     WHERE `ship_planet_id` = {$sourcePlanetId}
                     LIMIT 1"
                );
                if (!$okShips) {
                    return null;
                }

                if ($fuel > 0) {
                    $okPlanet = $db->query(
                        "UPDATE `{$prefix}planets`
                         SET `planet_deuterium` = GREATEST(0, `planet_deuterium` - {$fuel})
                         WHERE `planet_id` = {$sourcePlanetId}
                         LIMIT 1"
                    );
                    if (!$okPlanet) {
                        return null;
                    }
                }

                return $fleetId;
            }
        );

        if ($fleetId === null) {
            return null;
        }

        return sprintf(
            'colonize: %d:%d:%d -> %d:%d:%d (eta %ds, fuel %d)',
            $startGalaxy,
            $startSystem,
            $startPlanet,
            $endGalaxy,
            $endSystem,
            $endPlanet,
            $duration,
            $fuel
        );
    }
}
