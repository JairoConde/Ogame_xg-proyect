<?php

declare(strict_types=1);

/**
 * Pure helpers used by simple_rule_bot.php.
 *
 * This module exists so the same functions are reachable from PHPUnit
 * tests without having to require simple_rule_bot.php (which has
 * top-level setup code that opens a database connection and parses CLI
 * arguments). Anything here MUST remain side-effect free: no DB, no I/O,
 * no random sources. CLI/DB-aware helpers stay in simple_rule_bot.php.
 *
 * All declarations are wrapped in function_exists guards so re-including
 * the file (or sourcing it from both the bot bootstrap and the test
 * bootstrap) is safe.
 */
if (!function_exists('argValue')) {
    /**
     * Reads a CLI argument value from $argv. Supports both `--key=value`
     * and `--key value` forms; returns $default if neither is present.
     *
     * @param array<int, string> $argv
     */
    function argValue(array $argv, string $key, ?string $default = null): ?string
    {
        foreach ($argv as $index => $arg) {
            if (strpos($arg, $key . '=') === 0) {
                return substr($arg, strlen($key) + 1);
            }

            if ($arg === $key && isset($argv[$index + 1])) {
                $next = (string) $argv[$index + 1];
                if ($next !== '' && strpos($next, '--') !== 0) {
                    return $next;
                }
            }
        }

        return $default;
    }
}

if (!function_exists('hasFlag')) {
    /**
     * Returns whether $flag (e.g. '--dry-run') appears literally in $argv.
     *
     * @param array<int, string> $argv
     */
    function hasFlag(array $argv, string $flag): bool
    {
        return in_array($flag, $argv, true);
    }
}

if (!function_exists('parseUsersList')) {
    /**
     * Splits a comma-separated --users argument into a clean list. Trims
     * whitespace and drops empties so configs like ' bot1, , bot2 ' are
     * normalised to ['bot1', 'bot2'].
     *
     * @return array<int, string>
     */
    function parseUsersList(?string $usersArg): array
    {
        if ($usersArg === null || trim($usersArg) === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $usersArg));

        return array_values(array_filter($parts, static fn (string $u): bool => $u !== ''));
    }
}

if (!function_exists('nextLevelCost')) {
    /**
     * Cost of upgrading a building/research from $currentLevel to
     * $currentLevel + 1, applying the multiplicative `factor` from the
     * pricelist row.
     *
     * @param array<string, mixed> $price
     * @return array{metal:int, crystal:int, deuterium:int}
     */
    function nextLevelCost(array $price, int $currentLevel): array
    {
        $factor = $price['factor'] ?? 1;

        return [
            'metal' => (int) floor(($price['metal'] ?? 0) * pow($factor, $currentLevel)),
            'crystal' => (int) floor(($price['crystal'] ?? 0) * pow($factor, $currentLevel)),
            'deuterium' => (int) floor(($price['deuterium'] ?? 0) * pow($factor, $currentLevel)),
        ];
    }
}

if (!function_exists('maxStorageFromLevel')) {
    /**
     * Maximum storage in resources for a given store level. Mirrors the
     * formula used by App\Libraries\ProductionLib::maxStorable so bots
     * see the same caps as the web client.
     */
    function maxStorageFromLevel(int $storageLevel, float $resourceMultiplier): int
    {
        $baseStorage = (int) (2.5 * pow(M_E, (20 * $storageLevel / 33))) * 5000;

        return (int) floor($baseStorage * ($resourceMultiplier / 2));
    }
}

if (!function_exists('getQueuedHangarAmount')) {
    /**
     * Counts how many units of $itemId are currently pending in the
     * planet's hangar queue (the in-game `planet_b_hangar_id` field,
     * shaped as 'shipId,amount;...'). Used to avoid double-queuing the
     * same ship/defense within a single bot loop.
     *
     * @param array<string, mixed> $planet
     */
    function getQueuedHangarAmount(array $planet, int $itemId): int
    {
        $queue = (string) ($planet['planet_b_hangar_id'] ?? '');
        if ($queue === '' || $queue === '0') {
            return 0;
        }
        $total = 0;
        $items = array_values(array_filter(explode(';', $queue), static fn (string $item): bool => $item !== ''));
        foreach ($items as $item) {
            $parts = explode(',', $item);
            $queuedId = isset($parts[0]) ? (int) $parts[0] : 0;
            $queuedAmount = isset($parts[1]) ? (int) $parts[1] : 0;
            if ($queuedId === $itemId) {
                $total += $queuedAmount;
            }
        }

        return $total;
    }
}

