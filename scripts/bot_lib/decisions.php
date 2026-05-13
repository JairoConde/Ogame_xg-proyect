<?php

declare(strict_types=1);

/**
 * Bot decision engine.
 *
 * Replaces the previous deterministic if-cascade with a weighted-candidate
 * model:
 *
 *   1. Build a list of *candidate actions* the bot could plausibly take
 *      right now on this planet (mine upgrade, defense build, etc.).
 *   2. Each candidate has a weight that depends on:
 *        - Planet state (deficits, fill levels, current levels vs targets).
 *        - Bot personality + archetype (how aggressively it pursues each axis).
 *        - Current rotating focus (eco/military/tech) within the bot's
 *          long-term personality bias.
 *        - Planet role (metal-focus planet weights mines higher, etc.).
 *        - Recent attacks (defense surge, but bounded).
 *        - Quirks (a small probability of off-pattern picks).
 *   3. A weighted random pick (seeded by seed+key+timestamp) selects one.
 *
 * Hard preconditions still take precedence over the weighted system:
 *   - Energy deficit (demand exceeds supply per game rules): forced solar
 *     plant until BOT_ENERGY_SOLAR_PLANT_LEVEL_BEFORE_SAT_PRIORITY, then
 *     solar satellites when the hangar can queue them.
 *   - Storage above panic threshold: forced storage upgrade.
 *   - Home planet with no research lab yet (built+queued) below effective level 3:
 *     force lab upgrades before other economy picks so tryResearch is not blocked.
 *   - With astrophysics ≥1 and zero colony ships empire-wide (built+queue):
 *     runs before energy/storage/slow: upgrade hangar until effective level ≥4,
 *     then queue one colony ship (208) when impulse ≥3 and the yard can build.
 * These overrides keep the bot from making nonsense choices when the planet
 * is in obvious trouble.
 *
 * The function returns either an action array (the legacy structure used by
 * applyPlanetAction) or null if nothing makes sense to do (caller should
 * skip the planet).
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/heat.php';
require_once __DIR__ . '/personality.php';
require_once __DIR__ . '/safety.php';
require_once __DIR__ . '/util.php';

if (!function_exists('botSlowProductionBottleneckOverride')) {
    /**
     * When building or hangar queues are projected past
     * BOT_SLOW_QUEUE_THRESHOLD_SECONDS, bias toward Robotics, Hangar, or
     * Nanites depending on which queue is slowest.
     *
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $_profile Reserved for future tuning hooks.
     * @return array{type:string, id:int, column:string, amount:int}|null
     */
    function botSlowProductionBottleneckOverride(array $planet, array $_profile): ?array
    {
        $threshold = defined('BOT_SLOW_QUEUE_THRESHOLD_SECONDS')
            ? (int) BOT_SLOW_QUEUE_THRESHOLD_SECONDS
            : 3600;
        $now = time();
        $pricelist = $GLOBALS['pricelist'] ?? [];
        if (!is_array($pricelist)) {
            $pricelist = [];
        }
        $universeSpeed = (float) ($GLOBALS['bot_universe_speed'] ?? 1.0);

        if (botPlanetEnergyIsDeficit($planet)) {
            return null;
        }

        $buildRemain = botPlanetBuildingQueueRemainingMaxSeconds($planet, $now);
        $hangarEst = botPlanetHangarFrontBatchEstimatedSeconds($planet, $pricelist, $universeSpeed);

        if ($buildRemain < $threshold && $hangarEst < $threshold) {
            return null;
        }

        $robots = (int) ($planet['building_robot_factory'] ?? 0);
        $hangar = (int) ($planet['building_hangar'] ?? 0);

        $pickNano = static function () use ($robots, $planet): ?array {
            $nanites = (int) ($planet['building_nano_factory'] ?? 0);
            if ($robots < 10 || botCapBuildingTargetReached($nanites)) {
                return null;
            }

            return ['type' => 'building', 'id' => 15, 'column' => 'building_nano_factory', 'amount' => 1];
        };

        if ($buildRemain >= $threshold && $buildRemain >= $hangarEst) {
            $n = $pickNano();
            if ($n !== null) {
                return $n;
            }
            if (!botCapBuildingTargetReached($robots)) {
                return ['type' => 'building', 'id' => 14, 'column' => 'building_robot_factory', 'amount' => 1];
            }

            return null;
        }

        if ($hangarEst >= $threshold) {
            $n = $pickNano();
            if ($n !== null) {
                return $n;
            }
            if (!botCapBuildingTargetReached($hangar) && !botPlanetFacilityUpgradeBlocksHangar($planet, $now)) {
                return ['type' => 'building', 'id' => 21, 'column' => 'building_hangar', 'amount' => 1];
            }
            if (!botCapBuildingTargetReached($robots)) {
                return ['type' => 'building', 'id' => 14, 'column' => 'building_robot_factory', 'amount' => 1];
            }
        }

        return null;
    }
}

if (!function_exists('botEffectiveUnitCount')) {
    /**
     * Returns the count of a ship/defense type that the bot should consider
     * "already accounted for" when deciding whether to build more: the
     * actual built count (`$planet[$column]`) plus any amount currently
     * pending in the planet's hangar queue.
     *
     * Without this, a bot running multiple actions per loop will keep
     * re-deciding the same unit-build action until resources run out,
     * because the in-game count only updates once the queue completes.
     *
     * Falls back to just $planet[$column] if `getQueuedHangarAmount` is not
     * available (i.e. when the module is sourced standalone).
     */
    function botEffectiveUnitCount(array $planet, int $itemId, string $column): int
    {
        $current = (int) ($planet[$column] ?? 0);
        if (function_exists('getQueuedHangarAmount')) {
            $current += getQueuedHangarAmount($planet, $itemId);
        }

        return $current;
    }
}

