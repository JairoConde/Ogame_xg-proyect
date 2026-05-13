<?php

declare(strict_types=1);

/**
 * Bot global strategy module.
 *
 * Adds decisions that span across all of a user's planets, plus reusable
 * helpers that the orchestrator (simple_rule_bot.php) can call once per loop:
 *
 *   - botPickPlanetForAction(): given a list of planets, pick the most
 *     suitable one for an action of a given category (eco / mil / tech).
 *
 *   - botMaintainLongTermGoal(): maintain a slowly-evolving long-term goal
 *     (e.g. "build N battleships"); periodically re-evaluate it and store
 *     progress in bot_state.
 *
 *   - botCanQueueWithoutBlocking(): cheap heuristic to avoid filling the
 *     planet build queue with cheap items when an expensive important
 *     action is also pending.
 *
 *   - botDetectStuckPlanet(): when a planet has produced no progress in a
 *     long while, mark it for re-strategy (currently we just clear its
 *     'role' so it gets reassigned on next personality init).
 *
 *   - botColonizationAllowed(): central rule of which (galaxy, system,
 *     planet) tuples are valid. Reserved positions: 1,2,6,7,8,13,14,15.
 *     Reserved systems: first 10 of every galaxy.
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/personality.php';
require_once __DIR__ . '/safety.php';
require_once __DIR__ . '/util.php';

if (!defined('BOT_RESERVED_POSITIONS')) {
    // Player wants these positions reserved for other purposes; bots must
    // never colonize there.
    define('BOT_RESERVED_POSITIONS', [1, 2, 6, 7, 8, 13, 14, 15]);
}

if (!defined('BOT_RESERVED_SYSTEMS_PER_GALAXY')) {
    // First N systems of every galaxy are reserved (no colonization there).
    define('BOT_RESERVED_SYSTEMS_PER_GALAXY', 10);
}

if (!function_exists('botColonizationAllowedPositions')) {
    /**
     * Returns the positions a bot may colonize, derived from total positions
     * minus BOT_RESERVED_POSITIONS.
     *
     * @return array<int, int>
     */
    function botColonizationAllowedPositions(): array
    {
        $totalPositions = 15;
        $allowed = [];
        for ($p = 1; $p <= $totalPositions; $p++) {
            if (!in_array($p, BOT_RESERVED_POSITIONS, true)) {
                $allowed[] = $p;
            }
        }

        return $allowed; // -> [3, 4, 5, 9, 10, 11, 12]
    }
}

if (!function_exists('botColonizationAllowed')) {
    /**
     * Central rule: returns true when (galaxy, system, planet) is colonizable
     * by a bot.
     */
    function botColonizationAllowed(int $galaxy, int $system, int $planet): bool
    {
        if ($system <= BOT_RESERVED_SYSTEMS_PER_GALAXY) {
            return false;
        }
        if (in_array($planet, BOT_RESERVED_POSITIONS, true)) {
            return false;
        }

        return true;
    }
}

// ===========================================================================
// LONG-TERM GOAL
// ===========================================================================

if (!function_exists('botMaintainLongTermGoal')) {
    /**
     * Periodically picks (or re-picks) a long-term goal for the bot. Goals
     * are intentionally vague and align with personality so the bot stays
     * coherent without micromanagement.
     *
     * The current goal mostly informs the focus rotation; we don't enforce
     * it at action time (that would over-constrain). Persistence here is
     * useful for future tuning and for visibility in logs.
     *
     * @param array<string, mixed> $state Mutated in place.
     */
    function botMaintainLongTermGoal(mysqli $db, string $prefix, array &$state): void
    {
        $current = (string) ($state['bot_long_term_goal'] ?? '');
        $progress = (int) ($state['bot_long_term_progress'] ?? 0);
        $target = (int) ($state['bot_long_term_target'] ?? 0);
        $needsRefresh = $current === '' || ($target > 0 && $progress >= $target);

        if (!$needsRefresh) {
            return;
        }

        $personality = (string) ($state['bot_personality'] ?? 'minero');
        $seed = (int) ($state['bot_seed'] ?? 0);

        $byPersonality = [
            'minero' => [
                ['weight' => 3.0, 'goal' => 'mines_balance', 'target' => 100], // sum of mine levels
                ['weight' => 1.5, 'goal' => 'cargo_fleet',   'target' => 200],
                ['weight' => 1.0, 'goal' => 'tech_basics',   'target' => 30],
            ],
            'flotero' => [
                ['weight' => 3.0, 'goal' => 'fighter_pack',     'target' => 1500],
                ['weight' => 1.8, 'goal' => 'shipyard_upgrade', 'target' => 14],
                ['weight' => 1.0, 'goal' => 'tech_weapons',     'target' => 14],
            ],
            'cazador' => [
                ['weight' => 3.0, 'goal' => 'cruiser_pack',     'target' => 600],
                ['weight' => 2.0, 'goal' => 'tech_weapons',     'target' => 14],
                ['weight' => 1.5, 'goal' => 'plasma_focus',     'target' => 6],
            ],
            'defensor' => [
                ['weight' => 3.0, 'goal' => 'defense_wall', 'target' => 800],
                ['weight' => 1.5, 'goal' => 'mines_balance', 'target' => 80],
                ['weight' => 1.0, 'goal' => 'tech_shields',  'target' => 12],
            ],
            'tecnologico' => [
                ['weight' => 3.0, 'goal' => 'lab_dominance', 'target' => 14],
                ['weight' => 2.0, 'goal' => 'tech_basics',   'target' => 50],
                ['weight' => 1.0, 'goal' => 'astrophysics',  'target' => 8],
            ],
        ];

        $weights = $byPersonality[$personality] ?? $byPersonality['minero'];
        $picked = botRngWeightedPick($seed, 'long_term:' . time(), array_map(
            static fn (array $w) => ['weight' => $w['weight'], 'value' => ['goal' => $w['goal'], 'target' => $w['target']]],
            $weights
        )) ?? ['goal' => 'mines_balance', 'target' => 100];

        $state['bot_long_term_goal'] = (string) $picked['goal'];
        $state['bot_long_term_progress'] = 0;
        $state['bot_long_term_target'] = (int) $picked['target'];

        if (empty($state['__synthetic'])) {
            saveBotStateFields($db, $prefix, (int) $state['bot_user_id'], [
                'bot_long_term_goal' => $state['bot_long_term_goal'],
                'bot_long_term_progress' => 0,
                'bot_long_term_target' => $state['bot_long_term_target'],
            ]);
        }
    }
}

