<?php

declare(strict_types=1);

/**
 * Bot personality module.
 *
 * Generates and persists per-bot personalization derived from the seed:
 *  - Personal targets (mine/research caps, building goals, etc.)
 *  - Defense recipe (a coherent mix that determines defense composition)
 *  - Research order (a sensible-yet-distinct ordering respecting deps)
 *  - Planet roles (main/metal/crystal/industrial/military/support)
 *  - Quirks (small chance of "off-manual" choices, deterministic per bot)
 *
 * Everything is computed once and stored in bot_state. Subsequent runs reuse
 * the persisted values, so a single bot is internally coherent over time.
 *
 * Hard caps applied here come from BOT_CAP_* constants (see safety module).
 */

require_once __DIR__ . '/state.php';

const BOT_PERSONALITIES = ['minero', 'flotero', 'cazador', 'defensor', 'tecnologico'];

if (!function_exists('botPersonalTargets')) {
    /**
     * Generates personal targets ranges that each bot will try to reach.
     * Bounded by safety caps.
     *
     * @return array<string, int>
     */
    function botPersonalTargets(int $seed, string $personality): array
    {
        // Each target is a personal preferred level. The bot will keep
        // upgrading this building until it hits this number, but never above
        // it (caps applied later by safety module).
        $base = [
            'building_metal_mine' => botRngInt($seed, 't:metal_mine', 30, 50),
            'building_crystal_mine' => botRngInt($seed, 't:crystal_mine', 25, 45),
            'building_deuterium_sintetizer' => botRngInt($seed, 't:deut_synth', 20, 40),
            'building_solar_plant' => botRngInt($seed, 't:solar', 28, 45),
            'building_robot_factory' => botRngInt($seed, 't:robots', 10, 18),
            'building_nano_factory' => botRngInt($seed, 't:nanites', 4, 9),
            'building_hangar' => botRngInt($seed, 't:hangar', 8, 16),
            'building_metal_store' => botRngInt($seed, 't:metal_store', 9, 14),
            'building_crystal_store' => botRngInt($seed, 't:crystal_store', 8, 13),
            'building_deuterium_tank' => botRngInt($seed, 't:deut_tank', 7, 12),
            'building_laboratory' => botRngInt($seed, 't:lab', 10, 16),
        ];

        // Personality bias: shift the *preferred targets* up or down inside
        // sensible bounds (we never push below baseline efficiency).
        $shift = static function (array &$targets, string $col, int $delta) {
            if (isset($targets[$col])) {
                $targets[$col] += $delta;
            }
        };

        switch ($personality) {
            case 'minero':
                $shift($base, 'building_metal_mine', 5);
                $shift($base, 'building_crystal_mine', 4);
                $shift($base, 'building_deuterium_sintetizer', 3);
                $shift($base, 'building_solar_plant', 4);

                break;
            case 'flotero':
                $shift($base, 'building_hangar', 4);
                $shift($base, 'building_robot_factory', 2);
                $shift($base, 'building_nano_factory', 2);

                break;
            case 'cazador':
                $shift($base, 'building_hangar', 3);
                $shift($base, 'building_laboratory', 2);

                break;
            case 'defensor':
                $shift($base, 'building_robot_factory', 3);
                $shift($base, 'building_hangar', 2);
                $shift($base, 'building_metal_mine', 2);

                break;
            case 'tecnologico':
                $shift($base, 'building_laboratory', 4);
                $shift($base, 'building_crystal_mine', 2);

                break;
        }

        // Apply hard caps for safety. The cap module re-applies these at
        // action-time anyway, but this keeps the persisted target sane.
        $maxBuilding = defined('BOT_CAP_BUILDING_LEVEL') ? (int) BOT_CAP_BUILDING_LEVEL : 70;
        foreach ($base as $col => $level) {
            $base[$col] = min($maxBuilding, max(1, $level));
        }

        return $base;
    }
}