if (!function_exists('botHomeLaboratoryOverride')) {
    /**
     * Research checks building levels on the user's home planet; the weighted
     * candidate model rarely picks the lab early. Ensure at least one lab level
     * exists there so getResearchRequirementBlocker can clear (e.g. astrophysics
     * needs lab ≥3 per objects_collection).
     *
     * @param array<string, mixed> $planet
     */
    function botHomeLaboratoryOverride(array $planet, ?int $homePlanetId): ?array
    {
        if ($homePlanetId === null || $homePlanetId <= 0) {
            return null;
        }
        if ((int) ($planet['planet_id'] ?? 0) !== $homePlanetId) {
            return null;
        }
        if (!isset($planet['building_laboratory'])) {
            return null;
        }
        if (botEffectiveBuildingUpgradeLevel($planet, 31, 'building_laboratory') >= 3) {
            return null;
        }
        if (botPlanetEnergyIsDeficit($planet)) {
            return null;
        }
        $built = (int) ($planet['building_laboratory'] ?? 0);
        if (botCapBuildingTargetReached($built)) {
            return null;
        }

        return ['type' => 'building', 'id' => 31, 'column' => 'building_laboratory', 'amount' => 1];
    }
}

if (!function_exists('botColonyShipOverride')) {
    /**
     * If the account has astrophysics but no colony ship anywhere (built or
     * hangar-queued), steer this planet toward one 208: first raise hangar to
     * effective level ≥4 (objects id 21), then require impulse drive ≥3 and a
     * free shipyard slot, same rules as objects_collection 208.
     *
     * @param array<string, mixed> $planet
     * @param array<string, mixed>|null $researchRow
     * @param array<int, array<string, mixed>>|null $allUserPlanets
     */
    function botColonyShipOverride(
        array $planet,
        ?array $researchRow,
        ?array $allUserPlanets
    ): ?array {
        if ($researchRow === null || $allUserPlanets === null || $allUserPlanets === []) {
            return null;
        }
        if ((int) ($researchRow['research_astrophysics'] ?? 0) < 1) {
            return null;
        }
        if (botUserEffectiveColonyShipTotal($allUserPlanets) >= 1) {
            return null;
        }
        if (!isset($planet['ship_colony_ship'])) {
            return null;
        }

        $hangarEff = botEffectiveBuildingUpgradeLevel($planet, 21, 'building_hangar');
        $hangarBuilt = (int) ($planet['building_hangar'] ?? 0);

        // objects_collection: 208 => [21 => 4, 117 => 3]
        if ($hangarEff < 4) {
            if (botPlanetEnergyIsDeficit($planet)) {
                return null;
            }
            if (botCapBuildingTargetReached($hangarBuilt)) {
                return null;
            }
            if (botPlanetFacilityUpgradeBlocksHangar($planet)) {
                return null;
            }

            return ['type' => 'building', 'id' => 21, 'column' => 'building_hangar', 'amount' => 1];
        }

        if ((int) ($researchRow['research_impulse_drive'] ?? 0) < 3) {
            return null;
        }
        if (!botPlanetCanQueueShipyardUnits($planet)) {
            return null;
        }

        return ['type' => 'ship', 'id' => 208, 'column' => 'ship_colony_ship', 'amount' => 1];
    }
}

if (!function_exists('botDecideAction')) {
    /**
     * Top-level decision entry point.
     *
     * @param array<string, mixed> $planet Planet row (with buildings/ships/defenses joined).
     * @param array<string, mixed> $profile Old-style profile (eco_focus, defense_focus, etc.).
     * @param array<string, mixed> $state bot_state row.
     * @param array<string, mixed>|null $researchRow User research row; when null, colony-ship path is skipped.
     * @param array<int, array<string, mixed>>|null $allUserPlanets All planets for this user this tick; when null, colony-ship path is skipped.
     * @param int|null $homePlanetId User home planet id for research-lab bootstrap; when null, home-lab override is skipped.
     * @return array{type:string, id:int, column:string, amount:int}|null
     */
    function botDecideAction(
        array $planet,
        array $profile,
        array $state,
        ?array $researchRow = null,
        ?array $allUserPlanets = null,
        ?int $homePlanetId = null
    ): ?array {
        // ---- Home research lab (before colony/eco; tryResearch uses home row) ---
        $override = botHomeLaboratoryOverride($planet, $homePlanetId);
        if ($override !== null) {
            return $override;
        }

        // ---- Colony ship path (before eco/storage/slow so it is not starved) ---
        $override = botColonyShipOverride($planet, $researchRow, $allUserPlanets);
        if ($override !== null) {
            return $override;
        }

        // ---- Hard overrides (planet integrity) -----------------------------
        $override = botEnergyOverride($planet);
        if ($override !== null) {
            return $override;
        }

        $override = botStorageOverride($planet, $state);
        if ($override !== null) {
            return $override;
        }

        $slow = botSlowProductionBottleneckOverride($planet, $profile);
        if ($slow !== null) {
            return $slow;
        }

        // ---- Weighted candidates -------------------------------------------
        $candidates = botBuildCandidates($planet, $profile, $state, $researchRow, $allUserPlanets);
        if (empty($candidates)) {
            return null;
        }

        $seed = (int) ($state['bot_seed'] ?? 0);
        // Include time-based salt so the same bot can pick differently across
        // ticks (we want variety per loop, not per state). Per-bot stability
        // is preserved across short windows because we bucketize the time.
        $bucket = (int) floor(time() / 600); // 10-minute bucket
        $picked = botRngWeightedPick($seed, 'tick:' . $bucket . ':p:' . (int) $planet['planet_id'], $candidates);

        if (!is_array($picked)) {
            return null;
        }

        // Apply caps last to avoid generating capped picks.
        if (botCapBuildingAction($picked, $planet) !== null) {
            // Try a fallback: pick the highest-weight legal candidate.
            $sorted = $candidates;
            usort($sorted, static fn (array $a, array $b): int => ($b['weight'] <=> $a['weight']));
            foreach ($sorted as $entry) {
                $candidate = $entry['value'] ?? null;
                if (is_array($candidate) && botCapBuildingAction($candidate, $planet) === null) {
                    return $candidate;
                }
            }

            return null;
        }

        return $picked;
    }
}

