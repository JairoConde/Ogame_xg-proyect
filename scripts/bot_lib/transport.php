<?php

declare(strict_types=1);

/**
 * Bot purposeful transport module.
 *
 * Replaces the old tryTransportToHomePlanet (which always sent surplus
 * to the home planet on a fixed 1800s/0-fuel ride) with a smarter, multi-
 * destination, demand-driven transport system.
 *
 * Core idea: a transport only happens when there is a *reason*. Reasons,
 * in priority order:
 *
 *   1. The destination has a queued building/research/hangar item it cannot
 *      currently afford. The source ships exactly what's missing (capped by
 *      its surplus and cargo capacity).
 *   2. The destination is a "mil" or "tech" role planet with significant
 *      storage headroom and the source is an "eco" role with surplus.
 *   3. As a fallback when the above produce nothing, consolidate to the
 *      home planet (the old behavior) but only if surplus is significant.
 *
 * Travel uses real game formulas (botTravelEstimate) for duration & fuel.
 *
 * Per-planet cooldown is stored in bot_state.bot_quirks JSON under
 * key 'transport_cooldowns' (map of planetId => timestamp).
 *
 * Public entry points:
 *   - botTransportRunPurposeful(): orchestrator, called once per user/loop.
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/strategy.php';
require_once __DIR__ . '/safety.php';
require_once __DIR__ . '/travel.php';
require_once __DIR__ . '/personality.php';

if (!defined('BOT_TRANSPORT_COOLDOWN_BASE_SECONDS')) {
    // Minimum gap between two transports leaving the same planet. Personality
    // adds noise (defensor longer, flotero shorter).
    define('BOT_TRANSPORT_COOLDOWN_BASE_SECONDS', 900); // 15 min
}

if (!defined('BOT_TRANSPORT_MIN_PAYLOAD')) {
    // Don't bother sending tiny transports; they create log noise without
    // helping the destination meaningfully.
    define('BOT_TRANSPORT_MIN_PAYLOAD', 30_000);
}

if (!defined('BOT_TRANSPORT_MAX_PER_LOOP_PER_USER')) {
    // Cap how many transports a single user emits per loop, otherwise a
    // freshly-fed empire can saturate the fleet table on a single tick.
    define('BOT_TRANSPORT_MAX_PER_LOOP_PER_USER', 3);
}

if (!function_exists('botTransportPersonalityCooldown')) {
    function botTransportPersonalityCooldown(string $personality): int
    {
        switch ($personality) {
            case 'flotero':
            case 'cazador':
                return BOT_TRANSPORT_COOLDOWN_BASE_SECONDS - 300; // 10 min
            case 'defensor':
                return BOT_TRANSPORT_COOLDOWN_BASE_SECONDS + 900; // 30 min
            case 'tecnologico':
                return BOT_TRANSPORT_COOLDOWN_BASE_SECONDS + 300; // 20 min
            default:
                return BOT_TRANSPORT_COOLDOWN_BASE_SECONDS;
        }
    }
}

if (!function_exists('botTransportPersonalityReserveRatio')) {
    /**
     * Fraction of stored resources a planet must keep at home as "reserve"
     * before the rest is considered surplus. Higher = more conservative.
     *
     * Roles come straight from bot_state.bot_planet_roles, which uses the
     * same vocabulary as personality.php: main / metal / crystal /
     * industrial / military / support. Anything else falls back to a
     * neutral 0.20.
     */
    function botTransportPersonalityReserveRatio(string $personality, string $planetRole): float
    {
        $byRole = [
            'main' => 0.40, // home: keep a comfortable buffer
            'military' => 0.50, // hoards: consumes its own for fleet/defense
            'industrial' => 0.35, // builds expensive things, needs reserves
            'metal' => 0.10, // raw producer, ships almost everything
            'crystal' => 0.10,
            'support' => 0.20, // mixed role
        ];
        $base = $byRole[$planetRole] ?? 0.20;
        switch ($personality) {
            case 'defensor':
                return min(0.80, $base + 0.20);
            case 'minero':
                return max(0.05, $base - 0.05);
            case 'flotero':
            case 'cazador':
                return max(0.10, $base - 0.05);
            default:
                return $base;
        }
    }
}

