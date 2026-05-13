<?php

declare(strict_types=1);

/**
 * Bot combat simulator.
 *
 * Deterministic, simplified battle model used by the bot to decide whether
 * an attack is profitable BEFORE actually inserting an attack fleet. The
 * full game engine (app/Libraries/BattleEngine/) is stochastic (rapid-fire
 * randomness, RF buffs/nerfs, round-by-round random hits). For a
 * "should I attack?" decision we don't need that fidelity, just an honest
 * expected outcome.
 *
 * Inputs:
 *   - Attacker ships:       map [shipId => count].
 *   - Defender ships:       map [shipId => count] (fleet parked at target).
 *   - Defender defenses:    map [defenseId => count].
 *   - Attacker research:    associative row with research_* columns.
 *   - Defender research:    same shape, used for shielding/armour bonuses.
 *   - Pricelist:            $pricelist global, used to value lost units.
 *
 * Output (associative):
 *   - winner:                'attacker' | 'defender' | 'draw'
 *   - rounds_used:           int (1..6)
 *   - attacker_losses_by_id: map [unitId => count]
 *   - defender_losses_by_id: map [unitId => count]
 *   - attacker_losses_value: int (m+c+d sum of lost units)
 *   - defender_losses_value: int
 *   - debris:                ['metal' => int, 'crystal' => int]
 *
 * Algorithm (per round, max 6 rounds):
 *   1. For each side compute total_attack and total_shield (with tech
 *      bonuses): attack += 10% per weapons_tech_level, shield += 10% per
 *      shielding_tech_level, hull += 10% per armour_tech_level.
 *   2. Damage delivered to opponent = max(0, total_attack - opponent_total_shield).
 *      That damage is distributed over enemy units proportionally to their
 *      hull share, and converted into "killed units" = damage / hull_per_unit.
 *      Anti-rapid-fire is intentionally NOT modeled (decision-only).
 *   3. After both sides resolve their damage, remove dead units. If either
 *      side has 0 hull total, the OTHER side wins. If both still alive after
 *      6 rounds, it's a draw.
 *
 * Determinism: NO randomness, NO mt_rand. Same inputs always yield the same
 * outputs. This is essential for the unit tests to pin behavior.
 */

require_once __DIR__ . '/../../app/Core/objects_collection.php';

if (!defined('BOT_COMBAT_MAX_ROUNDS')) {
    define('BOT_COMBAT_MAX_ROUNDS', 6);
}

if (!function_exists('botCombatTechMultiplier')) {
    /**
     * Returns the per-tech multiplier (1.0 + 0.1*level) for a research level.
     */
    function botCombatTechMultiplier(int $level): float
    {
        return 1.0 + 0.10 * max(0, $level);
    }
}

if (!function_exists('botCombatBuildSideStats')) {
    /**
     * Builds a per-side combat dossier:
     *   - by_unit: associative array keyed by id with {count, atk, shi, hull}.
     *   - total_attack / total_shield / total_hull aggregates.
     *
     * `atk`, `shi` are PER UNIT after tech bonuses; `hull` is per unit and
     * derives from the unit's structural integrity (sum of m+c+d build cost
     * divided by 10 — same heuristic the in-game engine uses).
     *
     * @param array<int, int>           $unitsByShipId   shipId => count (or defenseId)
     * @param array<string, mixed>      $researchRow     attacker/defender research row
     * @param array<int, array<string,int|float>> $pricelist global pricelist
     * @return array{
     *     by_unit: array<int, array{count:int, atk:float, shi:float, hull:float}>,
     *     total_attack: float,
     *     total_shield: float,
     *     total_hull: float
     * }
     */
    function botCombatBuildSideStats(array $unitsByShipId, array $researchRow, array $pricelist): array
    {
        $combatCaps = $GLOBALS['CombatCaps'] ?? [];
        $weaponsLvl = (int) ($researchRow['research_weapons_technology'] ?? 0);
        $shieldingLvl = (int) ($researchRow['research_shielding_technology'] ?? 0);
        $armourLvl = (int) ($researchRow['research_armour_technology'] ?? 0);

        $weaponsMul = botCombatTechMultiplier($weaponsLvl);
        $shieldingMul = botCombatTechMultiplier($shieldingLvl);
        $armourMul = botCombatTechMultiplier($armourLvl);

        $byUnit = [];
        $totalAttack = 0.0;
        $totalShield = 0.0;
        $totalHull = 0.0;

        foreach ($unitsByShipId as $unitId => $count) {
            $unitId = (int) $unitId;
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }
            $caps = $combatCaps[$unitId] ?? null;
            if (!is_array($caps)) {
                continue;
            }
            $price = $pricelist[$unitId] ?? null;
            if (!is_array($price)) {
                continue;
            }
            $baseAttack = (float) ($caps['attack'] ?? 0);
            $baseShield = (float) ($caps['shield'] ?? 0);
            $baseStructural = (float) (($price['metal'] ?? 0) + ($price['crystal'] ?? 0) + ($price['deuterium'] ?? 0));
            // Hull per unit, scaled by armour tech (same convention as the
            // stock engine: structural_integrity = total_cost / 10).
            $hullPerUnit = max(1.0, ($baseStructural / 10.0) * $armourMul);
            $attackPerUnit = $baseAttack * $weaponsMul;
            $shieldPerUnit = $baseShield * $shieldingMul;

            $byUnit[$unitId] = [
                'count' => $count,
                'atk' => $attackPerUnit,
                'shi' => $shieldPerUnit,
                'hull' => $hullPerUnit,
            ];
            $totalAttack += $attackPerUnit * $count;
            $totalShield += $shieldPerUnit * $count;
            $totalHull += $hullPerUnit * $count;
        }

        return [
            'by_unit' => $byUnit,
            'total_attack' => $totalAttack,
            'total_shield' => $totalShield,
            'total_hull' => $totalHull,
        ];
    }
}