if (!function_exists('botEnergyOverride')) {
    /**
     * If the planet is in an energy deficit, force the most appropriate fix:
     *  - Below BOT_ENERGY_SOLAR_PLANT_LEVEL_BEFORE_SAT_PRIORITY: upgrade
     *    solar plant when not capped.
     *  - At or above that level: build solar satellites in chunks (when the
     *    hangar can queue them), falling back to plant or prerequisites.
     */
    function botEnergyOverride(array $planet): ?array
    {
        if (!botPlanetEnergyIsDeficit($planet)) {
            return null;
        }

        $deficit = botPlanetEnergyDeficitPoints($planet);
        $solarPlant = (int) ($planet['building_solar_plant'] ?? 0);
        $tempMax = (int) ($planet['planet_temp_max'] ?? 0);
        $satEnergy = max(1, (int) floor(($tempMax + 140) / 6));
        $plantFloor = (int) BOT_ENERGY_SOLAR_PLANT_LEVEL_BEFORE_SAT_PRIORITY;

        // Until the plant reaches the floor, prefer upgrading it; after that,
        // satellites are the primary lever (when the hangar can queue them).
        if ($solarPlant < $plantFloor && !botCapBuildingTargetReached($solarPlant)) {
            return ['type' => 'building', 'id' => 4, 'column' => 'building_solar_plant', 'amount' => 1];
        }

        // Otherwise build a sensible chunk of satellites to cover the deficit.
        // Discount whatever's already pending in the hangar queue so we
        // don't keep stacking identical orders across consecutive action
        // slots within the same loop.
        $current = (int) ($planet['ship_solar_satellite'] ?? 0);
        $alreadyQueued = function_exists('getQueuedHangarAmount')
            ? getQueuedHangarAmount($planet, 212)
            : 0;
        $effective = $current + $alreadyQueued;
        $effectiveEnergy = $effective * $satEnergy;
        $remainingDeficit = max(0, $deficit - $effectiveEnergy);
        if ($remainingDeficit <= 0) {
            // Queued satellites may cover the *eventual* gap, but production
            // stats stay negative until they exist — keep pushing small fixes.
            if (botPlanetEnergyIsDeficit($planet)) {
                if ($solarPlant < $plantFloor && !botCapBuildingTargetReached($solarPlant)) {
                    return ['type' => 'building', 'id' => 4, 'column' => 'building_solar_plant', 'amount' => 1];
                }
                if (botPlanetCanQueueShipyardUnits($planet)) {
                    $tiny = min(5, max(1, (int) ceil($deficit / max(1, $satEnergy))));
                    $allowed = botCapClampUnitAmount($effective, $tiny);
                    if ($allowed > 0) {
                        return ['type' => 'ship', 'id' => 212, 'column' => 'ship_solar_satellite', 'amount' => $allowed];
                    }
                }
            }

            return null;
        }

        $needed = max(1, (int) ceil($remainingDeficit / $satEnergy));
        $needed = min($needed, 30); // never chunk huge amounts at once.

        $allowed = botCapClampUnitAmount($effective, $needed);
        if ($allowed <= 0) {
            // Cannot build more sats; try upgrading solar plant if there's
            // any room left.
            if (!botCapBuildingTargetReached($solarPlant)) {
                return ['type' => 'building', 'id' => 4, 'column' => 'building_solar_plant', 'amount' => 1];
            }

            return null;
        }

        if (!botPlanetCanQueueShipyardUnits($planet)) {
            $rf = (int) ($planet['building_robot_factory'] ?? 0);
            if ($rf < 2 && !botCapBuildingTargetReached($rf)) {
                return ['type' => 'building', 'id' => 14, 'column' => 'building_robot_factory', 'amount' => 1];
            }
            if (!botCapBuildingTargetReached((int) ($planet['building_hangar'] ?? 0))
                && !botPlanetFacilityUpgradeBlocksHangar($planet)) {
                return ['type' => 'building', 'id' => 21, 'column' => 'building_hangar', 'amount' => 1];
            }
            if (!botCapBuildingTargetReached($solarPlant)) {
                return ['type' => 'building', 'id' => 4, 'column' => 'building_solar_plant', 'amount' => 1];
            }

            return null;
        }

        return ['type' => 'ship', 'id' => 212, 'column' => 'ship_solar_satellite', 'amount' => $allowed];
    }
}

if (!function_exists('botStorageOverride')) {
    function botStorageOverride(array $planet, array $state): ?array
    {
        $resourceMultiplier = (float) ($GLOBALS['resourceMultiplier'] ?? 1.0);
        $metalMax = max(1, maxStorageFromLevel((int) $planet['building_metal_store'], $resourceMultiplier));
        $crystalMax = max(1, maxStorageFromLevel((int) $planet['building_crystal_store'], $resourceMultiplier));
        $deuteriumMax = max(1, maxStorageFromLevel((int) $planet['building_deuterium_tank'], $resourceMultiplier));

        $fill = [
            'building_metal_store' => (float) $planet['planet_metal'] / $metalMax,
            'building_crystal_store' => (float) $planet['planet_crystal'] / $crystalMax,
            'building_deuterium_tank' => (float) $planet['planet_deuterium'] / $deuteriumMax,
        ];
        arsort($fill);
        $worstColumn = (string) array_key_first($fill);
        $worstFill = (float) ($fill[$worstColumn] ?? 0);

        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
        $panic = (float) ($quirks['storage_panic_threshold'] ?? 0.9);

        // While the bot has active cargo pressure, raise the panic threshold
        // so the storage override only fires on near-overflow (>= 0.98).
        // Otherwise the bot would keep stacking storage upgrades on a planet
        // that is sitting at 90 % fill instead of building the big cargos
        // that would actually drain it (storing AND looting more elsewhere).
        // We still respect "real" overflow as a safety net so we never lose
        // production for hours.
        if (
            function_exists('botCargoPressureActive')
            && botCargoPressureActive($state, time())
        ) {
            $panic = max($panic, 0.98);
        }

        if ($worstFill < $panic) {
            return null;
        }

        $idByColumn = [
            'building_metal_store' => 22,
            'building_crystal_store' => 23,
            'building_deuterium_tank' => 24,
        ];
        $currentLevel = (int) ($planet[$worstColumn] ?? 0);
        if (botCapBuildingTargetReached($currentLevel)) {
            return null;
        }

        return ['type' => 'building', 'id' => $idByColumn[$worstColumn], 'column' => $worstColumn, 'amount' => 1];
    }
}