if (!function_exists('botTransportRoleIsSenderLike')) {
    /**
     * "Sender-like" roles are economic planets that produce more than they
     * use locally; they prefer shipping surplus out to a busier sibling.
     */
    function botTransportRoleIsSenderLike(string $role): bool
    {
        return in_array($role, ['metal', 'crystal', 'support'], true);
    }
}

if (!function_exists('botTransportRoleIsReceiverLike')) {
    /**
     * "Receiver-like" roles are heavy consumers (military and industrial)
     * that benefit most from inbound resources when they have no specific
     * queue need.
     */
    function botTransportRoleIsReceiverLike(string $role): bool
    {
        return in_array($role, ['military', 'industrial'], true);
    }
}

if (!function_exists('botTransportLoadCooldowns')) {
    /**
     * Returns the per-planet cooldown timestamps from bot_state quirks.
     *
     * @return array<int, int>
     */
    function botTransportLoadCooldowns(array $state): array
    {
        $quirks = $state['bot_quirks'] ?? null;
        if (!is_array($quirks)) {
            return [];
        }
        $cd = $quirks['transport_cooldowns'] ?? null;
        if (!is_array($cd)) {
            return [];
        }
        $out = [];
        foreach ($cd as $k => $v) {
            $out[(int) $k] = (int) $v;
        }

        return $out;
    }
}

if (!function_exists('botTransportSaveCooldowns')) {
    /**
     * Persist updated cooldown map back to bot_state.bot_quirks.
     *
     * @param array<int, int> $cooldowns
     */
    function botTransportSaveCooldowns(mysqli $db, string $prefix, array &$state, array $cooldowns): void
    {
        $quirks = $state['bot_quirks'] ?? null;
        if (!is_array($quirks)) {
            $quirks = [];
        }
        // Drop entries older than 24h to keep the JSON small.
        $threshold = time() - 86400;
        $cleaned = [];
        foreach ($cooldowns as $k => $v) {
            if ((int) $v >= $threshold) {
                $cleaned[(int) $k] = (int) $v;
            }
        }
        $quirks['transport_cooldowns'] = $cleaned;
        $state['bot_quirks'] = $quirks;

        if (empty($state['__synthetic'])) {
            saveBotStateFields($db, $prefix, (int) $state['bot_user_id'], [
                'bot_quirks' => $quirks,
            ]);
        }
    }
}

if (!function_exists('botTransportNextQueuedBuildingCost')) {
    /**
     * Inspect a planet's building queue ('xxxId,Lvl;...') and return the
     * cost (metal, crystal, deuterium) of the FIRST item that the planet
     * cannot currently afford. Returns null if the queue is empty or every
     * queued step is already covered by stored resources.
     *
     * @param array<string, mixed> $planet
     * @param array<int, array<string, int|float>> $pricelist
     * @return array{metal:int, crystal:int, deuterium:int}|null
     */
    function botTransportNextQueuedBuildingCost(array $planet, array $pricelist): ?array
    {
        $queue = (string) ($planet['planet_b_building_id'] ?? '');
        if ($queue === '' || $queue === '0') {
            return null;
        }
        $items = array_values(array_filter(explode(';', $queue), static fn (string $s): bool => $s !== ''));
        if (empty($items)) {
            return null;
        }
        $parts = explode(',', $items[0]);
        $itemId = isset($parts[0]) ? (int) $parts[0] : 0;
        $targetLevel = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($itemId <= 0 || $targetLevel <= 0) {
            return null;
        }
        if (!isset($pricelist[$itemId])) {
            return null;
        }
        // Cost of the level being built right now is for ($targetLevel) i.e.
        // computed against $targetLevel - 1 baseline.
        if (!function_exists('nextLevelCost')) {
            return null;
        }
        $cost = nextLevelCost($pricelist[$itemId], max(0, $targetLevel - 1));
        $metal = (int) max(0, ($cost['metal'] ?? 0) - (float) ($planet['planet_metal'] ?? 0));
        $crystal = (int) max(0, ($cost['crystal'] ?? 0) - (float) ($planet['planet_crystal'] ?? 0));
        $deut = (int) max(0, ($cost['deuterium'] ?? 0) - (float) ($planet['planet_deuterium'] ?? 0));
        if ($metal + $crystal + $deut <= 0) {
            return null;
        }

        return ['metal' => $metal, 'crystal' => $crystal, 'deuterium' => $deut];
    }
}