if (!function_exists('botDefenseRecipe')) {
    /**
     * Builds a coherent defense composition for the bot.
     *
     * Returns a list of [defense_id, column, weight] sorted by priority. The
     * weights sum to ~1.0 and are interpreted as the desired *fraction* of
     * total defense composition.
     *
     * Distinct bots produce distinct mixes, but every mix includes a strong
     * meatshield (rocket launcher) and at least one specialist.
     *
     * @return array<int, array{id:int, column:string, share:float}>
     */
    function botDefenseRecipe(int $seed, string $personality): array
    {
        $catalog = [
            ['id' => 401, 'column' => 'defense_rocket_launcher', 'role' => 'shield'],
            ['id' => 402, 'column' => 'defense_light_laser',     'role' => 'light'],
            ['id' => 403, 'column' => 'defense_heavy_laser',     'role' => 'medium'],
            ['id' => 404, 'column' => 'defense_ion_cannon',      'role' => 'medium'],
            ['id' => 405, 'column' => 'defense_gauss_cannon',    'role' => 'heavy'],
            ['id' => 406, 'column' => 'defense_plasma_turret',   'role' => 'heavy'],
        ];

        // Always include rocket launcher as primary meatshield. Then pick
        // 2-3 specialists to round out the composition.
        $primary = $catalog[0];
        $specialists = [$catalog[1], $catalog[2], $catalog[3], $catalog[4], $catalog[5]];
        $shuffled = botRngShuffle($seed, 'defense:specialists', $specialists);
        $count = botRngInt($seed, 'defense:variety', 2, 3);
        $picked = array_slice($shuffled, 0, $count);

        $primaryShareBase = $personality === 'defensor' ? 0.55 : 0.65;
        $primaryShare = $primaryShareBase + botRng($seed, 'defense:primary') * 0.15;
        $remaining = max(0.05, 1.0 - $primaryShare);

        $weights = [];
        foreach ($picked as $idx => $entry) {
            $w = botRng($seed, 'defense:w:' . $idx) + 0.2;
            $weights[$idx] = $w;
        }
        $sumWeights = array_sum($weights);

        $recipe = [
            ['id' => $primary['id'], 'column' => $primary['column'], 'share' => $primaryShare],
        ];
        foreach ($picked as $idx => $entry) {
            $share = $remaining * ($weights[$idx] / $sumWeights);
            $recipe[] = ['id' => $entry['id'], 'column' => $entry['column'], 'share' => $share];
        }

        return $recipe;
    }
}

if (!function_exists('botResearchOrder')) {
    /**
     * Builds a research priority list that each bot will follow.
     *
     * Constraints:
     *  - The *foundational* researches (energy 5, computer 4, espionage 4)
     *    always appear first to avoid nonsensical orders.
     *  - The remaining researches are reordered with a personality bias
     *    plus mild seeded shuffling, so two bots are not identical but each
     *    bot is internally efficient.
     *  - Targets are bounded by BOT_CAP_RESEARCH_LEVEL.
     *
     * @return array<int, array{id:int, column:string, target:int}>
     */
    function botResearchOrder(int $seed, string $personality): array
    {
        $maxResearch = defined('BOT_CAP_RESEARCH_LEVEL') ? (int) BOT_CAP_RESEARCH_LEVEL : 50;

        // Foundational tier: always identical for every bot. These are
        // requirements for the rest, so skipping or shuffling them would
        // produce nonsensical orders.
        $foundation = [
            ['id' => 113, 'column' => 'research_energy_technology',    'target' => 8],
            ['id' => 108, 'column' => 'research_computer_technology',  'target' => 6],
            ['id' => 106, 'column' => 'research_espionage_technology', 'target' => 4],
        ];

        // Secondary tier: ordered with a soft personality bias. Each bot
        // shuffles within the bias, so two 'flotero' bots still differ.
        $secondary = [
            ['id' => 109, 'column' => 'research_weapons_technology',       'target' => 10, 'bias' => ['flotero' => 3, 'cazador' => 3, 'defensor' => 2]],
            ['id' => 110, 'column' => 'research_shielding_technology',     'target' => 10, 'bias' => ['defensor' => 3, 'flotero' => 2]],
            ['id' => 111, 'column' => 'research_armour_technology',        'target' => 10, 'bias' => ['defensor' => 2, 'flotero' => 2]],
            ['id' => 115, 'column' => 'research_combustion_drive',         'target' => 8,  'bias' => ['flotero' => 3, 'minero' => 2]],
            ['id' => 117, 'column' => 'research_impulse_drive',            'target' => 6,  'bias' => ['flotero' => 3]],
            ['id' => 120, 'column' => 'research_laser_technology',         'target' => 8,  'bias' => ['cazador' => 2, 'tecnologico' => 2]],
            ['id' => 121, 'column' => 'research_ionic_technology',         'target' => 6,  'bias' => ['tecnologico' => 3]],
            ['id' => 124, 'column' => 'research_astrophysics',             'target' => 6,  'bias' => ['minero' => 3, 'tecnologico' => 2]],
            ['id' => 122, 'column' => 'research_plasma_technology',        'target' => 6,  'bias' => ['cazador' => 3, 'flotero' => 2]],
            ['id' => 114, 'column' => 'research_hyperspace_technology',    'target' => 4,  'bias' => ['flotero' => 2, 'tecnologico' => 2]],
            ['id' => 118, 'column' => 'research_hyperspace_drive',         'target' => 4,  'bias' => ['flotero' => 2]],
            ['id' => 123, 'column' => 'research_intergalactic_research_network', 'target' => 2, 'bias' => ['tecnologico' => 3, 'minero' => 1]],
        ];

        usort($secondary, static function (array $a, array $b) use ($personality, $seed): int {
            $biasA = (int) ($a['bias'][$personality] ?? 0);
            $biasB = (int) ($b['bias'][$personality] ?? 0);
            if ($biasA !== $biasB) {
                return $biasB <=> $biasA;
            }
            // Stable seeded jitter as tiebreaker.
            $ja = botRng($seed, 'research:jitter:' . $a['id']);
            $jb = botRng($seed, 'research:jitter:' . $b['id']);

            return $ja <=> $jb;
        });

        $list = [];
        foreach ($foundation as $entry) {
            $list[] = [
                'id' => $entry['id'],
                'column' => $entry['column'],
                'target' => min($maxResearch, $entry['target']),
            ];
        }
        foreach ($secondary as $entry) {
            $list[] = [
                'id' => $entry['id'],
                'column' => $entry['column'],
                'target' => min($maxResearch, $entry['target']),
            ];
        }

        return $list;
    }
}