if (!function_exists('botBuildCandidates')) {
    /**
     * Builds the list of candidate actions with weights.
     *
     * @param array<string, mixed>|null $researchRow Used to boost lab weight when active research is long.
     * @param array<int, array<string, mixed>>|null $allUserPlanets Planet rows (must include planet doing research).
     * @return array<int, array{weight:float, value:array{type:string, id:int, column:string, amount:int}}>
     */
    function botBuildCandidates(
        array $planet,
        array $profile,
        array $state,
        ?array $researchRow = null,
        ?array $allUserPlanets = null
    ): array {
        $candidates = [];
        $personality = (string) ($state['bot_personality'] ?? 'minero');
        $archetype = (string) ($state['bot_archetype'] ?? 'balanced');
        $focus = (string) ($state['bot_current_focus'] ?? 'eco');
        $role = botGetPlanetRole($state, (int) $planet['planet_id']);
        $targets = is_array($state['bot_personal_targets'] ?? null) ? $state['bot_personal_targets'] : [];
        $recipe = is_array($state['bot_defense_recipe'] ?? null) ? $state['bot_defense_recipe'] : [];

        $energyNet = (int) $planet['planet_energy_max'] + (int) $planet['planet_energy_used'];
        $energyCrisis = botPlanetEnergyIsDeficit($planet);

        $archetypeMul = botArchetypeMultipliers($archetype);

        // ---- Mines ----
        $mines = [
            ['col' => 'building_metal_mine',           'id' => 1, 'eco_w' => 4.0, 'role_pref' => 'metal'],
            ['col' => 'building_crystal_mine',         'id' => 2, 'eco_w' => 3.5, 'role_pref' => 'crystal'],
            ['col' => 'building_deuterium_sintetizer', 'id' => 3, 'eco_w' => 2.5, 'role_pref' => null],
        ];
        if (!$energyCrisis) {
            foreach ($mines as $mine) {
                $current = botEffectiveBuildingUpgradeLevel($planet, (int) $mine['id'], (string) $mine['col']);
                $target = (int) ($targets[$mine['col']] ?? 30);
                if ($current >= $target || botCapBuildingTargetReached($current)) {
                    continue;
                }
                $gap = max(0, $target - $current);
                $weight = $mine['eco_w'] * (1 + 0.05 * min(20, $gap));
                if ($focus === 'eco') {
                    $weight *= 1.6;
                } elseif ($focus === 'mil') {
                    $weight *= 0.5;
                }
                if ($personality === 'minero') {
                    $weight *= 1.4;
                }
                if ($role === ($mine['role_pref'] ?? '__none')) {
                    $weight *= 1.6;
                }
                $weight *= $archetypeMul['eco'];

                $candidates[] = [
                    'weight' => $weight,
                    'value' => ['type' => 'building', 'id' => $mine['id'], 'column' => $mine['col'], 'amount' => 1],
                ];
            }
        }

        // ---- Storages: smaller weight unless there's a clear lag ----
        $stores = [
            ['col' => 'building_metal_store',    'id' => 22, 'mineCol' => 'building_metal_mine', 'mineId' => 1],
            ['col' => 'building_crystal_store',  'id' => 23, 'mineCol' => 'building_crystal_mine', 'mineId' => 2],
            ['col' => 'building_deuterium_tank', 'id' => 24, 'mineCol' => 'building_deuterium_sintetizer', 'mineId' => 3],
        ];
        if (!$energyCrisis) {
            foreach ($stores as $store) {
                $current = (int) ($planet[$store['col']] ?? 0);
                if (botCapBuildingTargetReached($current)) {
                    continue;
                }
                $mineLevel = botEffectiveBuildingUpgradeLevel($planet, (int) $store['mineId'], (string) $store['mineCol']);
                // Encourage storage when storage trails the mine by 6+ levels.
                $lag = max(0, ($mineLevel - 4) - $current);
                if ($lag <= 0) {
                    continue;
                }
                $weight = 1.5 * $lag;
                $candidates[] = [
                    'weight' => $weight,
                    'value' => ['type' => 'building', 'id' => $store['id'], 'column' => $store['col'], 'amount' => 1],
                ];
            }
        }

        // ---- Energy: build solar even when not in deficit (proactive) ----
        $solar = botEffectiveBuildingUpgradeLevel($planet, 4, 'building_solar_plant');
        $solarTarget = (int) ($targets['building_solar_plant'] ?? 30);
        if ($solar < $solarTarget && !botCapBuildingTargetReached($solar)) {
            $tightness = !$energyCrisis && $energyNet > 0 ? min(1.0, 1.0 / max(1, $energyNet)) * 50 : 0.5;
            if ($energyCrisis) {
                $tightness = 120.0;
            }
            $candidates[] = [
                'weight' => 0.5 + $tightness,
                'value' => ['type' => 'building', 'id' => 4, 'column' => 'building_solar_plant', 'amount' => 1],
            ];
        }

        // ---- Industrial: robotics, hangar, nanites, lab ----
        $industrial = [
            ['col' => 'building_robot_factory', 'id' => 14, 'base' => 1.0, 'role_pref' => 'industrial', 'min' => 0],
            ['col' => 'building_hangar',        'id' => 21, 'base' => 1.5, 'role_pref' => 'industrial', 'min' => 0],
            ['col' => 'building_nano_factory',  'id' => 15, 'base' => 1.2, 'role_pref' => 'industrial', 'min' => 10],
            ['col' => 'building_laboratory',    'id' => 31, 'base' => 1.0, 'role_pref' => null,         'min' => 0],
        ];
        if (!$energyCrisis) {
            foreach ($industrial as $b) {
                $current = botEffectiveBuildingUpgradeLevel($planet, (int) $b['id'], (string) $b['col']);
                $target = (int) ($targets[$b['col']] ?? 12);
                $robotsLevel = botEffectiveBuildingUpgradeLevel($planet, 14, 'building_robot_factory');
                if ($robotsLevel < $b['min']) {
                    continue; // nanites need robotics 10+
                }
                if ($current >= $target || botCapBuildingTargetReached($current)) {
                    continue;
                }
                $weight = $b['base'];
                if ($b['col'] === 'building_laboratory') {
                    $researchPid = (int) ($researchRow['research_current_research'] ?? 0);
                    $rem = botResearchRemainingSeconds($researchRow, $allUserPlanets);
                    // Remaining ≥30 min: weight 2 @30m, 3 @1h, 5 @2h — only on the planet running research.
                    if (
                        $researchPid > 0
                        && (int) ($planet['planet_id'] ?? 0) === $researchPid
                        && $rem >= 1800
                    ) {
                        $weight = 1.0 + (float) (int) ceil($rem / 1800.0);
                    }
                }
                if ($focus === 'tech' && $b['col'] === 'building_laboratory') {
                    $weight *= 1.8;
                }
                if ($focus === 'mil' && in_array($b['col'], ['building_hangar', 'building_robot_factory'], true)) {
                    $weight *= 1.4;
                }
                if ($role === ($b['role_pref'] ?? '__none')) {
                    $weight *= 1.5;
                }
                if ($personality === 'tecnologico' && $b['col'] === 'building_laboratory') {
                    $weight *= 1.5;
                }
                if ($personality === 'flotero' && $b['col'] === 'building_hangar') {
                    $weight *= 1.5;
                }
                $candidates[] = [
                    'weight' => $weight,
                    'value' => ['type' => 'building', 'id' => $b['id'], 'column' => $b['col'], 'amount' => 1],
                ];
            }
        }

        // ---- Defense (using personalized recipe) ----
        if (!$energyCrisis) {
            $defenseCandidates = botDefenseCandidates($planet, $state, $recipe, $focus, $personality, $archetypeMul);
            foreach ($defenseCandidates as $dc) {
                $candidates[] = $dc;
            }

            // ---- Ships: cargo + military mix per personality/role ----
            $shipCandidates = botShipCandidates($planet, $state, $focus, $personality, $role, $archetypeMul);
            foreach ($shipCandidates as $sc) {
                $candidates[] = $sc;
            }

            // ---- Quirks: occasional off-pattern action ----
            $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
            $quirkChance = (float) ($quirks['quirk_chance'] ?? 0.04);
            $seed = (int) ($state['bot_seed'] ?? 0);
            $tickKey = (int) floor(time() / 1800); // 30-minute bucket
            if (botRng($seed, 'quirk:' . $tickKey . ':' . (int) $planet['planet_id']) < $quirkChance) {
                $favShipId = (int) ($quirks['favourite_ship_id'] ?? 0);
                $favShipCol = botShipColumnById($favShipId);
                if (
                    $favShipCol !== null
                    && (int) ($planet[$favShipCol] ?? 0) < BOT_CAP_UNIT_PER_TYPE
                    && botPlanetCanQueueShipyardUnits($planet)
                ) {
                    $candidates[] = [
                        'weight' => 1.5,
                        'value' => ['type' => 'ship', 'id' => $favShipId, 'column' => $favShipCol, 'amount' => 1],
                    ];
                }
                $favDefId = (int) ($quirks['favourite_defense_id'] ?? 0);
                $favDefCol = botDefenseColumnById($favDefId);
                if (
                    $favDefCol !== null
                    && (int) ($planet[$favDefCol] ?? 0) < BOT_CAP_UNIT_PER_TYPE
                    && botPlanetCanQueueShipyardUnits($planet)
                ) {
                    $candidates[] = [
                        'weight' => 1.0,
                        'value' => ['type' => 'defense', 'id' => $favDefId, 'column' => $favDefCol, 'amount' => 1],
                    ];
                }
            }
        }

        return $candidates;
    }
}