if (!function_exists('botUserEffectiveColonyShipTotal')) {
    /**
     * Built + hangar-queued colony ships (208) summed across all of a user's
     * planets. Used so the bot keeps at least one ready once astrophysics
     * exists, matching colonization expectations.
     *
     * @param array<int, array<string, mixed>> $planets
     */
    function botUserEffectiveColonyShipTotal(array $planets): int
    {
        $sum = 0;
        foreach ($planets as $p) {
            if (!is_array($p)) {
                continue;
            }
            $sum += (int) ($p['ship_colony_ship'] ?? 0);
            $sum += getQueuedHangarAmount($p, 208);
        }

        return $sum;
    }
}

if (!function_exists('botShipyardUnitBuildSeconds')) {
    /**
     * Seconds to build one hangar unit (ship/defense), matching the bot's
     * queue math and ShipyardController production formula subset.
     *
     * @param array<string, mixed> $planet
     * @param array<int|string, mixed> $pricelist
     */
    function botShipyardUnitBuildSeconds(array $planet, int $itemId, array $pricelist, float $universeSpeed): float
    {
        if (!isset($pricelist[$itemId]) || !is_array($pricelist[$itemId])) {
            return 1.0;
        }

        $hangar = (int) ($planet['building_hangar'] ?? 0);
        $robotics = (1 + $hangar) * pow(1.1, $hangar);
        $nanite = pow(2, (int) ($planet['building_nano_factory'] ?? 0));
        $resourcesNeeded = (float) (
            ((int) ($pricelist[$itemId]['metal'] ?? 0))
            + ((int) ($pricelist[$itemId]['crystal'] ?? 0))
        );

        if ($resourcesNeeded <= 0) {
            return 1.0;
        }

        return max(
            0.000001,
            ($resourcesNeeded / (2500 * $robotics * $nanite * max(0.1, $universeSpeed))) * 3600
        );
    }
}