if (!function_exists('botPlanetRole')) {
    /**
     * Returns a stable role for a (bot, planet) pair.
     *
     * The home planet is always 'main'. Other planets get a role weighted by
     * personality. Roles are persisted in bot_state once assigned, so a planet
     * role doesn't change between runs.
     *
     * Possible roles:
     *  - main:        balanced; runs research, fleet, defense.
     *  - metal:       biased toward metal mine progression.
     *  - crystal:     biased toward crystal mine progression.
     *  - industrial:  biased toward shipyard / nanite / robotics.
     *  - military:    biased toward fleet + defense.
     *  - support:     cargo + economy + storage.
     */
    function botPlanetRole(
        int $seed,
        string $personality,
        int $planetId,
        bool $isHome
    ): string {
        if ($isHome) {
            return 'main';
        }

        $weightsByPersonality = [
            'minero' => [
                ['weight' => 3.5, 'value' => 'metal'],
                ['weight' => 2.5, 'value' => 'crystal'],
                ['weight' => 2.0, 'value' => 'support'],
                ['weight' => 1.0, 'value' => 'industrial'],
                ['weight' => 0.5, 'value' => 'military'],
            ],
            'flotero' => [
                ['weight' => 3.0, 'value' => 'industrial'],
                ['weight' => 2.5, 'value' => 'military'],
                ['weight' => 2.0, 'value' => 'metal'],
                ['weight' => 1.5, 'value' => 'crystal'],
                ['weight' => 1.0, 'value' => 'support'],
            ],
            'cazador' => [
                ['weight' => 3.0, 'value' => 'military'],
                ['weight' => 2.5, 'value' => 'industrial'],
                ['weight' => 2.0, 'value' => 'metal'],
                ['weight' => 1.5, 'value' => 'crystal'],
                ['weight' => 1.0, 'value' => 'support'],
            ],
            'defensor' => [
                ['weight' => 3.0, 'value' => 'military'],
                ['weight' => 2.5, 'value' => 'metal'],
                ['weight' => 2.0, 'value' => 'industrial'],
                ['weight' => 1.5, 'value' => 'crystal'],
                ['weight' => 1.0, 'value' => 'support'],
            ],
            'tecnologico' => [
                ['weight' => 2.5, 'value' => 'industrial'],
                ['weight' => 2.5, 'value' => 'crystal'],
                ['weight' => 2.0, 'value' => 'metal'],
                ['weight' => 1.5, 'value' => 'support'],
                ['weight' => 1.0, 'value' => 'military'],
            ],
        ];

        $weights = $weightsByPersonality[$personality] ?? $weightsByPersonality['minero'];

        return (string) (botRngWeightedPick($seed, 'role:' . $planetId, $weights) ?? 'metal');
    }
}