if (!function_exists('botArchetypeMultipliers')) {
    /**
     * Returns multipliers per axis for an archetype. Multipliers are scaled
     * so that no axis ever drops below ~0.5 (we never *forbid* a path,
     * just bias).
     *
     * @return array<string, float>
     */
    function botArchetypeMultipliers(string $archetype): array
    {
        switch ($archetype) {
            case 'turtle':
                return ['eco' => 1.4, 'mil' => 0.7, 'def' => 1.4, 'tech' => 1.0];
            case 'turbo':
                return ['eco' => 1.0, 'mil' => 1.4, 'def' => 0.9, 'tech' => 1.1];
            case 'opportunist':
                return ['eco' => 1.0, 'mil' => 1.2, 'def' => 1.0, 'tech' => 1.2];
            default:
                return ['eco' => 1.0, 'mil' => 1.0, 'def' => 1.0, 'tech' => 1.0];
        }
    }
}

if (!function_exists('botDefenseCandidates')) {
    /**
     * Builds defense candidates following the personalized recipe and
     * possible attack reactivity boost (capped).
     *
     * @param array<int, array{id:int, column:string, share:float}> $recipe
     * @param array<string, float> $archetypeMul
     * @return array<int, array{weight:float, value:array<string, mixed>}>
     */
    function botDefenseCandidates(
        array $planet,
        array $state,
        array $recipe,
        string $focus,
        string $personality,
        array $archetypeMul
    ): array {
        if (!botPlanetCanQueueShipyardUnits($planet)) {
            return [];
        }

        if (empty($recipe)) {
            return [];
        }

        // Compute current total defenses to balance the recipe shares.
        // Use effective counts (built + already enqueued) so we don't keep
        // stacking identical defense orders within the same loop.
        $totalDefenses = 0;
        $current = [];
        foreach ($recipe as $entry) {
            $col = (string) $entry['column'];
            $cnt = botEffectiveUnitCount($planet, (int) $entry['id'], $col);
            $current[$col] = $cnt;
            $totalDefenses += $cnt;
        }

        $mineLevels = [
            (int) ($planet['building_metal_mine'] ?? 0),
            (int) ($planet['building_crystal_mine'] ?? 0),
            (int) ($planet['building_deuterium_sintetizer'] ?? 0),
        ];
        $sumMines = array_sum($mineLevels);
        // Desired total defense scales with mine levels (richer planet =
        // more reason to defend).
        $desiredTotal = max(40, (int) floor($sumMines * 4));

        // Attack reactivity: short-lived multiplier when recently attacked.
        $reactiveUntil = (int) ($state['bot_attack_reactive_until'] ?? 0);
        $reactiveBoost = (time() < $reactiveUntil) ? 1.6 : 1.0;
        // Cap the multiplier: never go above 1.6 to avoid panic-spam.

        $candidates = [];
        foreach ($recipe as $entry) {
            $col = (string) $entry['column'];
            $cur = $current[$col];
            if ($cur >= BOT_CAP_UNIT_PER_TYPE) {
                continue;
            }
            $share = (float) $entry['share'];
            $desiredCount = (int) floor($desiredTotal * $share);
            if ($cur >= $desiredCount) {
                continue;
            }
            $missing = max(1, $desiredCount - $cur);
            $chunk = min(8, $missing, BOT_CAP_UNIT_PER_TYPE - $cur);
            if ($chunk <= 0) {
                continue;
            }
            $baseWeight = 1.0 + 0.5 * $share + 0.05 * min(20, $missing / max(1, $desiredCount) * 20);
            $weight = $baseWeight * $archetypeMul['def'] * $reactiveBoost;
            if ($focus === 'mil') {
                $weight *= 1.5;
            } elseif ($focus === 'eco') {
                $weight *= 0.6;
            }
            if ($personality === 'defensor') {
                $weight *= 1.5;
            }

            $candidates[] = [
                'weight' => $weight,
                'value' => ['type' => 'defense', 'id' => (int) $entry['id'], 'column' => $col, 'amount' => $chunk],
            ];
        }

        return $candidates;
    }
}