if (!function_exists('botPlanetFacilityUpgradeBlocksHangar')) {
    /**
     * True while robotics, nanites, or shipyard are upgrading (matches
     * ShipyardController::isAnyFacilityWorking — no hangar unit production).
     *
     * @param array<string, mixed> $planet
     */
    function botPlanetFacilityUpgradeBlocksHangar(array $planet, ?int $now = null): bool
    {
        $now ??= time();
        $queue = (string) ($planet['planet_b_building_id'] ?? '0');
        if ($queue === '' || $queue === '0') {
            return false;
        }

        $items = array_values(array_filter(explode(';', $queue), static fn (string $item): bool => $item !== ''));
        foreach ($items as $item) {
            $parts = explode(',', $item);
            $buildingId = isset($parts[0]) ? (int) $parts[0] : 0;
            if (!in_array($buildingId, [14, 15, 21], true)) {
                continue;
            }
            $endTs = isset($parts[3]) ? (int) $parts[3] : 0;
            if ($endTs > $now) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('botPlanetCanQueueShipyardUnits')) {
    /**
     * Whether the planet may enqueue ships/defenses (operational hangar ≥1
     * and no blocking robotics/nanite/hangar upgrade in the building queue).
     *
     * @param array<string, mixed> $planet
     */
    function botPlanetCanQueueShipyardUnits(array $planet, ?int $now = null): bool
    {
        if ((int) ($planet['building_hangar'] ?? 0) < 1) {
            return false;
        }

        return !botPlanetFacilityUpgradeBlocksHangar($planet, $now);
    }
}

if (!function_exists('botResearchRemainingSeconds')) {
    /**
     * Seconds until the technology being researched on `research_current_research`
     * finishes (`planet_b_tech` end timestamp on that planet row).
     *
     * @param array<string, mixed>|null $researchRow
     * @param array<int, array<string, mixed>>|null $allUserPlanets
     */
    function botResearchRemainingSeconds(?array $researchRow, ?array $allUserPlanets, ?int $now = null): int
    {
        $now ??= time();
        if ($researchRow === null || $allUserPlanets === null || $allUserPlanets === []) {
            return 0;
        }
        $pid = (int) ($researchRow['research_current_research'] ?? 0);
        if ($pid <= 0) {
            return 0;
        }
        foreach ($allUserPlanets as $p) {
            if (!is_array($p) || (int) ($p['planet_id'] ?? 0) !== $pid) {
                continue;
            }
            $techId = (int) ($p['planet_b_tech_id'] ?? 0);
            $end = (int) ($p['planet_b_tech'] ?? 0);
            if ($techId === 0 || $end <= 0) {
                return 0;
            }

            return max(0, $end - $now);
        }

        return 0;
    }
}

if (!function_exists('botSumTopLaboratoriesLevel')) {
    /**
     * Sum of the N highest effective laboratory levels (mirrors IRN "top labs"
     * aggregation for research speed).
     *
     * @param array<int, array<string, mixed>> $allUserPlanets
     */
    function botSumTopLaboratoriesLevel(array $allUserPlanets, int $labsLimit): int
    {
        $labsLimit = max(1, $labsLimit);
        $levels = [];
        foreach ($allUserPlanets as $p) {
            if (!is_array($p)) {
                continue;
            }
            $levels[] = botEffectiveBuildingUpgradeLevel($p, 31, 'building_laboratory');
        }
        if ($levels === []) {
            return 0;
        }
        rsort($levels, SORT_NUMERIC);
        $sum = 0;
        for ($i = 0; $i < $labsLimit && $i < count($levels); $i++) {
            $sum += $levels[$i];
        }

        return $sum;
    }
}

if (!function_exists('botPlanetWithMaxLaboratory')) {
    /**
     * Planet row with the highest effective laboratory (tie: higher planet_id).
     *
     * @param array<int, array<string, mixed>> $allUserPlanets
     * @return array<string, mixed>|null
     */
    function botPlanetWithMaxLaboratory(array $allUserPlanets): ?array
    {
        $best = null;
        $bestLab = -1;
        foreach ($allUserPlanets as $p) {
            if (!is_array($p)) {
                continue;
            }
            $lab = botEffectiveBuildingUpgradeLevel($p, 31, 'building_laboratory');
            $pid = (int) ($p['planet_id'] ?? 0);
            if ($lab > $bestLab || ($lab === $bestLab && ($best === null || $pid > (int) ($best['planet_id'] ?? 0)))) {
                $bestLab = $lab;
                $best = $p;
            }
        }

        return $best;
    }
}

if (!function_exists('botResearchSecondsForNextTechLevel')) {
    /**
     * Research queue duration for the next level of a tech (Formulas::getResearchTime shape).
     *
     * @param array<string, mixed> $pricelistRow
     */
    function botResearchSecondsForNextTechLevel(
        array $pricelistRow,
        int $currentResearchLevel,
        int $totalLabLevel,
        int $astrophysicsLevel,
        float $universeSpeed
    ): int {
        $factor = (float) ($pricelistRow['factor'] ?? 1.0);
        $metal = (float) round(((int) ($pricelistRow['metal'] ?? 0)) * pow($factor, $currentResearchLevel));
        $crystal = (float) round(((int) ($pricelistRow['crystal'] ?? 0)) * pow($factor, $currentResearchLevel));
        $lab = max(0, $totalLabLevel);
        $labBoost = (1 + $lab) * pow(1.1, $lab);
        $astro = max(0, $astrophysicsLevel);
        $u = max(0.000001, $universeSpeed);
        $secs = ($metal + $crystal) / ($u * 1000.0 * $labBoost * (1 + $astro)) * 3600.0;

        return max(1, (int) floor($secs));
    }
}

if (!defined('BOT_ENERGY_SOLAR_PLANT_LEVEL_BEFORE_SAT_PRIORITY')) {
    /**
     * While solar plant level is strictly below this value, energy overrides
     * prefer upgrading the plant; at or above, satellites take priority when
     * the hangar can build them (mirrors the usual OGame crossover).
     */
    define('BOT_ENERGY_SOLAR_PLANT_LEVEL_BEFORE_SAT_PRIORITY', 12);
}

if (!function_exists('botPlanetEnergyIsDeficit')) {
    /**
     * True when the planet cannot cover its energy demand.
     *
     * Matches ResourcesController::prod_level (not the raw max+used sum):
     * consumption is stored as negative values in planet_energy_used, so
     * deficit means abs(used) > max when max > 0.
     *
     * @param array<string, mixed> $planet
     */
    function botPlanetEnergyIsDeficit(array $planet): bool
    {
        $max = (int) ($planet['planet_energy_max'] ?? 0);
        $used = (int) ($planet['planet_energy_used'] ?? 0);

        if ($max === 0 && $used > 0) {
            return true;
        }

        if ($max > 0) {
            if ($used < 0) {
                return abs($used) > $max;
            }

            return $used > $max;
        }

        return $used < 0;
    }
}

if (!function_exists('botPlanetEnergyDeficitPoints')) {
    /**
     * Positive "energy points" shortfall when botPlanetEnergyIsDeficit is
     * true; 0 when balanced. Compatible with satellite deficit math that used
     * -(planet_energy_max + planet_energy_used) under the negative-used
     * convention.
     *
     * @param array<string, mixed> $planet
     */
    function botPlanetEnergyDeficitPoints(array $planet): int
    {
        if (!botPlanetEnergyIsDeficit($planet)) {
            return 0;
        }

        $max = (int) ($planet['planet_energy_max'] ?? 0);
        $used = (int) ($planet['planet_energy_used'] ?? 0);

        if ($max > 0 && $used < 0) {
            return max(1, abs($used) - $max);
        }

        if ($max > 0 && $used >= 0) {
            return max(1, $used - $max);
        }

        if ($used < 0) {
            return max(1, abs($used));
        }

        return max(1, $used);
    }
}

if (!function_exists('botPlanetBuildingQueueRemainingMaxSeconds')) {
    /**
     * Wall seconds until the last still-pending building queue entry finishes.
     *
     * @param array<string, mixed> $planet
     */
    function botPlanetBuildingQueueRemainingMaxSeconds(array $planet, ?int $now = null): int
    {
        $now ??= time();
        $queue = (string) ($planet['planet_b_building_id'] ?? '0');
        if ($queue === '' || $queue === '0') {
            return 0;
        }

        $maxEnd = 0;
        $items = array_values(array_filter(explode(';', $queue), static fn (string $item): bool => $item !== ''));
        foreach ($items as $item) {
            $parts = explode(',', $item);
            $endTs = isset($parts[3]) ? (int) $parts[3] : 0;
            if ($endTs > $now && $endTs > $maxEnd) {
                $maxEnd = $endTs;
            }
        }

        if ($maxEnd <= $now) {
            return 0;
        }

        return max(0, $maxEnd - $now);
    }
}

if (!function_exists('botMaxQueuedBuildingTargetLevel')) {
    /**
     * Highest target level for $buildingId already present in the planet
     * building queue (pending future completions).
     */
    function botMaxQueuedBuildingTargetLevel(array $planet, int $buildingId): int
    {
        $queue = (string) ($planet['planet_b_building_id'] ?? '');
        if ($queue === '' || $queue === '0') {
            return 0;
        }
        $max = 0;
        $items = array_values(array_filter(explode(';', $queue), static fn (string $item): bool => $item !== ''));
        foreach ($items as $item) {
            $parts = explode(',', $item);
            $bid = isset($parts[0]) ? (int) $parts[0] : 0;
            $target = isset($parts[1]) ? (int) $parts[1] : 0;
            if ($bid === $buildingId && $target > $max) {
                $max = $target;
            }
        }

        return $max;
    }
}

if (!function_exists('botEffectiveBuildingUpgradeLevel')) {
    /**
     * Level to use when deciding the next upgrade: max(built, any higher
     * target already queued for this building id).
     */
    function botEffectiveBuildingUpgradeLevel(array $planet, int $buildingId, string $column): int
    {
        $built = (int) ($planet[$column] ?? 0);
        $queuedTop = botMaxQueuedBuildingTargetLevel($planet, $buildingId);

        return max($built, $queuedTop);
    }
}

if (!function_exists('botPlanetHangarFrontBatchEstimatedSeconds')) {
    /**
     * Rough upper-bound seconds for the first hangar queue segment
     * (id,amount), used to detect painfully slow shipyard production.
     *
     * @param array<string, mixed> $planet
     * @param array<int|string, mixed> $pricelist
     */
    function botPlanetHangarFrontBatchEstimatedSeconds(array $planet, array $pricelist, float $universeSpeed): float
    {
        $queue = trim((string) ($planet['planet_b_hangar_id'] ?? ''));
        if ($queue === '') {
            return 0.0;
        }

        $items = array_values(array_filter(explode(';', $queue), static fn (string $item): bool => $item !== ''));
        if ($items === []) {
            return 0.0;
        }

        $parts = explode(',', $items[0]);
        $itemId = isset($parts[0]) ? (int) $parts[0] : 0;
        $amount = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($itemId <= 0 || $amount <= 0) {
            return 0.0;
        }

        $perUnit = botShipyardUnitBuildSeconds($planet, $itemId, $pricelist, $universeSpeed);
        $cappedAmount = min($amount, 5000);

        return $perUnit * $cappedAmount;
    }
}