if (!function_exists('botQuirks')) {
    /**
     * Generates per-bot quirks: small "off-manual" preferences that make
     * each bot's behavior less mechanical without breaking efficiency.
     *
     * @return array<string, mixed>
     */
    function botQuirks(int $seed): array
    {
        return [
            // Probability per action of doing something off-pattern.
            'quirk_chance' => round(botRng($seed, 'quirk_chance') * 0.06 + 0.02, 4),
            // Favourite ship the bot likes to overbuild.
            'favourite_ship_id' => botRngPick($seed, 'fav_ship', [
                202, 203, 204, 205, 206, 207, 211, 213, 215,
            ]),
            // Favourite defense to over-include.
            'favourite_defense_id' => botRngPick($seed, 'fav_defense', [
                403, 404, 405, 406,
            ]),
            // Tolerated storage fill before slowing eco.
            'storage_panic_threshold' => round(0.78 + botRng($seed, 'storage_panic') * 0.18, 3),
            // Per-bot deuterium hoard ratio (0..0.4): how much deuterium it
            // refuses to send away even if eligible to transport.
            'deuterium_hoard_ratio' => round(botRng($seed, 'deut_hoard') * 0.4, 3),
        ];
    }
}

if (!function_exists('botInitializePersonalityIfMissing')) {
    /**
     * Ensures the personality fields (targets, defense recipe, research order,
     * planet roles, quirks) are populated for the bot. Idempotent: only fills
     * what's missing.
     *
     * @param array<string, mixed> $state Existing bot_state row (mutated in place).
     * @param array<int, array<string, mixed>> $planets Planet rows for the user.
     * @param int $homePlanetId
     */
    function botInitializePersonalityIfMissing(
        mysqli $db,
        string $prefix,
        array &$state,
        array $planets,
        int $homePlanetId
    ): void {
        $userId = (int) $state['bot_user_id'];
        $seed = (int) $state['bot_seed'];
        $personality = (string) ($state['bot_personality'] ?? 'minero');

        $updates = [];

        if (empty($state['bot_personal_targets'])) {
            $state['bot_personal_targets'] = botPersonalTargets($seed, $personality);
            $updates['bot_personal_targets'] = $state['bot_personal_targets'];
        }
        if (empty($state['bot_defense_recipe'])) {
            $state['bot_defense_recipe'] = botDefenseRecipe($seed, $personality);
            $updates['bot_defense_recipe'] = $state['bot_defense_recipe'];
        }
        if (empty($state['bot_research_order'])) {
            $state['bot_research_order'] = botResearchOrder($seed, $personality);
            $updates['bot_research_order'] = $state['bot_research_order'];
        }
        if (empty($state['bot_quirks'])) {
            $state['bot_quirks'] = botQuirks($seed);
            $updates['bot_quirks'] = $state['bot_quirks'];
        }

        // Planet roles are stored as a map planet_id => role. Add any missing
        // planets without disturbing existing entries.
        $roles = is_array($state['bot_planet_roles'] ?? null) ? $state['bot_planet_roles'] : [];
        $rolesChanged = false;
        foreach ($planets as $planet) {
            $pid = (int) $planet['planet_id'];
            $key = (string) $pid;
            if (!isset($roles[$key]) || $roles[$key] === '') {
                $roles[$key] = botPlanetRole(
                    $seed,
                    $personality,
                    $pid,
                    $pid === $homePlanetId
                );
                $rolesChanged = true;
            }
        }
        if ($rolesChanged) {
            $state['bot_planet_roles'] = $roles;
            $updates['bot_planet_roles'] = $roles;
        }

        if (!empty($updates) && empty($state['__synthetic'])) {
            saveBotStateFields($db, $prefix, $userId, $updates);
        }
    }
}

if (!function_exists('botGetPlanetRole')) {
    function botGetPlanetRole(array $state, int $planetId): string
    {
        $roles = is_array($state['bot_planet_roles'] ?? null) ? $state['bot_planet_roles'] : [];
        $key = (string) $planetId;

        return isset($roles[$key]) ? (string) $roles[$key] : 'metal';
    }
}