if (!function_exists('botShipCandidates')) {
    /**
     * @param array<string, float> $archetypeMul
     * @return array<int, array{weight:float, value:array<string, mixed>}>
     */
    function botShipCandidates(
        array $planet,
        array $state,
        string $focus,
        string $personality,
        string $role,
        array $archetypeMul
    ): array {
        if (!botPlanetCanQueueShipyardUnits($planet)) {
            return [];
        }

        $candidates = [];

        $sumMines = (int) ($planet['building_metal_mine'] ?? 0)
            + (int) ($planet['building_crystal_mine'] ?? 0)
            + (int) ($planet['building_deuterium_sintetizer'] ?? 0);

        // Cargo: every bot wants some, but shares vary by personality/role.
        $smallCargoCol = 'ship_small_cargo_ship';
        $smallCargoCur = botEffectiveUnitCount($planet, 202, $smallCargoCol);
        switch ($role) {
            case 'support':
                $cargoTarget = max(80, $sumMines * 4);

                break;
            case 'metal':
            case 'crystal':
                $cargoTarget = max(40, $sumMines * 2);

                break;
            case 'industrial':
            case 'military':
                $cargoTarget = max(20, $sumMines);

                break;
            default:
                $cargoTarget = max(30, $sumMines * 2);

                break;
        }
        if ($smallCargoCur < $cargoTarget && $smallCargoCur < BOT_CAP_UNIT_PER_TYPE) {
            $missing = $cargoTarget - $smallCargoCur;
            $chunk = botCapClampUnitAmount($smallCargoCur, min(8, $missing));
            if ($chunk > 0) {
                $w = 1.2 * $archetypeMul['eco'];
                if ($role === 'support') {
                    $w *= 1.6;
                }
                if ($focus === 'eco') {
                    $w *= 1.2;
                }
                $candidates[] = [
                    'weight' => $w,
                    'value' => ['type' => 'ship', 'id' => 202, 'column' => $smallCargoCol, 'amount' => $chunk],
                ];
            }
        }

        // Big cargos: only enter the pool when the attack module signals
        // "cargo pressure" (one or more cap-loot skips inside the TTL).
        // Without that signal big cargos stay out of the candidate list,
        // so miner/defender bots never spend resources on them. Under
        // pressure we give them a high weight so the weighted picker
        // tends to pick them before regular ships.
        //
        // Where to build: not every planet should turn into a cargo depot.
        // Roles that make sense as launch pads or stocking hubs (main,
        // military, industrial, support) are always eligible. Pure mining
        // roles (metal, crystal) are only eligible if the planet already
        // shows some "fleet activity" (any cargo or combat ship already
        // built / queued). This prevents flooding a metal-only planet with
        // big cargos it will never use.
        $bigCargoCol = 'ship_big_cargo_ship';
        $bigCargoCur = botEffectiveUnitCount($planet, 203, $bigCargoCol);
        $now = time();
        $cargoPressure = botCargoPressureActive($state, $now);
        $rolePermitsBigCargo = in_array($role, ['main', 'military', 'industrial', 'support'], true);
        if (!$rolePermitsBigCargo) {
            // Pure mining role: accept big cargo only if the planet has
            // any cargo or combat ship already there.
            $existingFleet = (int) ($planet['ship_small_cargo_ship'] ?? 0)
                + (int) ($planet['ship_big_cargo_ship'] ?? 0)
                + (int) ($planet['ship_light_fighter'] ?? 0)
                + (int) ($planet['ship_heavy_fighter'] ?? 0)
                + (int) ($planet['ship_cruiser'] ?? 0)
                + (int) ($planet['ship_battleship'] ?? 0);
            $rolePermitsBigCargo = $existingFleet > 0;
        }
        if (
            isset($planet[$bigCargoCol])
            && $cargoPressure
            && $rolePermitsBigCargo
            && $bigCargoCur < BOT_CARGO_PRESSURE_BIG_CARGO_TARGET
            && $bigCargoCur < BOT_CAP_UNIT_PER_TYPE
        ) {
            $missing = BOT_CARGO_PRESSURE_BIG_CARGO_TARGET - $bigCargoCur;
            $chunk = botCapClampUnitAmount($bigCargoCur, min(5, $missing));
            if ($chunk > 0) {
                $w = 3.0 * $archetypeMul['eco'];
                // Boost on planets that are explicitly fleet hubs.
                if ($role === 'main' || $role === 'military') {
                    $w *= 1.6;
                } elseif ($role === 'support') {
                    $w *= 1.4;
                } elseif ($role === 'industrial') {
                    $w *= 1.2;
                }
                if ($focus === 'mil') {
                    // Even under "mil" focus the cargo pressure must win
                    // over fighters: the bot already has a profitable
                    // target, the problem is logistics.
                    $w *= 1.2;
                }
                $candidates[] = [
                    'weight' => $w,
                    'value' => ['type' => 'ship', 'id' => 203, 'column' => $bigCargoCol, 'amount' => $chunk],
                ];
            }
        }

        // Mining drill (granja-style): only if the bot has the personality
        // and the role aligns. Capped to a sane number.
        $drillCol = 'ship_mining_drill';
        if (($personality === 'minero' || $role === 'metal' || $role === 'crystal') && isset($planet[$drillCol])) {
            $cur = botEffectiveUnitCount($planet, 216, $drillCol);
            $headroom = botMiningDrillBuildableRemaining($planet);
            if ($headroom <= 0) {
                // At ShipyardController cap (built + queue).
            } else {
                $desired = min(BOT_MINING_DRILL_LIMIT_PER_PLANET, max(200, $sumMines * 10));
                if ($cur < $desired && $cur < BOT_CAP_UNIT_PER_TYPE) {
                    $chunk = botCapClampUnitAmount($cur, min(5, $desired - $cur, $headroom));
                    if ($chunk > 0) {
                        $w = 0.8 * $archetypeMul['eco'];
                        if ($personality === 'minero') {
                            $w *= 1.3;
                        }
                        $candidates[] = [
                            'weight' => $w,
                            'value' => ['type' => 'ship', 'id' => 216, 'column' => $drillCol, 'amount' => $chunk],
                        ];
                    }
                }
            }
        }

        // Combat ships: weighted by personality and military focus.
        $combatShips = [
            ['col' => 'ship_light_fighter',  'id' => 204, 'role_match' => ['military', 'industrial'], 'pers' => ['flotero', 'cazador'], 'base' => 1.5],
            ['col' => 'ship_heavy_fighter',  'id' => 205, 'role_match' => ['military'],                'pers' => ['flotero', 'cazador'], 'base' => 1.0],
            ['col' => 'ship_cruiser',        'id' => 206, 'role_match' => ['military'],                'pers' => ['flotero', 'cazador'], 'base' => 0.8],
            ['col' => 'ship_battleship',    'id' => 207, 'role_match' => ['military'],                'pers' => ['flotero'],            'base' => 0.5],
            ['col' => 'ship_espionage_probe', 'id' => 210, 'role_match' => ['military', 'main'],       'pers' => ['cazador', 'tecnologico'], 'base' => 0.4],
        ];
        foreach ($combatShips as $ship) {
            if (!isset($planet[$ship['col']])) {
                continue;
            }
            $cur = botEffectiveUnitCount($planet, (int) $ship['id'], (string) $ship['col']);
            if ($cur >= BOT_CAP_UNIT_PER_TYPE) {
                continue;
            }
            $w = $ship['base'] * $archetypeMul['mil'];
            if (in_array($role, $ship['role_match'], true)) {
                $w *= 1.6;
            }
            if (in_array($personality, $ship['pers'], true)) {
                $w *= 1.6;
            }
            if ($focus === 'mil') {
                $w *= 1.5;
            } elseif ($focus === 'eco') {
                $w *= 0.4;
            }
            $chunk = botCapClampUnitAmount($cur, 5);
            if ($chunk <= 0) {
                continue;
            }
            $candidates[] = [
                'weight' => $w,
                'value' => ['type' => 'ship', 'id' => $ship['id'], 'column' => $ship['col'], 'amount' => $chunk],
            ];
        }

        return $candidates;
    }
}