if (!function_exists('botTransportNextQueuedHangarCost')) {
    /**
     * Same idea as botTransportNextQueuedBuildingCost but for the hangar
     * (ships/defense) queue. The queue format here is 'shipId,amount;...'.
     *
     * @param array<string, mixed> $planet
     * @param array<int, array<string, int|float>> $pricelist
     * @return array{metal:int, crystal:int, deuterium:int}|null
     */
    function botTransportNextQueuedHangarCost(array $planet, array $pricelist): ?array
    {
        $queue = (string) ($planet['planet_b_hangar_id'] ?? '');
        if ($queue === '' || $queue === '0') {
            return null;
        }
        $items = array_values(array_filter(explode(';', $queue), static fn (string $s): bool => $s !== ''));
        if (empty($items)) {
            return null;
        }
        $parts = explode(',', $items[0]);
        $itemId = isset($parts[0]) ? (int) $parts[0] : 0;
        $amount = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($itemId <= 0 || $amount <= 0) {
            return null;
        }
        if (!isset($pricelist[$itemId])) {
            return null;
        }
        $price = $pricelist[$itemId];
        $metalNeed = (int) ((float) ($price['metal'] ?? 0) * $amount);
        $crystalNeed = (int) ((float) ($price['crystal'] ?? 0) * $amount);
        $deutNeed = (int) ((float) ($price['deuterium'] ?? 0) * $amount);
        $metal = (int) max(0, $metalNeed - (float) ($planet['planet_metal'] ?? 0));
        $crystal = (int) max(0, $crystalNeed - (float) ($planet['planet_crystal'] ?? 0));
        $deut = (int) max(0, $deutNeed - (float) ($planet['planet_deuterium'] ?? 0));
        if ($metal + $crystal + $deut <= 0) {
            return null;
        }

        return ['metal' => $metal, 'crystal' => $crystal, 'deuterium' => $deut];
    }
}

if (!function_exists('botTransportPlanetNeed')) {
    /**
     * Returns the merged "need" array for a planet. Aggregates building
     * queue need + hangar queue need (whichever is non-zero) so a single
     * transport plan can cover both pending costs.
     *
     * @param array<string, mixed> $planet
     * @param array<int, array<string, int|float>> $pricelist
     * @return array{metal:int, crystal:int, deuterium:int, sources:array<int, string>}|null
     */
    function botTransportPlanetNeed(array $planet, array $pricelist): ?array
    {
        $building = botTransportNextQueuedBuildingCost($planet, $pricelist);
        $hangar = botTransportNextQueuedHangarCost($planet, $pricelist);
        if ($building === null && $hangar === null) {
            return null;
        }
        $merged = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'sources' => []];
        if ($building !== null) {
            $merged['metal'] += $building['metal'];
            $merged['crystal'] += $building['crystal'];
            $merged['deuterium'] += $building['deuterium'];
            $merged['sources'][] = 'building';
        }
        if ($hangar !== null) {
            $merged['metal'] += $hangar['metal'];
            $merged['crystal'] += $hangar['crystal'];
            $merged['deuterium'] += $hangar['deuterium'];
            $merged['sources'][] = 'hangar';
        }

        return $merged;
    }
}