if (!function_exists('botCombatApplyDamage')) {
    /**
     * Applies a flat damage value to a side's units, distributing it
     * proportionally to each unit type's hull share. Returns:
     *   - new by_unit map (with reduced counts).
     *   - losses_by_id map (count of units killed per id).
     *   - new total_hull.
     *
     * @param array<int, array{count:int, atk:float, shi:float, hull:float}> $byUnit
     * @return array{
     *     by_unit: array<int, array{count:int, atk:float, shi:float, hull:float}>,
     *     losses_by_id: array<int, int>,
     *     new_total_hull: float
     * }
     */
    function botCombatApplyDamage(array $byUnit, float $damage): array
    {
        if ($damage <= 0 || empty($byUnit)) {
            return [
                'by_unit' => $byUnit,
                'losses_by_id' => [],
                'new_total_hull' => array_sum(array_map(
                    static fn (array $u): float => (float) $u['count'] * (float) $u['hull'],
                    $byUnit
                )),
            ];
        }

        $totalHull = 0.0;
        foreach ($byUnit as $entry) {
            $totalHull += (float) $entry['count'] * (float) $entry['hull'];
        }
        if ($totalHull <= 0) {
            return [
                'by_unit' => $byUnit,
                'losses_by_id' => [],
                'new_total_hull' => 0.0,
            ];
        }

        $losses = [];
        $newByUnit = [];
        foreach ($byUnit as $unitId => $entry) {
            $unitHullSlice = (float) $entry['count'] * (float) $entry['hull'];
            $share = $unitHullSlice / $totalHull;
            $damageOnUnit = $damage * $share;
            $unitsKilled = (int) floor($damageOnUnit / max(1.0, (float) $entry['hull']));
            if ($unitsKilled > $entry['count']) {
                $unitsKilled = $entry['count'];
            }
            if ($unitsKilled > 0) {
                $losses[$unitId] = $unitsKilled;
            }
            $remaining = $entry['count'] - $unitsKilled;
            if ($remaining > 0) {
                $newByUnit[$unitId] = [
                    'count' => $remaining,
                    'atk' => $entry['atk'],
                    'shi' => $entry['shi'],
                    'hull' => $entry['hull'],
                ];
            }
        }

        $newTotalHull = 0.0;
        foreach ($newByUnit as $entry) {
            $newTotalHull += (float) $entry['count'] * (float) $entry['hull'];
        }

        return [
            'by_unit' => $newByUnit,
            'losses_by_id' => $losses,
            'new_total_hull' => $newTotalHull,
        ];
    }
}

if (!function_exists('botCombatRecomputeAggregates')) {
    /**
     * @param array<int, array{count:int, atk:float, shi:float, hull:float}> $byUnit
     * @return array{total_attack:float, total_shield:float, total_hull:float}
     */
    function botCombatRecomputeAggregates(array $byUnit): array
    {
        $a = 0.0;
        $s = 0.0;
        $h = 0.0;
        foreach ($byUnit as $entry) {
            $count = (int) $entry['count'];
            $a += (float) $entry['atk'] * $count;
            $s += (float) $entry['shi'] * $count;
            $h += (float) $entry['hull'] * $count;
        }

        return ['total_attack' => $a, 'total_shield' => $s, 'total_hull' => $h];
    }
}

if (!function_exists('botCombatLossesValue')) {
    /**
     * Sums the build cost (m+c+d) of a losses_by_id map.
     *
     * @param array<int, int> $lossesById
     * @param array<int, array<string,int|float>> $pricelist
     */
    function botCombatLossesValue(array $lossesById, array $pricelist): int
    {
        $value = 0;
        foreach ($lossesById as $unitId => $count) {
            $price = $pricelist[(int) $unitId] ?? null;
            if (!is_array($price)) {
                continue;
            }
            $unit = (int) ($price['metal'] ?? 0)
                + (int) ($price['crystal'] ?? 0)
                + (int) ($price['deuterium'] ?? 0);
            $value += $unit * (int) $count;
        }

        return $value;
    }
}