if (!function_exists('botShipColumnById')) {
    function botShipColumnById(int $id): ?string
    {
        $map = [
            202 => 'ship_small_cargo_ship',
            203 => 'ship_big_cargo_ship',
            204 => 'ship_light_fighter',
            205 => 'ship_heavy_fighter',
            206 => 'ship_cruiser',
            207 => 'ship_battleship',
            208 => 'ship_colony_ship',
            211 => 'ship_bomber',
            213 => 'ship_destroyer',
            215 => 'ship_battlecruiser',
            216 => 'ship_mining_drill',
        ];

        return $map[$id] ?? null;
    }
}

if (!function_exists('botDefenseColumnById')) {
    function botDefenseColumnById(int $id): ?string
    {
        $map = [
            401 => 'defense_rocket_launcher',
            402 => 'defense_light_laser',
            403 => 'defense_heavy_laser',
            404 => 'defense_ion_cannon',
            405 => 'defense_gauss_cannon',
            406 => 'defense_plasma_turret',
        ];

        return $map[$id] ?? null;
    }
}

// ===========================================================================
// FOCUS ROTATION
// ===========================================================================

if (!function_exists('botUpdateFocusIfDue')) {
    /**
     * Rotates the bot's focus when its current focus window has expired.
     * The new focus is biased by personality so a 'flotero' rarely lands on
     * 'eco' for long, but does occasionally to keep its mines healthy.
     *
     * @param array<string, mixed> $state Mutated in place.
     */
    function botUpdateFocusIfDue(mysqli $db, string $prefix, array &$state): void
    {
        $until = (int) ($state['bot_focus_until'] ?? 0);
        if ($until > time()) {
            return;
        }

        $seed = (int) ($state['bot_seed'] ?? 0);
        $personality = (string) ($state['bot_personality'] ?? 'minero');
        $current = (string) ($state['bot_current_focus'] ?? 'eco');

        $weightsByPersonality = [
            'minero' => [
                ['weight' => 5.0, 'value' => 'eco'],
                ['weight' => 2.0, 'value' => 'tech'],
                ['weight' => 1.0, 'value' => 'mil'],
            ],
            'flotero' => [
                ['weight' => 3.5, 'value' => 'mil'],
                ['weight' => 2.0, 'value' => 'tech'],
                ['weight' => 2.0, 'value' => 'eco'],
            ],
            'cazador' => [
                ['weight' => 4.0, 'value' => 'mil'],
                ['weight' => 1.8, 'value' => 'tech'],
                ['weight' => 1.5, 'value' => 'eco'],
            ],
            'defensor' => [
                ['weight' => 3.0, 'value' => 'mil'],
                ['weight' => 2.5, 'value' => 'eco'],
                ['weight' => 1.5, 'value' => 'tech'],
            ],
            'tecnologico' => [
                ['weight' => 4.0, 'value' => 'tech'],
                ['weight' => 2.0, 'value' => 'eco'],
                ['weight' => 1.0, 'value' => 'mil'],
            ],
        ];

        $weights = $weightsByPersonality[$personality] ?? $weightsByPersonality['minero'];
        // Avoid sticking on the same focus twice in a row by halving its weight.
        foreach ($weights as &$w) {
            if (($w['value'] ?? null) === $current) {
                $w['weight'] = (float) ($w['weight'] ?? 0) * 0.4;
            }
        }
        unset($w);

        $bucket = (int) floor(time() / 86400);
        $next = (string) (botRngWeightedPick($seed, 'focus:' . $bucket, $weights) ?? 'eco');

        // Focus duration: 2-5 days, varied per archetype.
        $archetype = (string) ($state['bot_archetype'] ?? 'balanced');
        switch ($archetype) {
            case 'turtle':
                $duration = 5 * 86400;

                break;
            case 'turbo':
                $duration = 2 * 86400;

                break;
            case 'opportunist':
                $duration = 3 * 86400;

                break;
            default:
                $duration = 3 * 86400;

                break;
        }

        $now = time();
        $state['bot_current_focus'] = $next;
        $state['bot_focus_started_at'] = $now;
        $state['bot_focus_until'] = $now + $duration;

        if (empty($state['__synthetic'])) {
            saveBotStateFields($db, $prefix, (int) $state['bot_user_id'], [
                'bot_current_focus' => $next,
                'bot_focus_started_at' => $now,
                'bot_focus_until' => $now + $duration,
            ]);
        }
    }
}