if (!function_exists('botTransportPlanetSurplus')) {
    /**
     * Returns how much of each resource a planet can afford to ship away
     * without compromising its own short-term plans.
     *
     * Logic:
     *   - Subtract a "personality reserve" (% of stored).
     *   - Subtract any pending queue-need for itself (so a planet that
     *     can't afford its own next building doesn't ship resources out).
     *   - Subtract a small safety floor (so we never ship below ~10k of
     *     each).
     *
     * @param array<string, mixed> $planet
     * @param array<int, array<string, int|float>> $pricelist
     * @return array{metal:int, crystal:int, deuterium:int}
     */
    function botTransportPlanetSurplus(array $planet, array $pricelist, string $personality, string $role): array
    {
        $metal = (float) ($planet['planet_metal'] ?? 0);
        $crystal = (float) ($planet['planet_crystal'] ?? 0);
        $deut = (float) ($planet['planet_deuterium'] ?? 0);

        $reserveRatio = botTransportPersonalityReserveRatio($personality, $role);
        $metalReserve = $metal * $reserveRatio;
        $crystalReserve = $crystal * $reserveRatio;
        $deutReserve = $deut * $reserveRatio;

        $own = botTransportPlanetNeed($planet, $pricelist);
        $ownMetal = $own !== null ? (float) $own['metal'] : 0.0;
        $ownCrystal = $own !== null ? (float) $own['crystal'] : 0.0;
        $ownDeut = $own !== null ? (float) $own['deuterium'] : 0.0;

        $floor = 10_000;

        $surplusMetal = max(0.0, $metal - $metalReserve - $ownMetal - $floor);
        $surplusCrystal = max(0.0, $crystal - $crystalReserve - $ownCrystal - $floor);
        $surplusDeut = max(0.0, $deut - $deutReserve - $ownDeut - $floor);

        return [
            'metal' => (int) floor($surplusMetal),
            'crystal' => (int) floor($surplusCrystal),
            'deuterium' => (int) floor($surplusDeut),
        ];
    }
}

if (!function_exists('botTransportFleetByPlanet')) {
    /**
     * Reads the ships table once and returns a map planetId =>
     * [small => count, big => count].
     *
     * @param array<int, int> $planetIds
     * @return array<int, array{small:int, big:int}>
     */
    function botTransportFleetByPlanet(mysqli $db, string $prefix, array $planetIds): array
    {
        if (empty($planetIds)) {
            return [];
        }
        $idList = implode(',', array_map('intval', $planetIds));
        $result = $db->query(
            "SELECT `ship_planet_id`, `ship_small_cargo_ship`, `ship_big_cargo_ship`
             FROM `{$prefix}ships`
             WHERE `ship_planet_id` IN ({$idList})"
        );
        $out = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $out[(int) $row['ship_planet_id']] = [
                    'small' => (int) $row['ship_small_cargo_ship'],
                    'big' => (int) $row['ship_big_cargo_ship'],
                ];
            }
            $result->free();
        }

        return $out;
    }
}

if (!function_exists('botTransportRolesMap')) {
    /**
     * Returns map planetId => role string from bot_state.bot_planet_roles.
     * Roles use personality.php vocabulary (main/metal/crystal/industrial/
     * military/support); unmapped planets default to 'support' (neutral).
     *
     * @param array<int, array<string, mixed>> $planets
     * @return array<int, string>
     */
    function botTransportRolesMap(array $state, array $planets): array
    {
        $roles = is_array($state['bot_planet_roles'] ?? null) ? $state['bot_planet_roles'] : [];
        $out = [];
        foreach ($planets as $planet) {
            $pid = (int) $planet['planet_id'];
            $role = is_string($roles[(string) $pid] ?? null) ? $roles[(string) $pid] : 'support';
            $out[$pid] = $role;
        }

        return $out;
    }
}