// ===========================================================================
// QUEUE INTELLIGENCE
// ===========================================================================

if (!function_exists('botShouldThrottleQueue')) {
    /**
     * Returns true when the bot should NOT add a new (cheap) item to a planet
     * queue because there's a more important pending action that needs the
     * resources.
     *
     * Heuristic: if the queue already has 3+ items, only allow new queue
     * entries that are mines, energy, or storage (these are the "always
     * useful" items). Block ships/defenses if queue is busy.
     *
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $action
     */
    function botShouldThrottleQueue(array $planet, array $action): bool
    {
        $type = (string) ($action['type'] ?? '');
        $col = (string) ($action['column'] ?? '');
        if ($type === 'building' && botPlanetEnergyIsDeficit($planet)) {
            if ($col !== 'building_solar_plant') {
                return true;
            }
        }

        $queue = (string) ($planet['planet_b_building_id'] ?? '0');
        if ($queue === '' || $queue === '0') {
            return false;
        }
        $items = array_values(array_filter(explode(';', $queue), static fn (string $item): bool => $item !== ''));
        if (count($items) < 3) {
            return false;
        }
        if ($type !== 'building') {
            // Ship/defense queue is a separate queue; building queue size
            // does not block it.
            return false;
        }
        $alwaysAllowed = [
            'building_metal_mine',
            'building_crystal_mine',
            'building_deuterium_sintetizer',
            'building_solar_plant',
            'building_metal_store',
            'building_crystal_store',
            'building_deuterium_tank',
        ];

        return !in_array($col, $alwaysAllowed, true);
    }
}

// ===========================================================================
// AUTO-RECOVERY
// ===========================================================================

if (!function_exists('botDetectStuckPlanet')) {
    /**
     * If a planet hasn't progressed in N days (no building queue, low
     * resources, no recent updates), reset its role so the bot reassigns
     * one. This is a soft self-heal.
     *
     * @param array<string, mixed> $state Mutated in place.
     * @param array<string, mixed> $planet
     */
    function botDetectStuckPlanet(mysqli $db, string $prefix, array &$state, array $planet): void
    {
        $lastUpdate = (int) ($planet['planet_last_update'] ?? time());
        if (time() - $lastUpdate < 3 * 86400) {
            return;
        }

        $queue = (string) ($planet['planet_b_building_id'] ?? '0');
        $hangarQueue = (string) ($planet['planet_b_hangar_id'] ?? '');
        if ($queue !== '0' && $queue !== '') {
            return;
        }
        if ($hangarQueue !== '') {
            return;
        }

        $roles = is_array($state['bot_planet_roles'] ?? null) ? $state['bot_planet_roles'] : [];
        $key = (string) (int) $planet['planet_id'];
        if (!isset($roles[$key])) {
            return;
        }
        unset($roles[$key]);
        $state['bot_planet_roles'] = $roles;

        if (empty($state['__synthetic'])) {
            saveBotStateFields($db, $prefix, (int) $state['bot_user_id'], [
                'bot_planet_roles' => $roles,
            ]);
        }
    }
}

// ===========================================================================
// GLOBAL ACTION DISTRIBUTION
// ===========================================================================

if (!function_exists('botSortPlanetsForActions')) {
    /**
     * Returns the planet list reordered so the bot processes the "most
     * deserving" planet first. Heuristic:
     *  - Planets with active queues already get *less* priority (they're
     *    already busy).
     *  - Planets with high resources idle and no queue get more priority.
     *  - The home planet is never starved (always at most 2 positions away
     *    from the head).
     *
     * @param array<int, array<string, mixed>> $planets
     * @param int $homePlanetId
     * @return array<int, array<string, mixed>>
     */
    function botSortPlanetsForActions(array $planets, int $homePlanetId): array
    {
        $scored = [];
        foreach ($planets as $planet) {
            $pid = (int) $planet['planet_id'];
            $queue = (string) ($planet['planet_b_building_id'] ?? '0');
            $busy = !($queue === '0' || $queue === '');
            $idleResources = (float) $planet['planet_metal'] + (float) $planet['planet_crystal'];
            $score = $idleResources;
            if ($busy) {
                $score *= 0.5;
            }
            if ($pid === $homePlanetId) {
                $score *= 1.3;
            }
            $scored[] = ['score' => $score, 'planet' => $planet];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(static fn (array $entry) => $entry['planet'], $scored);
    }
}