// ===========================================================================
// ATTACK REACTIVITY
// ===========================================================================

if (!function_exists('botDetectRecentAttack')) {
    /**
     * Looks for inbound hostile fleets (mission 1=attack, 2=acs, 6=spy) that
     * targeted any of the user's planets in the last $sinceSeconds seconds.
     * If found, set bot_attack_reactive_until to give the bot a defense surge
     * AND remember the attacker's user_id inside bot_quirks so the offensive
     * pipeline can prioritize a revenge raid against them while the reactive
     * window is open.
     */
    function botDetectRecentAttack(
        mysqli $db,
        string $prefix,
        array &$state,
        int $sinceSeconds = 6 * 3600
    ): void {
        $userId = (int) $state['bot_user_id'];
        if ($userId <= 0) {
            return;
        }
        $since = time() - $sinceSeconds;
        // Grab the most recent hostile fleet so we know *who* hit us, not
        // just when. Ignore the bot's own friendly missions (e.g. ACS launched
        // by themselves would never happen but we filter defensively).
        // Also fetch fleet_mission to differentiate spy (6) from attack (1,2).
        $sql = "SELECT `fleet_creation`, `fleet_owner`, `fleet_mission`
                FROM `{$prefix}fleets`
                WHERE `fleet_target_owner` = {$userId}
                  AND `fleet_owner` <> {$userId}
                  AND `fleet_mission` IN (1, 2, 6)
                  AND `fleet_creation` >= {$since}
                ORDER BY `fleet_creation` DESC
                LIMIT 1";
        $result = $db->query($sql);
        if (!$result) {
            return;
        }
        $row = $result->fetch_assoc();
        $result->free();
        $lastAt = isset($row['fleet_creation']) ? (int) $row['fleet_creation'] : 0;
        $attackerUserId = isset($row['fleet_owner']) ? (int) $row['fleet_owner'] : 0;
        $fleetMission = isset($row['fleet_mission']) ? (int) $row['fleet_mission'] : 0;
        if ($lastAt <= 0) {
            return;
        }

        // Reactive surge: defense weight boost for the next 6 hours, never
        // longer than 12 hours total (so it doesn't snowball into a panic
        // bot stuck building defense forever).
        $until = min($lastAt + 6 * 3600, time() + 12 * 3600);
        $prevUntil = (int) ($state['bot_attack_reactive_until'] ?? 0);
        $prevAttacker = 0;
        if (is_array($state['bot_quirks'] ?? null)) {
            $prevAttacker = (int) ($state['bot_quirks']['last_attacker_user_id'] ?? 0);
        }

        // Short-circuit only when nothing actually changed (same window AND
        // same attacker). A different attacker should update the quirks even
        // if reactive_until isn't extended.
        if ($prevUntil >= $until && $prevAttacker === $attackerUserId) {
            return;
        }

        $state['bot_last_attacked_at'] = $lastAt;
        $state['bot_attack_reactive_until'] = $until;

        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
        if ($attackerUserId > 0) {
            $quirks['last_attacker_user_id'] = $attackerUserId;
            $quirks['last_attacker_seen_at'] = $lastAt;
        }
        $state['bot_quirks'] = $quirks;

        // Heat update: spy probes (mission 6) cause a lighter heat increase
        // than actual attacks (missions 1, 2).
        if ($attackerUserId > 0) {
            if ($fleetMission === 6) {
                botHeatOnSpyDetected($db, $prefix, $userId, $attackerUserId, time());
            } else {
                botHeatOnInboundHostile($db, $prefix, $userId, $attackerUserId, time());
            }
        }

        if (empty($state['__synthetic'])) {
            saveBotStateFields($db, $prefix, $userId, [
                'bot_last_attacked_at' => $lastAt,
                'bot_attack_reactive_until' => $until,
                'bot_quirks' => $quirks,
            ]);
        }
    }
}