if (!function_exists('botCombatDebrisFromLosses')) {
    /**
     * Computes the debris field generated by losses (30% of metal+crystal
     * cost of LOST SHIPS). Defenses do not generate debris in this model
     * (mirrors the stock engine convention).
     *
     * @param array<int, int> $lossesById
     * @param array<int, array<string,int|float>> $pricelist
     * @return array{metal:int, crystal:int}
     */
    function botCombatDebrisFromLosses(array $lossesById, array $pricelist): array
    {
        $metal = 0;
        $crystal = 0;
        foreach ($lossesById as $unitId => $count) {
            // Defenses (>=400) do not create debris.
            if ((int) $unitId >= 400) {
                continue;
            }
            $price = $pricelist[(int) $unitId] ?? null;
            if (!is_array($price)) {
                continue;
            }
            $metal += (int) floor(((int) ($price['metal'] ?? 0)) * 0.30 * (int) $count);
            $crystal += (int) floor(((int) ($price['crystal'] ?? 0)) * 0.30 * (int) $count);
        }

        return ['metal' => $metal, 'crystal' => $crystal];
    }
}

if (!function_exists('botCombatSimulate')) {
    /**
     * Runs a deterministic simplified simulation up to BOT_COMBAT_MAX_ROUNDS
     * rounds. See file header for algorithm details.
     *
     * @param array<int, int> $attackerShips
     * @param array<int, int> $defenderShips
     * @param array<int, int> $defenderDefenses
     * @param array<string, mixed> $attackerResearch
     * @param array<string, mixed> $defenderResearch
     * @param array<int, array<string, int|float>> $pricelist
     * @return array{
     *     winner: string,
     *     rounds_used: int,
     *     attacker_losses_by_id: array<int, int>,
     *     defender_losses_by_id: array<int, int>,
     *     attacker_losses_value: int,
     *     defender_losses_value: int,
     *     debris: array{metal:int, crystal:int}
     * }
     */
    function botCombatSimulate(
        array $attackerShips,
        array $defenderShips,
        array $defenderDefenses,
        array $attackerResearch,
        array $defenderResearch,
        array $pricelist
    ): array {
        $attacker = botCombatBuildSideStats($attackerShips, $attackerResearch, $pricelist);
        $defenderUnits = $defenderShips + $defenderDefenses; // arrays keyed by id (no overlap).
        $defender = botCombatBuildSideStats($defenderUnits, $defenderResearch, $pricelist);

        $attackerLossesTotal = [];
        $defenderLossesTotal = [];

        $attByUnit = $attacker['by_unit'];
        $defByUnit = $defender['by_unit'];
        $attTotals = ['total_attack' => $attacker['total_attack'], 'total_shield' => $attacker['total_shield'], 'total_hull' => $attacker['total_hull']];
        $defTotals = ['total_attack' => $defender['total_attack'], 'total_shield' => $defender['total_shield'], 'total_hull' => $defender['total_hull']];

        $rounds = 0;
        for ($r = 0; $r < BOT_COMBAT_MAX_ROUNDS; $r++) {
            if ($attTotals['total_hull'] <= 0 || $defTotals['total_hull'] <= 0) {
                break;
            }

            $rounds++;

            $damageToDefender = max(0.0, $attTotals['total_attack'] - $defTotals['total_shield']);
            $damageToAttacker = max(0.0, $defTotals['total_attack'] - $attTotals['total_shield']);

            // Apply damages simultaneously (so a side that dies still hits
            // back this same round, like the stock engine).
            $defResult = botCombatApplyDamage($defByUnit, $damageToDefender);
            $attResult = botCombatApplyDamage($attByUnit, $damageToAttacker);

            $defByUnit = $defResult['by_unit'];
            $attByUnit = $attResult['by_unit'];

            foreach ($defResult['losses_by_id'] as $id => $cnt) {
                $defenderLossesTotal[$id] = ($defenderLossesTotal[$id] ?? 0) + $cnt;
            }
            foreach ($attResult['losses_by_id'] as $id => $cnt) {
                $attackerLossesTotal[$id] = ($attackerLossesTotal[$id] ?? 0) + $cnt;
            }

            $attTotals = botCombatRecomputeAggregates($attByUnit);
            $defTotals = botCombatRecomputeAggregates($defByUnit);
        }

        $attackerAlive = $attTotals['total_hull'] > 0;
        $defenderAlive = $defTotals['total_hull'] > 0;
        if ($attackerAlive && !$defenderAlive) {
            $winner = 'attacker';
        } elseif (!$attackerAlive && $defenderAlive) {
            $winner = 'defender';
        } else {
            $winner = 'draw';
        }

        $combinedLosses = $attackerLossesTotal + $defenderLossesTotal;
        // Note: attacker and defender id sets do not overlap in practice
        // (defenses are 401+, ships 200..299), but defensive merge using
        // explicit per-side accumulation is safer.
        $debris = botCombatDebrisFromLosses($combinedLosses, $pricelist);

        return [
            'winner' => $winner,
            'rounds_used' => $rounds,
            'attacker_losses_by_id' => $attackerLossesTotal,
            'defender_losses_by_id' => $defenderLossesTotal,
            'attacker_losses_value' => botCombatLossesValue($attackerLossesTotal, $pricelist),
            'defender_losses_value' => botCombatLossesValue($defenderLossesTotal, $pricelist),
            'debris' => $debris,
        ];
    }
}