if (!function_exists('botTransportRunPurposeful')) {
    /**
     * Public entry point. Computes which planet should ship resources to
     * which other planet for each owned planet, executes the resulting
     * plans (up to BOT_TRANSPORT_MAX_PER_LOOP_PER_USER) and returns the
     * log lines.
     *
     * @param array<int, array<string, mixed>> $planets
     * @param array<string, mixed> $researchRow
     * @param array<int, array<string, int|float>> $pricelist
     * @param array<string, mixed> $state
     * @return array<int, string>
     */
    function botTransportRunPurposeful(
        mysqli $db,
        string $prefix,
        array $user,
        array $planets,
        array $homePlanet,
        array $researchRow,
        array $pricelist,
        array &$state,
        float $universeSpeed
    ): array {
        $logs = [];
        if (count($planets) < 2) {
            return $logs;
        }

        $personality = (string) ($state['bot_personality'] ?? 'minero');
        $cooldowns = botTransportLoadCooldowns($state);
        $cooldownSeconds = botTransportPersonalityCooldown($personality);
        $now = time();

        $planetIds = array_map(static fn (array $p) => (int) $p['planet_id'], $planets);
        $fleetByPlanet = botTransportFleetByPlanet($db, $prefix, $planetIds);
        $rolesMap = botTransportRolesMap($state, $planets);

        // Pre-compute need + surplus for every planet. Avoids O(n^2) repeat
        // work in the matching loop below.
        $needs = [];
        $surplus = [];
        foreach ($planets as $planet) {
            $pid = (int) $planet['planet_id'];
            $role = $rolesMap[$pid] ?? 'eco';
            $needs[$pid] = botTransportPlanetNeed($planet, $pricelist);
            $surplus[$pid] = botTransportPlanetSurplus($planet, $pricelist, $personality, $role);
        }

        // Build a list of candidate plans, then sort by priority.
        $plans = [];
        foreach ($planets as $sourcePlanet) {
            $sourceId = (int) $sourcePlanet['planet_id'];
            // Cooldown gating per source planet.
            if (isset($cooldowns[$sourceId]) && ($now - $cooldowns[$sourceId]) < $cooldownSeconds) {
                continue;
            }
            $availSurplus = $surplus[$sourceId] ?? null;
            if ($availSurplus === null) {
                continue;
            }
            if ($availSurplus['metal'] + $availSurplus['crystal'] + $availSurplus['deuterium'] < BOT_TRANSPORT_MIN_PAYLOAD) {
                continue;
            }
            // Fleet sanity.
            $fleet = $fleetByPlanet[$sourceId] ?? null;
            if ($fleet === null) {
                continue;
            }
            if ($fleet['small'] < 1 && $fleet['big'] < 1) {
                continue;
            }

            // Find the most useful destination.
            $bestDestId = null;
            $bestDestScore = -INF;
            $bestDestNeed = null;
            $bestDestPlanet = null;
            $fallbackDestId = null;
            $fallbackDestPlanet = null;

            foreach ($planets as $destPlanet) {
                $destId = (int) $destPlanet['planet_id'];
                if ($destId === $sourceId) {
                    continue;
                }
                $destNeed = $needs[$destId] ?? null;
                if ($destNeed !== null) {
                    // Direct demand: queue starvation. Score = total deficit
                    // divided by distance penalty.
                    $deficit = $destNeed['metal'] + $destNeed['crystal'] + $destNeed['deuterium'];
                    if ($deficit < BOT_TRANSPORT_MIN_PAYLOAD) {
                        continue;
                    }
                    $distance = botTravelDistance(
                        [
                            'galaxy' => (int) $sourcePlanet['planet_galaxy'],
                            'system' => (int) $sourcePlanet['planet_system'],
                            'planet' => (int) $sourcePlanet['planet_planet'],
                        ],
                        [
                            'galaxy' => (int) $destPlanet['planet_galaxy'],
                            'system' => (int) $destPlanet['planet_system'],
                            'planet' => (int) $destPlanet['planet_planet'],
                        ]
                    );
                    $score = $deficit / max(1.0, log(max(2, $distance)));
                    if ($score > $bestDestScore) {
                        $bestDestScore = $score;
                        $bestDestId = $destId;
                        $bestDestNeed = $destNeed;
                        $bestDestPlanet = $destPlanet;
                    }

                    continue;
                }
                // No direct demand: track the "best fallback" — receiver-
                // like roles (military / industrial) are preferred targets
                // when the source is a sender-like role (metal/crystal/
                // support) with surplus.
                $sourceRole = $rolesMap[$sourceId] ?? 'support';
                $destRole = $rolesMap[$destId] ?? 'support';
                if (botTransportRoleIsSenderLike($sourceRole) && botTransportRoleIsReceiverLike($destRole)) {
                    if ($fallbackDestId === null) {
                        $fallbackDestId = $destId;
                        $fallbackDestPlanet = $destPlanet;
                    }
                }
            }

            // Final fallback: home planet (only if source != home and we
            // still have no destination at all).
            if ($bestDestId === null && $fallbackDestId === null) {
                if ($sourceId !== (int) $homePlanet['planet_id']) {
                    $fallbackDestId = (int) $homePlanet['planet_id'];
                    $fallbackDestPlanet = $homePlanet;
                }
            }

            if ($bestDestId === null && $fallbackDestId !== null) {
                $bestDestId = $fallbackDestId;
                $bestDestPlanet = $fallbackDestPlanet;
                $bestDestNeed = null; // unscored / nominal
                // Lower priority than direct-demand plans.
                $bestDestScore = 1.0;
            }

            if ($bestDestId === null || $bestDestPlanet === null) {
                continue;
            }

            $plans[] = [
                'source' => $sourcePlanet,
                'dest' => $bestDestPlanet,
                'need' => $bestDestNeed,
                'surplus' => $availSurplus,
                'fleet' => $fleet,
                'score' => $bestDestScore,
                'reason' => $bestDestNeed !== null
                    ? ('queue:' . implode('+', $bestDestNeed['sources'] ?? ['queue']))
                    : ($bestDestId === (int) $homePlanet['planet_id'] ? 'consolidate_home' : 'role_balance'),
            ];
        }

        if (empty($plans)) {
            return $logs;
        }

        usort($plans, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $executed = 0;
        $newCooldowns = $cooldowns;
        foreach ($plans as $plan) {
            if ($executed >= BOT_TRANSPORT_MAX_PER_LOOP_PER_USER) {
                break;
            }
            $log = botTransportExecutePlan(
                $db,
                $prefix,
                $user,
                $plan,
                $researchRow,
                $universeSpeed
            );
            if ($log !== null) {
                $logs[] = $log;
                $executed++;
                $newCooldowns[(int) $plan['source']['planet_id']] = $now;
            }
        }

        if ($executed > 0) {
            botTransportSaveCooldowns($db, $prefix, $state, $newCooldowns);
        }

        return $logs;
    }
}

if (!function_exists('botTransportExecutePlan')) {
    /**
     * Realises a single transport plan: picks the cargo mix, computes
     * travel/fuel, validates fuel is affordable, INSERTs the fleet row,
     * decrements ships and resources from the source planet.
     *
     * Returns a log line on success, null if the plan can't be realised.
     *
     * @param array{source:array, dest:array, need:?array, surplus:array, fleet:array, score:float, reason:string} $plan
     */
    function botTransportExecutePlan(
        mysqli $db,
        string $prefix,
        array $user,
        array $plan,
        array $researchRow,
        float $universeSpeed
    ): ?string {
        $source = $plan['source'];
        $dest = $plan['dest'];
        $need = $plan['need'];
        $surplus = $plan['surplus'];
        $fleet = $plan['fleet'];

        // What we'd LIKE to send: min(need, surplus) per resource, or full
        // surplus when there's no specific need.
        if ($need !== null) {
            $sendMetal = min((int) $need['metal'], (int) $surplus['metal']);
            $sendCrystal = min((int) $need['crystal'], (int) $surplus['crystal']);
            $sendDeut = min((int) $need['deuterium'], (int) $surplus['deuterium']);
        } else {
            // Without a specific need, ship surplus 50/30/20 split (more
            // metal because that's what's almost always lacking).
            $totalSurplus = $surplus['metal'] + $surplus['crystal'] + $surplus['deuterium'];
            $sendMetal = (int) floor($totalSurplus * 0.50);
            $sendCrystal = (int) floor($totalSurplus * 0.30);
            $sendDeut = max(0, $totalSurplus - $sendMetal - $sendCrystal);
            // Re-cap by what's actually available per resource.
            $sendMetal = min($sendMetal, (int) $surplus['metal']);
            $sendCrystal = min($sendCrystal, (int) $surplus['crystal']);
            $sendDeut = min($sendDeut, (int) $surplus['deuterium']);
        }

        $payload = $sendMetal + $sendCrystal + $sendDeut;
        if ($payload < BOT_TRANSPORT_MIN_PAYLOAD) {
            return null;
        }

        // Pick cargo mix.
        $mix = botPickCargoMix($payload, (int) $fleet['small'], (int) $fleet['big'], $researchRow);
        if ($mix === null) {
            return null;
        }
        // If the mix has less capacity than the payload (because the fleet
        // can't carry it all), shrink the payload to fit.
        $capacity = (int) $mix['capacity'];
        if ($capacity < $payload) {
            $ratio = $capacity / max(1, $payload);
            $sendMetal = (int) floor($sendMetal * $ratio);
            $sendCrystal = (int) floor($sendCrystal * $ratio);
            $sendDeut = (int) floor($sendDeut * $ratio);
            $payload = $sendMetal + $sendCrystal + $sendDeut;
            if ($payload < BOT_TRANSPORT_MIN_PAYLOAD) {
                return null;
            }
        }

        $shipMix = [];
        if ($mix['big'] > 0) {
            $shipMix[203] = (int) $mix['big'];
        }
        if ($mix['small'] > 0) {
            $shipMix[202] = (int) $mix['small'];
        }
        if (empty($shipMix)) {
            return null;
        }

        // Travel.
        $sourceCoords = [
            'galaxy' => (int) $source['planet_galaxy'],
            'system' => (int) $source['planet_system'],
            'planet' => (int) $source['planet_planet'],
        ];
        $destCoords = [
            'galaxy' => (int) $dest['planet_galaxy'],
            'system' => (int) $dest['planet_system'],
            'planet' => (int) $dest['planet_planet'],
        ];
        $estimate = botTravelEstimate($sourceCoords, $destCoords, $shipMix, $researchRow, $universeSpeed);
        $duration = (int) $estimate['duration'];
        $fuel = (int) $estimate['consumption'];

        // Need enough deuterium to fuel the trip + a small buffer.
        $availDeut = (float) ($source['planet_deuterium'] ?? 0);
        if ($availDeut < $fuel + $sendDeut + 50) {
            // Try shrinking the deuterium payload first.
            $room = (int) max(0, floor($availDeut - $fuel - 50));
            if ($room < 0) {
                return null;
            }
            $sendDeut = min($sendDeut, $room);
            $payload = $sendMetal + $sendCrystal + $sendDeut;
            if ($payload < BOT_TRANSPORT_MIN_PAYLOAD) {
                return null;
            }
        }

        $now = time();
        $userId = (int) $user['user_id'];
        $sourcePlanetId = (int) $source['planet_id'];
        $destOwnerId = (int) ($dest['planet_user_id'] ?? $user['user_id']);

        // FleetsLib::getFleetShipsArray() consumes PHP serialize() data
        // (== unserialize), not the legacy "shipId,count;" string format.
        // Using the wrong format here makes every consumer of this fleet
        // (engine + bot stats) explode on the next loop.
        $shipMix = [];
        if ($mix['big'] > 0) {
            $shipMix[203] = (int) $mix['big'];
        }
        if ($mix['small'] > 0) {
            $shipMix[202] = (int) $mix['small'];
        }
        $fleetArray = serialize($shipMix);
        $fleetAmount = (int) ($mix['big'] + $mix['small']);

        $shipDecrements = [];
        if ($mix['small'] > 0) {
            $shipDecrements[] = '`ship_small_cargo_ship` = `ship_small_cargo_ship` - ' . (int) $mix['small'];
        }
        if ($mix['big'] > 0) {
            $shipDecrements[] = '`ship_big_cargo_ship` = `ship_big_cargo_ship` - ' . (int) $mix['big'];
        }

        $startGalaxy = (int) $source['planet_galaxy'];
        $startSystem = (int) $source['planet_system'];
        $startPlanet = (int) $source['planet_planet'];
        $startType = (int) ($source['planet_type'] ?? 1);
        $endGalaxy = (int) $dest['planet_galaxy'];
        $endSystem = (int) $dest['planet_system'];
        $endPlanet = (int) $dest['planet_planet'];
        $endType = (int) ($dest['planet_type'] ?? 1);
        $endTime = $now + $duration;
        $totalDeutLeft = $sendDeut + $fuel;

        $fleetId = botFleetInsertTransactional(
            $db,
            $prefix,
            $sourcePlanetId,
            function (mysqli $db) use (
                $prefix,
                $userId,
                $fleetAmount,
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
                $sendMetal,
                $sendCrystal,
                $sendDeut,
                $fuel,
                $destOwnerId,
                $sourcePlanetId,
                $shipDecrements,
                $totalDeutLeft
            ): ?int {
                $okInsert = $db->query(
                    "INSERT INTO `{$prefix}fleets` SET
                     `fleet_owner` = {$userId},
                     `fleet_mission` = 3,
                     `fleet_amount` = {$fleetAmount},
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
                     `fleet_resource_metal` = {$sendMetal},
                     `fleet_resource_crystal` = {$sendCrystal},
                     `fleet_resource_deuterium` = {$sendDeut},
                     `fleet_fuel` = {$fuel},
                     `fleet_target_owner` = {$destOwnerId},
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

                if (!empty($shipDecrements)) {
                    $okShips = $db->query(
                        "UPDATE `{$prefix}ships`
                         SET " . implode(', ', $shipDecrements) . "
                         WHERE `ship_planet_id` = {$sourcePlanetId}
                         LIMIT 1"
                    );
                    if (!$okShips) {
                        return null;
                    }
                }

                $okPlanet = $db->query(
                    "UPDATE `{$prefix}planets`
                     SET `planet_metal` = GREATEST(0, `planet_metal` - {$sendMetal}),
                         `planet_crystal` = GREATEST(0, `planet_crystal` - {$sendCrystal}),
                         `planet_deuterium` = GREATEST(0, `planet_deuterium` - {$totalDeutLeft})
                     WHERE `planet_id` = {$sourcePlanetId}
                     LIMIT 1"
                );
                if (!$okPlanet) {
                    return null;
                }

                return $fleetId;
            }
        );

        if ($fleetId === null) {
            return null;
        }

        return sprintf(
            'transport: %d:%d:%d -> %d:%d:%d [%s] m=%d c=%d d=%d ships=%dB+%dS eta=%ds fuel=%d',
            $startGalaxy,
            $startSystem,
            $startPlanet,
            $endGalaxy,
            $endSystem,
            $endPlanet,
            (string) $plan['reason'],
            $sendMetal,
            $sendCrystal,
            $sendDeut,
            (int) $mix['big'],
            (int) $mix['small'],
            $duration,
            $fuel
        );
    }
}
