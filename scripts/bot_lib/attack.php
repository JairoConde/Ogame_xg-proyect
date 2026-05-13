<?php

declare(strict_types=1);

/**
 * Bot purposeful attack module (Block D).
 *
 * Adds spy + attack capability to the bot. Mirrors the patterns of
 * scripts/bot_lib/transport.php (cooldowns in bot_quirks JSON, real fleet
 * INSERTs, no schema change). Two phases:
 *
 *   1. SPY: when the bot has no fresh intel for a candidate target, it
 *      sends real espionage probes (mission=6) using the same INSERT path
 *      a real player would. The fleet_id and target_planet_id are recorded
 *      in bot_quirks['pending_spies'].
 *
 *   2. INTEL HARVEST: in subsequent loops, when the spy has arrived (i.e.
 *      now >= expected_arrival), the bot reads the target's CURRENT
 *      ships/defenses/resources directly from the DB (rather than parsing
 *      the message HTML report — too brittle) and stores a snapshot in
 *      bot_quirks['intel'][target_planet_id]. Snapshots expire after
 *      BOT_INTEL_TTL_SECONDS.
 *
 *   3. ATTACK: with fresh intel, the bot runs a deterministic battle
 *      simulator (botCombatSimulate), computes (loot - fuel) vs the value
 *      of the ships it expects to lose, and if the ratio meets the
 *      threshold for its aggressiveness bracket, it INSERTs an attack
 *      fleet (mission=1).
 *
 * Filters applied to candidate targets (in order):
 *   - planet_type = 1 (no moons).
 *   - target user_id != bot user_id.
 *   - target user_ally_id != bot user_ally_id (when bot has an ally).
 *   - preference_vacation_mode = 0.
 *   - user_banned = 0.
 *   - planet_system NOT IN BOT_ATTACK_RESERVED_SYSTEMS (default: [1]).
 *   - Spy cooldown per target.
 *
 * Aggressiveness scoring is in [0, 10] and combines profile.aggressiveness +
 * personality + archetype + style + focus + source planet role.
 *
 * Profitability ratio: (loot_estimado - fuel) >= X * valor_naves_perdidas.
 * X comes from one of four constants (very_aggressive..conservative) or a
 * per-profile override in bot_accounts.json.
 *
 * Public entry point: botAttackRunPurposeful().
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/strategy.php';
require_once __DIR__ . '/safety.php';
require_once __DIR__ . '/travel.php';
require_once __DIR__ . '/personality.php';
require_once __DIR__ . '/combat.php';

if (!function_exists('botAttackHasOffensiveLeaning')) {
    /**
     * Returns true when the bot is "naturally inclined" to retaliate against
     * a recent attacker. Used as a filter for the reactive aggressiveness
     * bonus: a deep miner/defensor should NOT switch to a vengeful attacker
     * just because they got probed once.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $state
     */
    function botAttackHasOffensiveLeaning(array $profile, array $state): bool
    {
        $personality = (string) ($state['bot_personality'] ?? 'minero');
        if ($personality === 'cazador' || $personality === 'flotero') {
            return true;
        }
        $archetype = (string) ($state['bot_archetype'] ?? 'balanced');
        if ($archetype === 'turbo' || $archetype === 'opportunist') {
            return true;
        }
        $style = (string) ($profile['bot_style'] ?? 'granja');
        if ($style === 'raider') {
            return true;
        }

        return false;
    }
}

if (!function_exists('botAttackReactiveBonus')) {
    /**
     * Linearly-decreasing revenge bonus for the aggressiveness score while
     * the bot is within its post-attack reactive window. Caller decides
     * whether to apply it (typically gated by botAttackHasOffensiveLeaning).
     *
     * Inputs:
     *   - bot_last_attacked_at (unix ts when the latest hostile fleet was
     *     created against us).
     *   - bot_attack_reactive_until (unix ts when the reactive window expires;
     *     typically bot_last_attacked_at + 6h).
     *
     * Output: 0.0 when outside the window or window data is missing. Inside
     * the window, returns BOT_ATTACK_REACTIVE_BONUS_MAX (default 2.0) at the
     * very moment of the attack and linearly decays to 0.0 when now reaches
     * reactive_until.
     *
     * @param array<string, mixed> $state
     */
    function botAttackReactiveBonus(array $state, ?int $now = null): float
    {
        $now = $now ?? time();
        $reactiveUntil = (int) ($state['bot_attack_reactive_until'] ?? 0);
        $lastAttackedAt = (int) ($state['bot_last_attacked_at'] ?? 0);
        if ($reactiveUntil <= $now || $lastAttackedAt <= 0 || $reactiveUntil <= $lastAttackedAt) {
            return 0.0;
        }

        $bonusMax = defined('BOT_ATTACK_REACTIVE_BONUS_MAX')
            ? (float) BOT_ATTACK_REACTIVE_BONUS_MAX
            : 2.0;

        $window = $reactiveUntil - $lastAttackedAt;
        $elapsed = max(0, $now - $lastAttackedAt);
        $remaining = max(0.0, 1.0 - ($elapsed / $window));

        return $bonusMax * $remaining;
    }
}

if (!function_exists('botAttackAggressivenessScore')) {
    /**
     * Computes a 0..10 aggressiveness score combining profile setting +
     * personality + archetype + style + focus. Optional source role
     * argument (military/industrial/etc.) further nudges the score.
     *
     * If the bot is inside its reactive window (bot_attack_reactive_until in
     * the future) AND has an offensive leaning (cazador/flotero/turbo/
     * opportunist/raider), a linearly-decreasing revenge bonus is added on
     * top (max +2.0 right after the attack, → 0.0 as the window closes).
     *
     * @param array<string, mixed> $profile profile from bot_accounts.json
     * @param array<string, mixed> $state   bot_state row
     * @param string|null $sourceRole       role of the planet launching the attack
     * @param int|null    $now              optional override for testability
     */
    function botAttackAggressivenessScore(
        array $profile,
        array $state,
        ?string $sourceRole = null,
        ?int $now = null
    ): float {
        $score = (float) ($profile['aggressiveness'] ?? 3);

        $personality = (string) ($state['bot_personality'] ?? 'minero');
        switch ($personality) {
            case 'cazador':
                $score += 1.5;

                break;
            case 'flotero':
                $score += 1.0;

                break;
            case 'tecnologico':
                $score += 0.0;

                break;
            case 'defensor':
                $score -= 1.0;

                break;
            case 'minero':
                $score -= 1.5;

                break;
        }

        $archetype = (string) ($state['bot_archetype'] ?? 'balanced');
        switch ($archetype) {
            case 'turbo':
                $score += 1.0;

                break;
            case 'opportunist':
                $score += 0.7;

                break;
            case 'turtle':
                $score -= 1.0;

                break;
        }

        $style = (string) ($profile['bot_style'] ?? 'granja');
        switch ($style) {
            case 'raider':
                $score += 1.0;

                break;
            case 'bunker':
                $score -= 1.0;

                break;
        }

        $focus = (string) ($state['bot_current_focus'] ?? 'eco');
        switch ($focus) {
            case 'mil':
                $score += 0.8;

                break;
            case 'eco':
                $score -= 0.5;

                break;
        }

        if ($sourceRole !== null) {
            switch ($sourceRole) {
                case 'military':
                case 'industrial':
                    $score += 0.5;

                    break;
                case 'metal':
                case 'crystal':
                case 'support':
                    $score -= 0.3;

                    break;
            }
        }

        if (botAttackHasOffensiveLeaning($profile, $state)) {
            $score += botAttackReactiveBonus($state, $now);
        }

        return max(0.0, min(10.0, $score));
    }
}

if (!function_exists('botAttackProfitabilityRatio')) {
    /**
     * Returns the minimum (loot_neto / valor_naves_perdidas) ratio that
     * justifies an attack for this aggressiveness score and (optional)
     * profile override.
     *
     * Resolution order:
     *   1. profile.attack_ratio (single value, ignores aggressiveness).
     *   2. profile.attack_ratios.{very_aggressive|aggressive|moderate|conservative}.
     *   3. Global BOT_ATTACK_RATIO_* constants.
     *
     * Always clamped to [BOT_ATTACK_RATIO_MIN, BOT_ATTACK_RATIO_MAX].
     *
     * @param array<string, mixed> $profile
     */
    function botAttackProfitabilityRatio(float $aggressiveness, array $profile = []): float
    {
        $bracket = botAttackAggressivenessBracket($aggressiveness);

        $clampMin = defined('BOT_ATTACK_RATIO_MIN') ? (float) BOT_ATTACK_RATIO_MIN : 1.5;
        $clampMax = defined('BOT_ATTACK_RATIO_MAX') ? (float) BOT_ATTACK_RATIO_MAX : 10.0;
        $clamp = static function (float $v) use ($clampMin, $clampMax): float {
            return max($clampMin, min($clampMax, $v));
        };

        // 1) Single override.
        if (isset($profile['attack_ratio']) && is_numeric($profile['attack_ratio'])) {
            return $clamp((float) $profile['attack_ratio']);
        }

        // 2) Per-bracket override map.
        $perBracket = $profile['attack_ratios'] ?? null;
        if (is_array($perBracket) && isset($perBracket[$bracket]) && is_numeric($perBracket[$bracket])) {
            return $clamp((float) $perBracket[$bracket]);
        }

        // 3) Global defaults.
        switch ($bracket) {
            case 'very_aggressive':
                $value = defined('BOT_ATTACK_RATIO_VERY_AGGRESSIVE') ? (float) BOT_ATTACK_RATIO_VERY_AGGRESSIVE : 3.0;

                break;
            case 'aggressive':
                $value = defined('BOT_ATTACK_RATIO_AGGRESSIVE') ? (float) BOT_ATTACK_RATIO_AGGRESSIVE : 4.0;

                break;
            case 'conservative':
                $value = defined('BOT_ATTACK_RATIO_CONSERVATIVE') ? (float) BOT_ATTACK_RATIO_CONSERVATIVE : 6.0;

                break;
            case 'moderate':
            default:
                $value = defined('BOT_ATTACK_RATIO_MODERATE') ? (float) BOT_ATTACK_RATIO_MODERATE : 5.0;

                break;
        }

        return $clamp($value);
    }
}

if (!function_exists('botAttackAggressivenessBracket')) {
    /**
     * Maps a 0..10 aggressiveness score to one of four named brackets.
     * Boundaries are inclusive on the low side, exclusive on the high
     * side except the top bracket.
     */
    function botAttackAggressivenessBracket(float $aggressiveness): string
    {
        if ($aggressiveness >= 8.0) {
            return 'very_aggressive';
        }
        if ($aggressiveness >= 6.0) {
            return 'aggressive';
        }
        if ($aggressiveness >= 4.0) {
            return 'moderate';
        }

        return 'conservative';
    }
}

if (!function_exists('botAttackPersonalityCooldown')) {
    /**
     * Per-personality attack cooldown override.
     */
    function botAttackPersonalityCooldown(string $personality): int
    {
        $base = defined('BOT_ATTACK_COOLDOWN_BASE_SECONDS') ? (int) BOT_ATTACK_COOLDOWN_BASE_SECONDS : 1800;
        switch ($personality) {
            case 'cazador':
                return max(300, $base - 600); // 20 min
            case 'flotero':
                return max(300, $base - 300); // 25 min
            case 'defensor':
                return $base + 1800; // 60 min
            case 'minero':
                return $base + 600; // 40 min
            default:
                return $base;
        }
    }
}

if (!function_exists('botAttackLoadCooldowns')) {
    /**
     * Returns the four cooldown / state maps from bot_quirks JSON. Always
     * returns arrays (empty when missing), so callers don't have to null-check.
     *
     * @return array{
     *   attack_cooldowns: array<int, int>,
     *   spy_cooldowns: array<int, int>,
     *   pending_spies: array<int, array<string, mixed>>,
     *   intel: array<int, array<string, mixed>>
     * }
     */
    function botAttackLoadCooldowns(array $state): array
    {
        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];

        $loadIntMap = static function ($raw): array {
            if (!is_array($raw)) {
                return [];
            }
            $out = [];
            foreach ($raw as $k => $v) {
                $out[(int) $k] = (int) $v;
            }

            return $out;
        };

        $loadStructMap = static function ($raw): array {
            if (!is_array($raw)) {
                return [];
            }
            $out = [];
            foreach ($raw as $k => $v) {
                if (is_array($v)) {
                    $out[(int) $k] = $v;
                }
            }

            return $out;
        };

        return [
            'attack_cooldowns' => $loadIntMap($quirks['attack_cooldowns'] ?? null),
            'spy_cooldowns' => $loadIntMap($quirks['spy_cooldowns'] ?? null),
            'pending_spies' => $loadStructMap($quirks['pending_spies'] ?? null),
            'intel' => $loadStructMap($quirks['intel'] ?? null),
        ];
    }
}

if (!function_exists('botAttackMergeMetrics')) {
    /**
     * Folds the per-loop delta into the persistent metrics object stored
     * inside bot_quirks. Counters accumulate (sum), timestamp fields keep
     * the maximum value seen, first_seen_at is sticky (set once), and
     * loops_processed increments by 1 on every call.
     *
     * Pure helper: no DB access. Centralizes the metrics shape so it can
     * be unit tested in isolation and so botAttackSaveCooldowns remains
     * the only function that flushes bot_quirks to the database.
     *
     * @param array<string, mixed>          $state  Bot state, mutated in place.
     * @param array<string, int|string>     $delta  Per-loop counters/timestamps.
     */
    function botAttackMergeMetrics(array &$state, array $delta): void
    {
        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
        $metrics = is_array($quirks['metrics'] ?? null) ? $quirks['metrics'] : [];

        // Counter fields: accumulate.
        $counterKeys = [
            'spies_sent',
            'intel_captured',
            'attacks_launched',
            'attacks_skipped_low_loot',
            'attacks_skipped_capped_loot',
            'attacks_skipped_sim_lose',
            'attacks_skipped_ratio',
            'attacks_skipped_draw',
            'attacks_insert_fail',
            'spies_insert_fail',
            'raidmix_fail',
            'no_spy_src_events',
            'no_atk_src_events',
            'cooldown_spy_events',
            'fresh_intel_events',
            'attacks_throttled',
            'spies_throttled',
            'harvest_only_loops',
            'reactive_attacks_launched',
            'total_loot_launched',
            'total_my_losses_value',
            'attacks_completed',
            'attacks_completed_no_data',
            'total_loot_returned',
            'real_ships_lost_value',
            'attacks_armed',
            'attacks_recheck_confirmed',
            'attacks_aborted_sim_lose',
            'attacks_aborted_low_ratio',
            'attacks_aborted_low_loot',
            'attacks_aborted_no_target',
            'attacks_aborted_no_source',
            'attacks_aborted_stale_plan',
        ];
        foreach ($counterKeys as $k) {
            $cur = (int) ($metrics[$k] ?? 0);
            $add = (int) ($delta[$k] ?? 0);
            $metrics[$k] = $cur + $add;
        }

        // Timestamp fields: keep the most recent value seen, never go
        // backwards if a stale delta arrives.
        $timestampKeys = ['last_spy_at', 'last_attack_at', 'last_loop_at'];
        foreach ($timestampKeys as $k) {
            $cur = (int) ($metrics[$k] ?? 0);
            $add = (int) ($delta[$k] ?? 0);
            if ($add > $cur) {
                $metrics[$k] = $add;
            }
        }

        // first_seen_at is sticky: written only the first time we merge.
        if (empty($metrics['first_seen_at'])) {
            $firstSeen = (int) ($delta['last_loop_at'] ?? time());
            $metrics['first_seen_at'] = $firstSeen;
        }

        // loops_processed grows by exactly 1 per merge call so callers can
        // compute a "loops since first seen" gauge for offline analysis.
        $metrics['loops_processed'] = (int) ($metrics['loops_processed'] ?? 0) + 1;

        $quirks['metrics'] = $metrics;
        $state['bot_quirks'] = $quirks;
    }
}

if (!function_exists('botAttackTruncateIntelByRecency')) {
    /**
     * Keeps at most $maxEntries intel snapshots, preferring the most
     * recently captured (captured_at descending). Tie-break: higher
     * planet_id wins so ordering is deterministic.
     *
     * @param array<int, array<string, mixed>> $intel
     * @return array<int, array<string, mixed>>
     */
    function botAttackTruncateIntelByRecency(array $intel, int $maxEntries): array
    {
        if ($maxEntries <= 0 || count($intel) <= $maxEntries) {
            return $intel;
        }

        $pairs = [];
        foreach ($intel as $planetId => $entry) {
            $pairs[] = [
                'id' => (int) $planetId,
                'captured' => (int) ($entry['captured_at'] ?? 0),
                'entry' => $entry,
            ];
        }
        usort(
            $pairs,
            static function (array $x, array $y): int {
                if ($x['captured'] !== $y['captured']) {
                    return $y['captured'] <=> $x['captured'];
                }

                return $y['id'] <=> $x['id'];
            }
        );

        $out = [];
        for ($i = 0; $i < $maxEntries; $i++) {
            $id = $pairs[$i]['id'];
            $out[$id] = $pairs[$i]['entry'];
        }

        return $out;
    }
}

if (!function_exists('botAttackSaveCooldowns')) {
    /**
     * Persists the merged attack/spy/pending/intel maps back into
     * bot_quirks JSON, dropping anything older than 24h to keep the
     * column small. Intel entries surviving the TTL filter are then
     * truncated to BOT_INTEL_MAX_ENTRIES (most recent captured_at first).
     * If $metricsDelta is provided, it is folded into
     * bot_quirks.metrics before the row is flushed so all writes stay
     * in a single UPDATE.
     *
     * @param array<int, int> $attackCooldowns
     * @param array<int, int> $spyCooldowns
     * @param array<int, array<string, mixed>> $pendingSpies
     * @param array<int, array<string, mixed>> $intel
     * @param array<string, int|string> $metricsDelta
     */
    function botAttackSaveCooldowns(
        mysqli $db,
        string $prefix,
        array &$state,
        array $attackCooldowns,
        array $spyCooldowns,
        array $pendingSpies,
        array $intel,
        array $metricsDelta = []
    ): void {
        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];

        $now = time();
        $threshold = $now - 86400;

        $cleanAttackCd = [];
        foreach ($attackCooldowns as $k => $v) {
            if ((int) $v >= $threshold) {
                $cleanAttackCd[(int) $k] = (int) $v;
            }
        }
        $cleanSpyCd = [];
        foreach ($spyCooldowns as $k => $v) {
            if ((int) $v >= $threshold) {
                $cleanSpyCd[(int) $k] = (int) $v;
            }
        }
        $cleanPending = [];
        foreach ($pendingSpies as $k => $v) {
            $sentAt = (int) ($v['sent_at'] ?? 0);
            if ($sentAt >= $threshold) {
                $cleanPending[(int) $k] = $v;
            }
        }
        $cleanIntel = [];
        $intelTtl = defined('BOT_INTEL_TTL_SECONDS') ? (int) BOT_INTEL_TTL_SECONDS : 1800;
        foreach ($intel as $k => $v) {
            $capturedAt = (int) ($v['captured_at'] ?? 0);
            // Keep intel up to 2x its TTL so it stays as historical signal
            // even after expiry (purely informational).
            if (($now - $capturedAt) <= 2 * $intelTtl) {
                $cleanIntel[(int) $k] = $v;
            }
        }

        $intelMax = defined('BOT_INTEL_MAX_ENTRIES') ? (int) BOT_INTEL_MAX_ENTRIES : 50;
        $cleanIntel = botAttackTruncateIntelByRecency($cleanIntel, $intelMax);

        $quirks['attack_cooldowns'] = $cleanAttackCd;
        $quirks['spy_cooldowns'] = $cleanSpyCd;
        $quirks['pending_spies'] = $cleanPending;
        $quirks['intel'] = $cleanIntel;

        $state['bot_quirks'] = $quirks;

        // Fold per-loop metrics before flushing so a single UPDATE covers
        // cooldowns, pending spies, intel and metrics atomically.
        if (!empty($metricsDelta)) {
            botAttackMergeMetrics($state, $metricsDelta);
        }

        if (empty($state['__synthetic'])) {
            saveBotStateFields($db, $prefix, (int) $state['bot_user_id'], [
                'bot_quirks' => $state['bot_quirks'],
            ]);
        }
    }
}

if (!function_exists('botAttackIntelIsFresh')) {
    /**
     * Returns true if the intel snapshot is within its TTL.
     *
     * @param array<string, mixed> $intelEntry
     */
    function botAttackIntelIsFresh(array $intelEntry, ?int $now = null): bool
    {
        $now = $now ?? time();
        $capturedAt = (int) ($intelEntry['captured_at'] ?? 0);
        if ($capturedAt <= 0) {
            return false;
        }
        $ttl = defined('BOT_INTEL_TTL_SECONDS') ? (int) BOT_INTEL_TTL_SECONDS : 1800;

        return ($now - $capturedAt) < $ttl;
    }
}

if (!function_exists('botAttackHarvestIntel')) {
    /**
     * Walk pending_spies and, for spies whose expected_arrival has passed,
     * read the target's current ships/defenses/resources from DB and store
     * a fresh snapshot in intel. Removes the harvested entries from
     * pending_spies. Returns the new (intel, pending_spies, logs) triple.
     *
     * @param array<int, array<string, mixed>> $pendingSpies
     * @param array<int, array<string, mixed>> $intel
     * @return array{
     *   intel: array<int, array<string, mixed>>,
     *   pending_spies: array<int, array<string, mixed>>,
     *   logs: array<int, string>
     * }
     */
    function botAttackHarvestIntel(
        mysqli $db,
        string $prefix,
        array $pendingSpies,
        array $intel,
        ?int $now = null
    ): array {
        $now = $now ?? time();
        $logs = [];

        if (empty($pendingSpies)) {
            return ['intel' => $intel, 'pending_spies' => $pendingSpies, 'logs' => $logs];
        }

        $stillPending = [];
        foreach ($pendingSpies as $fleetId => $entry) {
            $arrival = (int) ($entry['expected_arrival'] ?? 0);
            $targetPlanetId = (int) ($entry['target_planet_id'] ?? 0);
            if ($arrival > $now || $targetPlanetId <= 0) {
                $stillPending[(int) $fleetId] = $entry;

                continue;
            }

            $snapshot = botAttackReadTargetSnapshot($db, $prefix, $targetPlanetId);
            if ($snapshot === null) {
                // Target gone? Drop the pending entry without intel.
                continue;
            }

            $intel[$targetPlanetId] = [
                'captured_at' => $now,
                'sent_at' => (int) ($entry['sent_at'] ?? 0),
                'snapshot' => $snapshot,
            ];

            $coords = sprintf(
                '%d:%d:%d',
                (int) ($snapshot['galaxy'] ?? 0),
                (int) ($snapshot['system'] ?? 0),
                (int) ($snapshot['planet'] ?? 0)
            );
            $logs[] = sprintf(
                'attack: intel %s ships=%d defs=%d res=%d',
                $coords,
                (int) array_sum($snapshot['ships'] ?? []),
                (int) array_sum($snapshot['defenses'] ?? []),
                (int) (($snapshot['resources']['metal'] ?? 0)
                    + ($snapshot['resources']['crystal'] ?? 0)
                    + ($snapshot['resources']['deuterium'] ?? 0))
            );
        }

        return ['intel' => $intel, 'pending_spies' => $stillPending, 'logs' => $logs];
    }
}

if (!function_exists('botAttackAppendRaidOutcomeLog')) {
    /**
     * Anexa un resultado real de raid (tras volver la flota) para contexto LLM
     * y análisis. FIFO acotado en bot_quirks.raid_outcome_log.
     *
     * @param array<string, mixed> $quirks
     * @param array<string, int|string> $entry ended_at, target_user_id, target_planet_id,
     *        loot_real, ship_losses_value, outcome (completed|no_data|unknown)
     */
    function botAttackAppendRaidOutcomeLog(array &$quirks, array $entry): void
    {
        $max = defined('BOT_ATTACK_RAID_LOG_MAX') ? (int) BOT_ATTACK_RAID_LOG_MAX : 40;
        $max = max(10, min(120, $max));
        $log = is_array($quirks['raid_outcome_log'] ?? null) ? $quirks['raid_outcome_log'] : [];
        $log[] = $entry;
        if (count($log) > $max) {
            $log = array_slice($log, -$max);
        }
        $quirks['raid_outcome_log'] = $log;
    }
}

if (!function_exists('botAttackHarvestReturns')) {
    /**
     * Observe the lifecycle of attack fleets the bot has launched so we can
     * measure REAL loot and REAL losses (not just our pre-launch estimate).
     *
     * The game engine processes our attack fleets in two phases:
     *   - Phase 1 (arrival): runs combat, fills in `fleet_resource_*` with
     *     the actual plundered amounts, replaces `fleet_array` with the
     *     surviving ship mix, and sets `fleet_mess=1` (returning).
     *   - Phase 2 (return): credits resources + surviving ships back to the
     *     source planet and DELETEs the fleet row.
     *
     * `bot_quirks.attacks_in_flight[fleet_id]` keeps a small record per
     * launched fleet with: launched_at, expected_arrival, expected_return,
     * ship_mix (as launched), loot_estimated, ship_value_launched, status.
     *
     * This helper transitions each entry:
     *   - Row present + fleet_mess=1 + status=outbound -> mark returning,
     *     capture loot_real and ship_mix_returning from the engine state.
     *   - Row gone + status=returning -> engine completed the cycle.
     *     Accumulate metrics:
     *       attacks_completed     += 1
     *       total_loot_returned   += loot_real
     *       real_ships_lost_value += value(launched) - value(returning)
     *     Drop the entry.
     *   - Row gone + status=outbound -> we missed the fleet_mess=1 window
     *     (bot polled too slow). Increment attacks_completed_no_data and
     *     drop. Conservative: we don't guess loot/losses.
     *   - Row present + status=returning -> still en route home, leave it.
     *
     * Paranoid TTL: any entry past expected_return + 1h is dropped and
     * counted as no_data (covers fleet rows manually deleted, engine bugs,
     * or stale state from previous bot versions).
     *
     * Mutates `$state['bot_quirks']['attacks_in_flight']` and persists the
     * full bot_quirks blob via saveBotStateFields. Metrics are merged via
     * botAttackMergeMetrics so they appear in bot_quirks.metrics.
     *
     * @param array<string, mixed> $state
     * @param array<int, array<string, int|float>> $pricelist
     * @return array<int, string>
     */
    function botAttackHarvestReturns(
        mysqli $db,
        string $prefix,
        array &$state,
        array $pricelist,
        ?int $now = null,
        int $attackerAllyId = 0
    ): array {
        $logs = [];
        $now = $now ?? time();

        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
        $inFlight = is_array($quirks['attacks_in_flight'] ?? null) ? $quirks['attacks_in_flight'] : [];
        if (empty($inFlight)) {
            return $logs;
        }

        $ids = [];
        foreach (array_keys($inFlight) as $fid) {
            $fid = (int) $fid;
            if ($fid > 0) {
                $ids[] = $fid;
            }
        }
        if (empty($ids)) {
            $quirks['attacks_in_flight'] = [];
            $state['bot_quirks'] = $quirks;

            return $logs;
        }

        $idsClause = implode(',', $ids);
        $sql = "SELECT `fleet_id`, `fleet_mess`, `fleet_array`,
                       `fleet_resource_metal`, `fleet_resource_crystal`,
                       `fleet_resource_deuterium`, `fleet_end_time`,
                       `fleet_target_owner`
                FROM `{$prefix}fleets`
                WHERE `fleet_id` IN ({$idsClause})";
        $byId = [];
        $result = $db->query($sql);
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $byId[(int) $row['fleet_id']] = $row;
            }
            $result->free();
        }

        $stillInFlight = [];
        $metricsDelta = [];

        foreach ($inFlight as $fleetId => $entry) {
            $fleetId = (int) $fleetId;
            $status = (string) ($entry['status'] ?? 'outbound');
            $row = $byId[$fleetId] ?? null;

            if ($row !== null) {
                $fleetMess = (int) ($row['fleet_mess'] ?? 0);
                if ($fleetMess === 1 && $status === 'outbound') {
                    $lootReal = (int) round(
                        (float) ($row['fleet_resource_metal'] ?? 0)
                        + (float) ($row['fleet_resource_crystal'] ?? 0)
                        + (float) ($row['fleet_resource_deuterium'] ?? 0)
                    );
                    $shipsBack = @unserialize((string) ($row['fleet_array'] ?? 'a:0:{}'));
                    if (!is_array($shipsBack)) {
                        $shipsBack = [];
                    }
                    $entry['status'] = 'returning';
                    $entry['loot_real'] = $lootReal;
                    $entry['ship_mix_returning'] = $shipsBack;
                    $entry['target_user_id'] = (int) ($row['fleet_target_owner'] ?? 0);
                    $logs[] = sprintf(
                        'attack: returning fleet=%d loot=%d',
                        $fleetId,
                        $lootReal
                    );
                }
                $stillInFlight[$fleetId] = $entry;

                continue;
            }

            // Row gone: engine finished phase 2.
            if ($status === 'returning' && isset($entry['loot_real'])) {
                $shipsBack = is_array($entry['ship_mix_returning'] ?? null)
                    ? $entry['ship_mix_returning']
                    : [];
                $shipValueReturned = botCombatLossesValue($shipsBack, $pricelist);
                $shipValueLaunched = (int) ($entry['ship_value_launched'] ?? 0);
                $losses = max(0, $shipValueLaunched - $shipValueReturned);
                $metricsDelta['attacks_completed']
                    = ($metricsDelta['attacks_completed'] ?? 0) + 1;
                $metricsDelta['total_loot_returned']
                    = ($metricsDelta['total_loot_returned'] ?? 0) + (int) $entry['loot_real'];
                $metricsDelta['real_ships_lost_value']
                    = ($metricsDelta['real_ships_lost_value'] ?? 0) + $losses;
                $logs[] = sprintf(
                    'attack: completed fleet=%d loot=%d losses=%d',
                    $fleetId,
                    (int) $entry['loot_real'],
                    $losses
                );

                $tpidDone = (int) ($entry['target_planet_id'] ?? 0);
                $tuidDone = (int) ($entry['target_user_id'] ?? 0);
                if ($tuidDone <= 0 && $tpidDone > 0) {
                    $pRow = $db->query(
                        "SELECT `planet_user_id` FROM `{$prefix}planets`
                         WHERE `planet_id` = {$tpidDone} LIMIT 1"
                    );
                    if ($pRow && ($pr = $pRow->fetch_assoc())) {
                        $tuidDone = (int) ($pr['planet_user_id'] ?? 0);
                    }
                    if ($pRow) {
                        $pRow->free();
                    }
                }
                $netRaid = (int) $entry['loot_real'] - $losses;
                $outcomeTag = $losses <= 0 ? 'clean'
                    : ($netRaid >= (int) $entry['loot_real'] / 2 ? 'profitable_with_losses' : 'costly');
                botAttackAppendRaidOutcomeLog($quirks, [
                    'ended_at' => $now,
                    'target_user_id' => $tuidDone,
                    'target_planet_id' => $tpidDone,
                    'loot_real' => (int) $entry['loot_real'],
                    'ship_losses_value' => $losses,
                    'net_resource_gain_vs_ship_loss' => $netRaid,
                    'outcome' => $outcomeTag,
                ]);

                // Diplomacy bookkeeping: every completed attack adds
                // pressure on the victim's alliance and, if a war is
                // active, accumulates damage_*_to_* used by the bribe
                // threshold. Self-attacks and stateless targets are
                // skipped inside the helpers.
                $targetUserId = (int) ($entry['target_user_id'] ?? 0);
                if (function_exists('botDiplomacyRecordAttack')
                    && $attackerAllyId > 0
                    && $targetUserId > 0
                ) {
                    $resAlly = $db->query(
                        "SELECT `user_ally_id` FROM `{$prefix}users`
                         WHERE `user_id` = {$targetUserId} LIMIT 1"
                    );
                    $victimAlly = 0;
                    if ($resAlly instanceof \mysqli_result) {
                        $rowAlly = $resAlly->fetch_assoc();
                        $resAlly->free();
                        $victimAlly = (int) ($rowAlly['user_ally_id'] ?? 0);
                    }
                    if ($victimAlly > 0 && $victimAlly !== $attackerAllyId) {
                        $pressureUnit = (int) $entry['loot_real'] + $losses;
                        botDiplomacyRecordAttack(
                            $db,
                            $prefix,
                            $attackerAllyId,
                            $victimAlly,
                            $pressureUnit,
                            $now
                        );
                        $metricsDelta['diplo_pressure_recorded']
                            = ($metricsDelta['diplo_pressure_recorded'] ?? 0) + 1;
                        if (function_exists('botDiplomacyAddDamage')) {
                            if ((int) $entry['loot_real'] > 0) {
                                botDiplomacyAddDamage(
                                    $db,
                                    $prefix,
                                    $attackerAllyId,
                                    $victimAlly,
                                    (int) $entry['loot_real'],
                                    $now
                                );
                            }
                            if ($losses > 0) {
                                botDiplomacyAddDamage(
                                    $db,
                                    $prefix,
                                    $victimAlly,
                                    $attackerAllyId,
                                    $losses,
                                    $now
                                );
                            }
                        }
                        if (function_exists('botDiplomacyHandleNapBreach')) {
                            botDiplomacyHandleNapBreach(
                                $db,
                                $prefix,
                                $attackerAllyId,
                                $victimAlly,
                                $now
                            );
                        }
                    }
                }
            } else {
                $metricsDelta['attacks_completed_no_data']
                    = ($metricsDelta['attacks_completed_no_data'] ?? 0) + 1;
                $logs[] = sprintf(
                    'attack: completed fleet=%d (no_data status=%s)',
                    $fleetId,
                    $status
                );
                $tpNd = (int) ($entry['target_planet_id'] ?? 0);
                $tuNd = (int) ($entry['target_user_id'] ?? 0);
                if ($tuNd <= 0 && $tpNd > 0) {
                    $pRow2 = $db->query(
                        "SELECT `planet_user_id` FROM `{$prefix}planets`
                         WHERE `planet_id` = {$tpNd} LIMIT 1"
                    );
                    if ($pRow2 && ($pr2 = $pRow2->fetch_assoc())) {
                        $tuNd = (int) ($pr2['planet_user_id'] ?? 0);
                    }
                    if ($pRow2) {
                        $pRow2->free();
                    }
                }
                botAttackAppendRaidOutcomeLog($quirks, [
                    'ended_at' => $now,
                    'target_user_id' => $tuNd,
                    'target_planet_id' => $tpNd,
                    'loot_real' => 0,
                    'ship_losses_value' => 0,
                    'net_resource_gain_vs_ship_loss' => 0,
                    'outcome' => 'no_data',
                ]);
            }
        }

        // Paranoid TTL.
        foreach ($stillInFlight as $fleetId => $entry) {
            $expectedReturn = (int) ($entry['expected_return'] ?? 0);
            if ($expectedReturn > 0 && $now > ($expectedReturn + 3600)) {
                unset($stillInFlight[$fleetId]);
                $metricsDelta['attacks_completed_no_data']
                    = ($metricsDelta['attacks_completed_no_data'] ?? 0) + 1;
                $logs[] = sprintf(
                    'attack: drop fleet=%d (stale_in_flight)',
                    $fleetId
                );
            }
        }

        $quirks['attacks_in_flight'] = $stillInFlight;
        $state['bot_quirks'] = $quirks;

        if (!empty($metricsDelta)) {
            botAttackMergeMetrics($state, $metricsDelta);
        }

        return $logs;
    }
}

if (!function_exists('botAttackHandlePendingPlans')) {
    /**
     * Two-phase attack pipeline: when a candidate passes fresh-intel +
     * sim + ratio checks, the bot does NOT launch the attack right away.
     * Instead it ARMS a plan: it sends a recheck spy probe and stores
     * the full decision in `bot_quirks.pending_attacks[target_planet_id]`.
     * This helper handles the second phase — when the recheck probe has
     * arrived, it reads a fresh snapshot, re-runs sim + ratio + loot
     * checks, and either CONFIRMS (launches) or ABORTS the plan.
     *
     * Why this exists: intel collected at t0 is BOT_INTEL_TTL_SECONDS old
     * by the time the bot decides to attack. A human can build defenses
     * or fleetsave in that window and our pre-launch estimate would be
     * wrong. The pre-attack recheck makes the bot's combat decisions
     * react to last-second changes at the cost of one extra probe.
     *
     * Plan layout (stored under bot_quirks.pending_attacks[target_pid]):
     *   armed_at:             unix ts when the plan was created
     *   recheck_fleet_id:     fleet_id of the recheck spy probe
     *   recheck_arrival:      unix ts when the spy lands
     *   source_planet_id:     attacker's source planet
     *   ship_mix:             [shipId => count] reserved for the raid
     *   ratio_threshold:      X used at arm time (frozen for consistency)
     *   min_loot:             min loot used at arm time
     *   estimated_loot:       loot estimated at arm time
     *   estimated_losses:     attacker_losses_value estimated at arm time
     *   target_planet_id:     target_planet_id (also the map key)
     *
     * Decision tree per plan whose recheck has arrived:
     *   - Target gone           -> ABORT (attacks_aborted_no_target)
     *   - Source gone / no ships -> ABORT (attacks_aborted_no_source)
     *   - Fresh sim defender wins/draw -> ABORT (attacks_aborted_sim_lose)
     *   - Fresh loot < min      -> ABORT (attacks_aborted_low_loot)
     *   - Fresh ratio < threshold -> ABORT (attacks_aborted_low_ratio)
     *   - Otherwise             -> CONFIRM via botAttackInsertAttack
     *
     * On any path (abort or confirm) the fresh snapshot is written back
     * to bot_quirks.intel[target_pid] so the next decision loop has
     * up-to-date data. Aborted plans free their reserved ships back into
     * $shipsByPlanet so the same loop can reuse them for a different
     * plan or attack.
     *
     * Paranoid TTL: any plan older than BOT_ATTACK_PLAN_TTL_SECONDS is
     * dropped as stale_plan (its reserved ships are also released).
     *
     * @param array<int, array<string, mixed>> $planets
     * @param array<int, array<int, int>> $shipsByPlanet
     * @param array<int, int> $attackCooldowns
     * @param array<string, mixed> $researchRow
     * @param array<int, array<string, int|float>> $pricelist
     * @param array<string, mixed> $profile perfil bot (LLM recheck)
     * @param string $profileName nombre del perfil en JSON
     * @return array{
     *   logs: array<int, string>,
     *   metrics_delta: array<string, int>,
     *   attacks_confirmed: int
     * }
     */
    function botAttackHandlePendingPlans(
        mysqli $db,
        string $prefix,
        array &$state,
        array $user,
        array $planets,
        array &$shipsByPlanet,
        array &$attackCooldowns,
        array $researchRow,
        array $pricelist,
        float $universeSpeed,
        int $now,
        int $maxAttacks,
        int $attacksDoneSoFar,
        array $profile = [],
        string $profileName = 'default'
    ): array {
        $out = ['logs' => [], 'metrics_delta' => [], 'attacks_confirmed' => 0];

        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
        $pendingAttacks = is_array($quirks['pending_attacks'] ?? null)
            ? $quirks['pending_attacks']
            : [];
        if (empty($pendingAttacks)) {
            return $out;
        }

        $intel = is_array($quirks['intel'] ?? null) ? $quirks['intel'] : [];
        $planTtl = defined('BOT_ATTACK_PLAN_TTL_SECONDS') ? (int) BOT_ATTACK_PLAN_TTL_SECONDS : 6 * 3600;

        $releaseShips = static function (array $shipMix, int $sourcePid) use (&$shipsByPlanet): void {
            if ($sourcePid <= 0 || !isset($shipsByPlanet[$sourcePid])) {
                return;
            }
            foreach ($shipMix as $shipId => $cnt) {
                $shipsByPlanet[$sourcePid][(int) $shipId]
                    = (int) ($shipsByPlanet[$sourcePid][(int) $shipId] ?? 0) + (int) $cnt;
            }
        };

        $remainingPlans = [];

        foreach ($pendingAttacks as $targetPid => $plan) {
            $targetPid = (int) $targetPid;
            $armedAt = (int) ($plan['armed_at'] ?? 0);
            $recheckArrival = (int) ($plan['recheck_arrival'] ?? 0);
            $shipMix = is_array($plan['ship_mix'] ?? null) ? $plan['ship_mix'] : [];
            $sourcePid = (int) ($plan['source_planet_id'] ?? 0);
            $ratioThreshold = (float) ($plan['ratio_threshold'] ?? 0.0);
            $minLoot = (int) ($plan['min_loot'] ?? 0);

            if ($armedAt > 0 && ($armedAt + $planTtl) < $now) {
                $releaseShips($shipMix, $sourcePid);
                $out['logs'][] = sprintf(
                    'attack: aborted target=%d (stale_plan)',
                    $targetPid
                );
                $out['metrics_delta']['attacks_aborted_stale_plan']
                    = ($out['metrics_delta']['attacks_aborted_stale_plan'] ?? 0) + 1;

                continue;
            }

            if ($recheckArrival > $now) {
                $remainingPlans[$targetPid] = $plan;

                continue;
            }

            if (($attacksDoneSoFar + $out['attacks_confirmed']) >= $maxAttacks) {
                // Throttle: keep the plan, will be processed next loop.
                $remainingPlans[$targetPid] = $plan;

                continue;
            }

            // Fresh snapshot of the target.
            $snapshot = botAttackReadTargetSnapshot($db, $prefix, $targetPid);
            if ($snapshot === null) {
                $releaseShips($shipMix, $sourcePid);
                $out['logs'][] = sprintf(
                    'attack: aborted target=%d (no_target)',
                    $targetPid
                );
                $out['metrics_delta']['attacks_aborted_no_target']
                    = ($out['metrics_delta']['attacks_aborted_no_target'] ?? 0) + 1;

                continue;
            }

            $targetCoords = sprintf(
                '%d:%d:%d',
                (int) $snapshot['galaxy'],
                (int) $snapshot['system'],
                (int) $snapshot['planet']
            );

            // Refresh intel with the new snapshot regardless of outcome:
            // even an aborted target gives us valuable data for next round.
            $intel[$targetPid] = [
                'snapshot' => $snapshot,
                'captured_at' => $now,
                'sent_at' => $armedAt,
            ];

            // Find the source planet row again.
            $sourcePlanet = null;
            foreach ($planets as $p) {
                if ((int) $p['planet_id'] === $sourcePid) {
                    $sourcePlanet = $p;

                    break;
                }
            }
            if ($sourcePlanet === null) {
                $releaseShips($shipMix, $sourcePid);
                $out['logs'][] = sprintf(
                    'attack: aborted %s (no_source)',
                    $targetCoords
                );
                $out['metrics_delta']['attacks_aborted_no_source']
                    = ($out['metrics_delta']['attacks_aborted_no_source'] ?? 0) + 1;

                continue;
            }

            // Compute cargo capacity of the planned mix.
            $cargoCapacity = 0;
            foreach ($shipMix as $shipId => $cnt) {
                $cargoCapacity += botCargoCapacity((int) $shipId, $researchRow) * (int) $cnt;
            }

            $intelLoot = botAttackEstimateLoot(['snapshot' => $snapshot], $cargoCapacity);
            $freshLoot = (int) $intelLoot['loot'];
            if ($freshLoot < $minLoot) {
                $releaseShips($shipMix, $sourcePid);
                $out['logs'][] = sprintf(
                    'attack: aborted %s (low_loot=%d < min=%d)',
                    $targetCoords,
                    $freshLoot,
                    $minLoot
                );
                $out['metrics_delta']['attacks_aborted_low_loot']
                    = ($out['metrics_delta']['attacks_aborted_low_loot'] ?? 0) + 1;

                continue;
            }

            $sim = botCombatSimulate(
                $shipMix,
                $snapshot['ships'] ?? [],
                $snapshot['defenses'] ?? [],
                $researchRow,
                $researchRow,
                $pricelist
            );
            if (($sim['winner'] ?? '') === 'defender' || ($sim['winner'] ?? '') === 'draw') {
                $releaseShips($shipMix, $sourcePid);
                $out['logs'][] = sprintf(
                    'attack: aborted %s (sim_lose my_losses=%d)',
                    $targetCoords,
                    (int) ($sim['attacker_losses_value'] ?? 0)
                );
                $out['metrics_delta']['attacks_aborted_sim_lose']
                    = ($out['metrics_delta']['attacks_aborted_sim_lose'] ?? 0) + 1;

                continue;
            }

            // Fuel.
            $sourceCoords = [
                'galaxy' => (int) $sourcePlanet['planet_galaxy'],
                'system' => (int) $sourcePlanet['planet_system'],
                'planet' => (int) $sourcePlanet['planet_planet'],
            ];
            $targetCoordsArr = [
                'galaxy' => (int) $snapshot['galaxy'],
                'system' => (int) $snapshot['system'],
                'planet' => (int) $snapshot['planet'],
            ];
            $estimate = botTravelEstimate(
                $sourceCoords,
                $targetCoordsArr,
                $shipMix,
                $researchRow,
                $universeSpeed
            );
            $fuel = max(0, (int) ($estimate['consumption'] ?? 0));
            $netLoot = $freshLoot - $fuel;
            $myLosses = max(1, (int) ($sim['attacker_losses_value'] ?? 1));
            $ratio = $netLoot / $myLosses;
            if ($ratio < $ratioThreshold) {
                $releaseShips($shipMix, $sourcePid);
                $out['logs'][] = sprintf(
                    'attack: aborted %s (ratio=%.2f < %.2f)',
                    $targetCoords,
                    $ratio,
                    $ratioThreshold
                );
                $out['metrics_delta']['attacks_aborted_low_ratio']
                    = ($out['metrics_delta']['attacks_aborted_low_ratio'] ?? 0) + 1;

                continue;
            }

            $recheckOk = !empty($plan['llm_recheck_ok']);
            if ($recheckOk) {
                unset($plan['llm_recheck_ok']);
            }

            $stakesRecheck = function_exists('botLlmAttackIsHighStakes')
                && botLlmAttackIsHighStakes((int) $netLoot, (float) $ratio);
            if ($stakesRecheck
                && function_exists('botLlmIsEnabled')
                && botLlmIsEnabled()
                && function_exists('botLlmJobTableExists')
                && botLlmJobTableExists($db, $prefix)
                && !$recheckOk) {
                $botUid = (int) ($user['user_id'] ?? 0);
                $botNm = (string) ($user['user_name'] ?? '');
                $pvRes = $db->query(
                    "SELECT p.`planet_user_id`, u.`user_name`
                     FROM `{$prefix}planets` AS p
                     INNER JOIN `{$prefix}users` AS u ON u.`user_id` = p.`planet_user_id`
                     WHERE p.`planet_id` = {$targetPid}
                     LIMIT 1"
                );
                $vicId = 0;
                $vicName = '';
                if ($pvRes && ($pvRow = $pvRes->fetch_assoc())) {
                    $vicId = (int) ($pvRow['planet_user_id'] ?? 0);
                    $vicName = (string) ($pvRow['user_name'] ?? '');
                }
                if ($pvRes) {
                    $pvRes->free();
                }
                $dedupeR = 'atk_v1:r:' . $targetPid . ':' . $armedAt;
                $lr = $plan['llm_recheck'] ?? null;
                if (!is_array($lr) || ($lr['status'] ?? '') !== 'queued') {
                    if (function_exists('botLlmAttackTryEnqueue')) {
                        $enq = botLlmAttackTryEnqueue(
                            $db,
                            $prefix,
                            $botUid,
                            $botNm,
                            $dedupeR,
                            $profile,
                            $profileName,
                            $now,
                            'recheck',
                            $targetPid,
                            $targetCoords,
                            $vicId,
                            $vicName,
                            (int) $netLoot,
                            (int) $myLosses,
                            (float) $ratio,
                            (float) $ratioThreshold,
                            $minLoot,
                            (string) ($sim['winner'] ?? ''),
                            (int) ($sim['rounds_used'] ?? 0),
                            $researchRow,
                            $planets,
                            is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : []
                        );
                        if ($enq === 'queued' || $enq === 'duplicate') {
                            $plan['llm_recheck'] = ['status' => 'queued', 'dedupe' => $dedupeR];
                            $remainingPlans[$targetPid] = $plan;
                            $out['logs'][] = sprintf(
                                'attack: recheck espera LLM %s (loot=%d ratio=%.2f)',
                                $targetCoords,
                                (int) $netLoot,
                                (float) $ratio
                            );
                            $out['metrics_delta']['attack_llm_recheck_queued']
                                = ($out['metrics_delta']['attack_llm_recheck_queued'] ?? 0) + 1;

                            continue;
                        }
                    }
                } elseif (($lr['status'] ?? '') === 'queued') {
                    $remainingPlans[$targetPid] = $plan;

                    continue;
                }
            }

            // Confirm: launch attack.
            $targetForInsert = [
                'planet_id' => $targetPid,
                'planet_galaxy' => (int) $snapshot['galaxy'],
                'planet_system' => (int) $snapshot['system'],
                'planet_planet' => (int) $snapshot['planet'],
                'planet_type' => (int) ($snapshot['planet_type'] ?? 1),
            ];
            $fleetId = botAttackInsertAttack(
                $db,
                $prefix,
                $user,
                $sourcePlanet,
                $targetForInsert,
                $shipMix,
                $researchRow,
                $universeSpeed
            );
            if ($fleetId === null) {
                $releaseShips($shipMix, $sourcePid);
                $out['logs'][] = sprintf(
                    'attack: aborted %s (insert_fail)',
                    $targetCoords
                );
                $out['metrics_delta']['attacks_insert_fail']
                    = ($out['metrics_delta']['attacks_insert_fail'] ?? 0) + 1;

                continue;
            }

            // shipsByPlanet stayed already decremented (reserved at arm
            // time), so do NOT subtract again here — that would double
            // count. The DB INSERT inside botAttackInsertAttack consumed
            // the physical ships.
            $attackCooldowns[$sourcePid] = $now;
            $out['attacks_confirmed']++;
            $out['metrics_delta']['attacks_recheck_confirmed']
                = ($out['metrics_delta']['attacks_recheck_confirmed'] ?? 0) + 1;
            $out['metrics_delta']['attacks_launched']
                = ($out['metrics_delta']['attacks_launched'] ?? 0) + 1;
            $out['metrics_delta']['total_loot_launched']
                = ($out['metrics_delta']['total_loot_launched'] ?? 0) + max(0, (int) $netLoot);
            $out['metrics_delta']['total_my_losses_value']
                = ($out['metrics_delta']['total_my_losses_value'] ?? 0) + max(0, (int) $myLosses);
            $out['metrics_delta']['last_attack_at'] = $now;

            // Register in attacks_in_flight (mirrors the new section 9.6
            // bookkeeping done by the direct-launch path).
            $duration = (int) ($estimate['duration'] ?? 0);
            $quirksLocal = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
            $inFlight = is_array($quirksLocal['attacks_in_flight'] ?? null)
                ? $quirksLocal['attacks_in_flight']
                : [];
            $inFlight[(int) $fleetId] = [
                'launched_at' => $now,
                'expected_arrival' => $now + $duration,
                'expected_return' => $now + 2 * $duration,
                'ship_mix' => $shipMix,
                'loot_estimated' => (int) $netLoot,
                'ship_value_launched' => (int) botCombatLossesValue($shipMix, $pricelist),
                'status' => 'outbound',
                'target_planet_id' => $targetPid,
            ];
            $quirksLocal['attacks_in_flight'] = $inFlight;
            $state['bot_quirks'] = $quirksLocal;

            $out['logs'][] = sprintf(
                'attack: confirmed %s eta=%ds loot=%d losses=%d ratio=%.2f',
                $targetCoords,
                $duration,
                $netLoot,
                $myLosses,
                $ratio
            );
        }

        // Persist remaining plans and refreshed intel.
        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
        $quirks['pending_attacks'] = $remainingPlans;
        $quirks['intel'] = $intel;
        $state['bot_quirks'] = $quirks;

        return $out;
    }
}

if (!function_exists('botAttackReadTargetSnapshot')) {
    /**
     * Reads ships/defenses/resources for a target planet. Returns null if
     * the planet no longer exists. The snapshot mirrors the data a real
     * espionage report would give the bot if it could parse messages.
     *
     * @return array<string, mixed>|null
     */
    function botAttackReadTargetSnapshot(mysqli $db, string $prefix, int $targetPlanetId): ?array
    {
        if ($targetPlanetId <= 0) {
            return null;
        }
        $row = $db->query(
            "SELECT
                p.`planet_id`, p.`planet_galaxy`, p.`planet_system`, p.`planet_planet`,
                p.`planet_metal`, p.`planet_crystal`, p.`planet_deuterium`,
                s.*, d.*
             FROM `{$prefix}planets` AS p
             LEFT JOIN `{$prefix}ships` AS s ON s.`ship_planet_id` = p.`planet_id`
             LEFT JOIN `{$prefix}defenses` AS d ON d.`defense_planet_id` = p.`planet_id`
             WHERE p.`planet_id` = {$targetPlanetId}
             LIMIT 1"
        );
        if (!$row) {
            return null;
        }
        $data = $row->fetch_assoc();
        $row->free();
        if (!is_array($data)) {
            return null;
        }

        $shipColumns = [
            202 => 'ship_small_cargo_ship', 203 => 'ship_big_cargo_ship',
            204 => 'ship_light_fighter', 205 => 'ship_heavy_fighter',
            206 => 'ship_cruiser', 207 => 'ship_battleship',
            208 => 'ship_colony_ship', 209 => 'ship_recycler',
            210 => 'ship_espionage_probe', 211 => 'ship_bomber',
            212 => 'ship_solar_satellite', 213 => 'ship_destroyer',
            214 => 'ship_deathstar', 215 => 'ship_battlecruiser',
            216 => 'ship_mining_drill',
        ];
        $defenseColumns = [
            401 => 'defense_rocket_launcher', 402 => 'defense_light_laser',
            403 => 'defense_heavy_laser', 404 => 'defense_gauss_cannon',
            405 => 'defense_ion_cannon', 406 => 'defense_plasma_turret',
            407 => 'defense_small_shield_dome', 408 => 'defense_large_shield_dome',
            502 => 'defense_anti-ballistic_missile', 503 => 'defense_interplanetary_missile',
        ];

        $ships = [];
        foreach ($shipColumns as $id => $col) {
            $count = (int) ($data[$col] ?? 0);
            if ($count > 0) {
                $ships[$id] = $count;
            }
        }
        $defenses = [];
        foreach ($defenseColumns as $id => $col) {
            $count = (int) ($data[$col] ?? 0);
            if ($count > 0) {
                $defenses[$id] = $count;
            }
        }

        return [
            'planet_id' => (int) $data['planet_id'],
            'galaxy' => (int) $data['planet_galaxy'],
            'system' => (int) $data['planet_system'],
            'planet' => (int) $data['planet_planet'],
            'resources' => [
                'metal' => (int) ($data['planet_metal'] ?? 0),
                'crystal' => (int) ($data['planet_crystal'] ?? 0),
                'deuterium' => (int) ($data['planet_deuterium'] ?? 0),
            ],
            'ships' => $ships,
            'defenses' => $defenses,
        ];
    }
}

if (!function_exists('botAttackReorderEnemiesFirst')) {
    /**
     * Stable partition: planets owned by users in $enemyUserIds come
     * first (in their original order), every other planet keeps its
     * relative order. Used to bias attack scanning toward alliances
     * the bot is at war with.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    function botAttackReorderEnemiesFirst(array $candidates, array $enemyUserIds): array
    {
        if ($candidates === [] || $enemyUserIds === []) {
            return $candidates;
        }
        $enemySet = array_flip(array_map('intval', $enemyUserIds));
        $front = [];
        $back = [];
        foreach ($candidates as $c) {
            $uid = (int) ($c['planet_user_id'] ?? 0);
            if (isset($enemySet[$uid])) {
                $front[] = $c;
            } else {
                $back[] = $c;
            }
        }

        return array_merge($front, $back);
    }
}

if (!function_exists('botAttackReorderRevengeFirst')) {
    /**
     * Pull planets owned by $attackerUserId to the front of the list,
     * preserving relative order for everything else.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    function botAttackReorderRevengeFirst(array $candidates, int $attackerUserId): array
    {
        if ($attackerUserId <= 0) {
            return $candidates;
        }
        $revenge = [];
        $rest = [];
        foreach ($candidates as $row) {
            if ((int) ($row['planet_user_id'] ?? 0) === $attackerUserId) {
                $revenge[] = $row;
            } else {
                $rest[] = $row;
            }
        }
        if (empty($revenge)) {
            return $candidates;
        }

        return array_merge($revenge, $rest);
    }
}

if (!function_exists('botAttackFleetSlotsInfo')) {
    /**
     * Reports the bot's current fleet-slot budget. Mirrors what the game
     * applies to humans (FleetsLib::getMaxFleets vs row count in xgp_fleets
     * for the user). Used to bail out before launching new spies or attacks
     * when the bot would otherwise be rejected by the engine.
     *
     * Note: returned 'max' is the same `1 + computer_tech + AMIRAL_BONUS`
     * formula the game uses, but the AMIRAL bonus is 0 for bots (they
     * cannot buy premium officers). 'used' counts every row owned by the
     * user, including mission=15 (mess) which still occupies a slot until
     * the engine deletes it on return.
     *
     * @param array<string, mixed> $researchRow
     * @return array{used:int,max:int,free:int}
     */
    function botAttackFleetSlotsInfo(
        mysqli $db,
        string $prefix,
        int $userId,
        array $researchRow
    ): array {
        $computerTech = (int) ($researchRow['research_computer_technology'] ?? 0);
        $max = 1 + $computerTech;

        $used = 0;
        $sql = "SELECT COUNT(*) AS c FROM `{$prefix}fleets` WHERE `fleet_owner` = " . $userId;
        $result = $db->query($sql);
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $used = (int) ($row['c'] ?? 0);
            $result->free();
        }

        return [
            'used' => $used,
            'max' => $max,
            'free' => max(0, $max - $used),
        ];
    }
}

if (!function_exists('botAttackScanCandidates')) {
    /**
     * Scans for inhabited planets that are valid targets, applying every
     * filter inside the SQL itself (so reserved/banned/vacation/admin
     * planets never reach PHP). Returns the result as an array of rows.
     *
     * @return array<int, array<string, mixed>>
     */
    function botAttackScanCandidates(
        mysqli $db,
        string $prefix,
        int $botUserId,
        ?int $allyId = null,
        int $limit = 100
    ): array {
        $reservedSystems = botAttackReservedSystems();
        $reservedClause = '';
        if (!empty($reservedSystems)) {
            $list = implode(',', array_map('intval', $reservedSystems));
            $reservedClause = "AND p.`planet_system` NOT IN ({$list})";
        }

        $allyClause = '';
        if ($allyId !== null && $allyId > 0) {
            $allyClause = "AND (u.`user_ally_id` = 0 OR u.`user_ally_id` <> {$allyId})";
        }

        $sql = "
            SELECT
                p.`planet_id`, p.`planet_user_id`, p.`planet_name`,
                p.`planet_galaxy`, p.`planet_system`, p.`planet_planet`,
                p.`planet_type`,
                u.`user_id`, u.`user_name`, u.`user_ally_id`
            FROM `{$prefix}planets` AS p
            INNER JOIN `{$prefix}users` AS u ON u.`user_id` = p.`planet_user_id`
            LEFT JOIN `{$prefix}preferences` AS pr ON pr.`preference_user_id` = u.`user_id`
            WHERE p.`planet_type` = 1
              AND u.`user_id` <> {$botUserId}
              AND COALESCE(u.`user_banned`, 0) = 0
              AND COALESCE(pr.`preference_vacation_mode`, 0) = 0
              {$reservedClause}
              {$allyClause}
            ORDER BY p.`planet_id` ASC
            LIMIT " . (int) $limit;

        $result = $db->query($sql);
        if (!$result) {
            return [];
        }
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
        $result->free();

        return $rows;
    }
}

if (!function_exists('botAttackPickSourcePlanetForSpy')) {
    /**
     * Picks the source planet that should launch the spy mission against a
     * target. Criteria: shortest distance, has at least
     * BOT_SPY_PROBES_PER_MISSION probes, has spare deuterium for fuel.
     *
     * @param array<int, array<string, mixed>> $planets
     * @param array<int, array<string, int>> $shipsByPlanet
     * @return array<string, mixed>|null
     */
    function botAttackPickSourcePlanetForSpy(
        array $planets,
        array $shipsByPlanet,
        array $target,
        array $spyCooldowns,
        int $now,
        int $cooldownSeconds
    ): ?array {
        $needProbes = defined('BOT_SPY_PROBES_PER_MISSION') ? (int) BOT_SPY_PROBES_PER_MISSION : 4;
        $best = null;
        $bestDist = PHP_INT_MAX;
        foreach ($planets as $planet) {
            $pid = (int) $planet['planet_id'];
            $ships = $shipsByPlanet[$pid] ?? [];
            $probes = (int) ($ships[210] ?? 0);
            if ($probes < $needProbes) {
                continue;
            }
            $deut = (float) ($planet['planet_deuterium'] ?? 0);
            // Espionage probes consume ~1 deuterium each at top speed; we
            // want a generous buffer.
            if ($deut < $needProbes * 5 + 100) {
                continue;
            }
            $targetPid = (int) ($target['planet_id'] ?? 0);
            if (isset($spyCooldowns[$targetPid]) && ($now - $spyCooldowns[$targetPid]) < $cooldownSeconds) {
                continue;
            }
            $distance = botTravelDistance(
                [
                    'galaxy' => (int) $planet['planet_galaxy'],
                    'system' => (int) $planet['planet_system'],
                    'planet' => (int) $planet['planet_planet'],
                ],
                [
                    'galaxy' => (int) $target['planet_galaxy'],
                    'system' => (int) $target['planet_system'],
                    'planet' => (int) $target['planet_planet'],
                ]
            );
            if ($distance < $bestDist) {
                $bestDist = $distance;
                $best = $planet;
            }
        }

        return $best;
    }
}

if (!function_exists('botAttackPickSourcePlanetForAttack')) {
    /**
     * Picks the source planet that should launch the actual attack. Same
     * idea as botAttackPickSourcePlanetForSpy but the requirements differ
     * (must have at least 1 small cargo + 1 combat ship, must not be on
     * its own attack cooldown).
     *
     * @return array{planet:array<string,mixed>, ships:array<int,int>}|null
     */
    function botAttackPickSourcePlanetForAttack(
        array $planets,
        array $shipsByPlanet,
        array $target,
        array $attackCooldowns,
        int $now,
        int $cooldownSeconds
    ): ?array {
        $best = null;
        $bestShips = null;
        $bestDist = PHP_INT_MAX;
        foreach ($planets as $planet) {
            $pid = (int) $planet['planet_id'];
            if (isset($attackCooldowns[$pid]) && ($now - $attackCooldowns[$pid]) < $cooldownSeconds) {
                continue;
            }
            $ships = $shipsByPlanet[$pid] ?? [];
            $hasCargo = (int) ($ships[202] ?? 0) > 0 || (int) ($ships[203] ?? 0) > 0;
            $combatIds = [204, 205, 206, 207, 211, 213, 215];
            $hasCombat = false;
            foreach ($combatIds as $cid) {
                if ((int) ($ships[$cid] ?? 0) > 0) {
                    $hasCombat = true;

                    break;
                }
            }
            if (!$hasCargo || !$hasCombat) {
                continue;
            }
            $distance = botTravelDistance(
                [
                    'galaxy' => (int) $planet['planet_galaxy'],
                    'system' => (int) $planet['planet_system'],
                    'planet' => (int) $planet['planet_planet'],
                ],
                [
                    'galaxy' => (int) $target['planet_galaxy'],
                    'system' => (int) $target['planet_system'],
                    'planet' => (int) $target['planet_planet'],
                ]
            );
            if ($distance < $bestDist) {
                $bestDist = $distance;
                $best = $planet;
                $bestShips = $ships;
            }
        }
        if ($best === null) {
            return null;
        }

        return ['planet' => $best, 'ships' => $bestShips ?? []];
    }
}

if (!function_exists('botAttackShipsByPlanet')) {
    /**
     * Reads the full ships table for the bot's planets and returns a map
     * planetId => [shipId => count]. Loaded once per loop and reused for
     * spy and attack source picks.
     *
     * @param array<int, int> $planetIds
     * @return array<int, array<int, int>>
     */
    function botAttackShipsByPlanet(mysqli $db, string $prefix, array $planetIds): array
    {
        if (empty($planetIds)) {
            return [];
        }
        $idList = implode(',', array_map('intval', $planetIds));
        $result = $db->query(
            "SELECT * FROM `{$prefix}ships` WHERE `ship_planet_id` IN ({$idList})"
        );
        if (!$result) {
            return [];
        }
        $shipCols = [
            202 => 'ship_small_cargo_ship', 203 => 'ship_big_cargo_ship',
            204 => 'ship_light_fighter', 205 => 'ship_heavy_fighter',
            206 => 'ship_cruiser', 207 => 'ship_battleship',
            208 => 'ship_colony_ship', 209 => 'ship_recycler',
            210 => 'ship_espionage_probe', 211 => 'ship_bomber',
            212 => 'ship_solar_satellite', 213 => 'ship_destroyer',
            214 => 'ship_deathstar', 215 => 'ship_battlecruiser',
            216 => 'ship_mining_drill',
        ];
        $out = [];
        while ($row = $result->fetch_assoc()) {
            $pid = (int) $row['ship_planet_id'];
            $byId = [];
            foreach ($shipCols as $id => $col) {
                $count = (int) ($row[$col] ?? 0);
                if ($count > 0) {
                    $byId[$id] = $count;
                }
            }
            $out[$pid] = $byId;
        }
        $result->free();

        return $out;
    }
}

if (!function_exists('botAttackInsertSpy')) {
    /**
     * INSERTs a spy fleet (mission=6) and decrements the source planet's
     * probes + deuterium. Returns the new fleet_id on success, or null.
     *
     * @return int|null
     */
    function botAttackInsertSpy(
        mysqli $db,
        string $prefix,
        array $user,
        array $source,
        array $target,
        int $probeCount,
        array $researchRow,
        float $universeSpeed
    ): ?int {
        $shipMix = [210 => $probeCount];
        $sourceCoords = [
            'galaxy' => (int) $source['planet_galaxy'],
            'system' => (int) $source['planet_system'],
            'planet' => (int) $source['planet_planet'],
        ];
        $targetCoords = [
            'galaxy' => (int) $target['planet_galaxy'],
            'system' => (int) $target['planet_system'],
            'planet' => (int) $target['planet_planet'],
        ];
        $estimate = botTravelEstimate($sourceCoords, $targetCoords, $shipMix, $researchRow, $universeSpeed);
        $duration = max(1, (int) $estimate['duration']);
        $fuel = max(0, (int) $estimate['consumption']);

        $availDeut = (float) ($source['planet_deuterium'] ?? 0);
        if ($availDeut < $fuel + 50) {
            return null;
        }

        $now = time();
        $userId = (int) $user['user_id'];
        $sourcePlanetId = (int) $source['planet_id'];
        $targetOwnerId = (int) ($target['planet_user_id'] ?? $target['user_id'] ?? 0);

        // Use the same serialization the game engine reads back via
        // FleetsLib::getFleetShipsArray() (== unserialize()).
        $fleetArray = serialize($shipMix);

        $startGalaxy = (int) $source['planet_galaxy'];
        $startSystem = (int) $source['planet_system'];
        $startPlanet = (int) $source['planet_planet'];
        $startType = (int) ($source['planet_type'] ?? 1);
        $endGalaxy = (int) $target['planet_galaxy'];
        $endSystem = (int) $target['planet_system'];
        $endPlanet = (int) $target['planet_planet'];
        $endType = (int) ($target['planet_type'] ?? 1);
        $endTime = $now + $duration;

        return botFleetInsertTransactional(
            $db,
            $prefix,
            $sourcePlanetId,
            function (mysqli $db) use (
                $prefix,
                $userId,
                $probeCount,
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
                $targetOwnerId,
                $sourcePlanetId
            ): ?int {
                $okInsert = $db->query(
                    "INSERT INTO `{$prefix}fleets` SET
                     `fleet_owner` = {$userId},
                     `fleet_mission` = 6,
                     `fleet_amount` = {$probeCount},
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
                     `fleet_target_owner` = {$targetOwnerId},
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
                     SET `ship_espionage_probe` = GREATEST(0, `ship_espionage_probe` - {$probeCount})
                     WHERE `ship_planet_id` = {$sourcePlanetId}
                     LIMIT 1"
                );
                if (!$okShips) {
                    return null;
                }
                $okPlanet = $db->query(
                    "UPDATE `{$prefix}planets`
                     SET `planet_deuterium` = GREATEST(0, `planet_deuterium` - {$fuel})
                     WHERE `planet_id` = {$sourcePlanetId}
                     LIMIT 1"
                );
                if (!$okPlanet) {
                    return null;
                }

                return $fleetId;
            }
        );
    }
}

if (!function_exists('botAttackInsertAttack')) {
    /**
     * INSERTs an attack fleet (mission=1), decrements ships + deuterium
     * (fuel) on the source planet. Returns the new fleet_id on success.
     *
     * @param array<int, int> $shipMix
     * @return int|null
     */
    function botAttackInsertAttack(
        mysqli $db,
        string $prefix,
        array $user,
        array $source,
        array $target,
        array $shipMix,
        array $researchRow,
        float $universeSpeed
    ): ?int {
        if (empty($shipMix)) {
            return null;
        }
        $sourceCoords = [
            'galaxy' => (int) $source['planet_galaxy'],
            'system' => (int) $source['planet_system'],
            'planet' => (int) $source['planet_planet'],
        ];
        $targetCoords = [
            'galaxy' => (int) $target['planet_galaxy'],
            'system' => (int) $target['planet_system'],
            'planet' => (int) $target['planet_planet'],
        ];
        $estimate = botTravelEstimate($sourceCoords, $targetCoords, $shipMix, $researchRow, $universeSpeed);
        $duration = max(1, (int) $estimate['duration']);
        $fuel = max(0, (int) $estimate['consumption']);

        $availDeut = (float) ($source['planet_deuterium'] ?? 0);
        if ($availDeut < $fuel + 50) {
            return null;
        }

        $totalShipCount = 0;
        foreach ($shipMix as $count) {
            $totalShipCount += (int) $count;
        }
        if ($totalShipCount <= 0) {
            return null;
        }

        $now = time();
        $userId = (int) $user['user_id'];
        $sourcePlanetId = (int) $source['planet_id'];
        $targetOwnerId = (int) ($target['planet_user_id'] ?? $target['user_id'] ?? 0);

        $fleetArray = serialize($shipMix);

        // Pre-compute ship decrement SQL fragment outside the closure so
        // the rollback path doesn't waste cycles on it.
        $shipCols = [
            202 => 'ship_small_cargo_ship', 203 => 'ship_big_cargo_ship',
            204 => 'ship_light_fighter', 205 => 'ship_heavy_fighter',
            206 => 'ship_cruiser', 207 => 'ship_battleship',
            210 => 'ship_espionage_probe', 211 => 'ship_bomber',
            213 => 'ship_destroyer', 215 => 'ship_battlecruiser',
        ];
        $decrements = [];
        foreach ($shipMix as $shipId => $count) {
            $col = $shipCols[(int) $shipId] ?? null;
            if ($col === null || $count <= 0) {
                continue;
            }
            $decrements[] = "`{$col}` = GREATEST(0, `{$col}` - " . (int) $count . ')';
        }

        $startGalaxy = (int) $source['planet_galaxy'];
        $startSystem = (int) $source['planet_system'];
        $startPlanet = (int) $source['planet_planet'];
        $startType = (int) ($source['planet_type'] ?? 1);
        $endGalaxy = (int) $target['planet_galaxy'];
        $endSystem = (int) $target['planet_system'];
        $endPlanet = (int) $target['planet_planet'];
        $endType = (int) ($target['planet_type'] ?? 1);
        $endTime = $now + $duration;

        return botFleetInsertTransactional(
            $db,
            $prefix,
            $sourcePlanetId,
            function (mysqli $db) use (
                $prefix,
                $userId,
                $totalShipCount,
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
                $targetOwnerId,
                $sourcePlanetId,
                $decrements
            ): ?int {
                $okInsert = $db->query(
                    "INSERT INTO `{$prefix}fleets` SET
                     `fleet_owner` = {$userId},
                     `fleet_mission` = 1,
                     `fleet_amount` = {$totalShipCount},
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
                     `fleet_target_owner` = {$targetOwnerId},
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

                if (!empty($decrements)) {
                    $okShips = $db->query(
                        "UPDATE `{$prefix}ships`
                         SET " . implode(', ', $decrements) . "
                         WHERE `ship_planet_id` = {$sourcePlanetId}
                         LIMIT 1"
                    );
                    if (!$okShips) {
                        return null;
                    }
                }
                $okPlanet = $db->query(
                    "UPDATE `{$prefix}planets`
                     SET `planet_deuterium` = GREATEST(0, `planet_deuterium` - {$fuel})
                     WHERE `planet_id` = {$sourcePlanetId}
                     LIMIT 1"
                );
                if (!$okPlanet) {
                    return null;
                }

                return $fleetId;
            }
        );
    }
}

if (!function_exists('botAttackEstimateLoot')) {
    /**
     * Estimates plunderable loot from an intel snapshot, capped by cargo
     * capacity. Plunder = half of each resource on the target.
     *
     * @param array<string, mixed> $intelEntry
     * @return array{loot:int, cap:int}
     */
    function botAttackEstimateLoot(array $intelEntry, int $cargoCapacity): array
    {
        $snap = $intelEntry['snapshot'] ?? null;
        if (!is_array($snap)) {
            return ['loot' => 0, 'cap' => $cargoCapacity];
        }
        $res = $snap['resources'] ?? [];
        $halfMetal = (int) floor((int) ($res['metal'] ?? 0) / 2);
        $halfCrystal = (int) floor((int) ($res['crystal'] ?? 0) / 2);
        $halfDeut = (int) floor((int) ($res['deuterium'] ?? 0) / 2);
        $totalHalf = $halfMetal + $halfCrystal + $halfDeut;
        $loot = $cargoCapacity > 0 ? min($totalHalf, $cargoCapacity) : $totalHalf;

        return ['loot' => $loot, 'cap' => $cargoCapacity];
    }
}

if (!function_exists('botAttackHarvestOnly')) {
    /**
     * Lightweight pass for bots that are *outside* their active window
     * (`isInActiveWindow($profile) === false`). It only:
     *
     *   - Loads cooldowns/pending_spies/intel from bot_quirks.
     *   - Harvests intel for spies whose expected_arrival has passed.
     *   - Persists the updated state plus a metrics delta with
     *     `harvest_only_loops += 1`.
     *
     * It does NOT scan candidates, send new spies, or launch attacks: a
     * sleeping bot should not be visibly active to the engine. The whole
     * point of running it during sleep is to avoid the trap where a spy
     * that arrived during sleep is presented to the bot as "freshly
     * captured" hours later when it wakes up.
     *
     * Returns the `attack: intel ...` log lines emitted by the harvest,
     * so the caller can append them to the bot's per-loop log.
     *
     * @param array<string, mixed> $state Bot state, mutated in place.
     * @return array<int, string>
     */
    function botAttackHarvestOnly(
        mysqli $db,
        string $prefix,
        array &$state,
        ?array $pricelist = null
    ): array {
        $maps = botAttackLoadCooldowns($state);
        $now = time();

        $harvest = botAttackHarvestIntel(
            $db,
            $prefix,
            $maps['pending_spies'],
            $maps['intel'],
            $now
        );

        // Also drain attack returns during sleep windows: if a fleet we
        // launched right before going to sleep comes back while idle, we
        // still want metrics to reflect the real outcome. Skip silently if
        // no pricelist is wired (unit tests without it should not crash).
        $returnLogs = [];
        if (is_array($pricelist)) {
            $userId = (int) ($state['bot_user_id'] ?? 0);
            $attackerAllyId = 0;
            if ($userId > 0) {
                $resAlly = $db->query(
                    "SELECT `user_ally_id` FROM `{$prefix}users` WHERE `user_id` = {$userId} LIMIT 1"
                );
                if ($resAlly instanceof \mysqli_result) {
                    $rowA = $resAlly->fetch_assoc();
                    $resAlly->free();
                    $attackerAllyId = (int) ($rowA['user_ally_id'] ?? 0);
                }
            }
            $returnLogs = botAttackHarvestReturns($db, $prefix, $state, $pricelist, $now, $attackerAllyId);
        }

        $metricsDelta = [
            'intel_captured' => count($harvest['logs']),
            'harvest_only_loops' => 1,
            'last_loop_at' => $now,
        ];

        botAttackSaveCooldowns(
            $db,
            $prefix,
            $state,
            $maps['attack_cooldowns'],
            $maps['spy_cooldowns'],
            $harvest['pending_spies'],
            $harvest['intel'],
            $metricsDelta
        );

        return array_merge($harvest['logs'], $returnLogs);
    }
}

if (!function_exists('botAttackRunPurposeful')) {
    /**
     * Per-user attack/spy orchestrator. Called once per loop after
     * transports + colonization. Steps:
     *
     *   1) Harvest intel from arrived spies.
     *   2) Scan candidate targets.
     *   3) For each candidate, decide:
     *        - Fresh intel? simulate + maybe attack.
     *        - No intel?    maybe send spy.
     *   4) Persist updated bot_quirks.
     *
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $planets
     * @param array<string, mixed> $researchRow
     * @param array<int, array<string, int|float>> $pricelist
     * @param array<string, mixed> $state
     * @param array<string, mixed> $profile profile from bot_accounts.json
     * @return array<int, string>
     */
    function botAttackRunPurposeful(
        mysqli $db,
        string $prefix,
        array $user,
        array $planets,
        array $researchRow,
        array $pricelist,
        array &$state,
        float $universeSpeed,
        array $profile = [],
        string $profileName = 'default'
    ): array {
        $logs = [];
        if (empty($planets)) {
            return $logs;
        }

        $maps = botAttackLoadCooldowns($state);
        $attackCooldowns = $maps['attack_cooldowns'];
        $spyCooldowns = $maps['spy_cooldowns'];
        $pendingSpies = $maps['pending_spies'];
        $intel = $maps['intel'];

        $now = time();

        // Step 1: harvest intel.
        $harvest = botAttackHarvestIntel($db, $prefix, $pendingSpies, $intel, $now);
        $intel = $harvest['intel'];
        $pendingSpies = $harvest['pending_spies'];
        foreach ($harvest['logs'] as $hl) {
            $logs[] = $hl;
        }

        // Step 1.5: harvest attack returns. Walk attacks_in_flight and
        // measure REAL loot/losses by observing engine-side state. Metrics
        // accumulate in bot_quirks.metrics. Mutates $state['bot_quirks'].
        $botUserId = (int) $user['user_id'];
        $allyId = (int) ($user['user_ally_id'] ?? 0);
        $returnLogs = botAttackHarvestReturns($db, $prefix, $state, $pricelist, $now, $allyId);
        foreach ($returnLogs as $rl) {
            $logs[] = $rl;
        }

        // Step 2: scan candidates. If the bot's alliance is at war the
        // helper biases enemy planets to the front of the list.
        $candidates = botAttackScanCandidates($db, $prefix, $botUserId, $allyId);
        if ($allyId > 0 && function_exists('botDiplomacyListEnemyUserIds') && !empty($candidates)) {
            $enemyUsers = botDiplomacyListEnemyUserIds($db, $prefix, $allyId);
            if (!empty($enemyUsers)) {
                $candidates = botAttackReorderEnemiesFirst($candidates, $enemyUsers);
            }
        }

        // Revenge prioritization: while the bot is still inside its reactive
        // window AND remembers who hit it last, pull the attacker's planets to
        // the front of the candidate list. This way the first viable raid the
        // pipeline can build aims at them. We don't filter out the rest, so a
        // bot whose attacker has no looteable planets (or is in vacation) can
        // still raid normal targets. Reorder is stable for non-revenge rows.
        $reactiveAttackerId = 0;
        $reactiveUntil = (int) ($state['bot_attack_reactive_until'] ?? 0);
        if ($reactiveUntil > $now && is_array($state['bot_quirks'] ?? null)) {
            $reactiveAttackerId = (int) ($state['bot_quirks']['last_attacker_user_id'] ?? 0);
        }
        if ($reactiveAttackerId > 0 && !empty($candidates)) {
            $candidates = botAttackReorderRevengeFirst($candidates, $reactiveAttackerId);
        }

        // Persistent per-bot metrics delta. Counters accumulate across loops
        // inside bot_quirks.metrics so we can analyze bot behavior offline
        // (intel/loot/skip distribution) without scraping ephemeral logs.
        $metricsDelta = [
            'intel_captured' => count($harvest['logs']),
            'last_loop_at' => $now,
        ];

        if (empty($candidates)) {
            // Even with no candidates, save state so cleanup happens.
            botAttackSaveCooldowns(
                $db,
                $prefix,
                $state,
                $attackCooldowns,
                $spyCooldowns,
                $pendingSpies,
                $intel,
                $metricsDelta
            );

            return $logs;
        }

        // Pre-fetch all bots ships (one query) for source picking.
        $planetIds = array_map(static fn (array $p): int => (int) $p['planet_id'], $planets);
        $shipsByPlanet = botAttackShipsByPlanet($db, $prefix, $planetIds);

        if (function_exists('botLlmAttackApplyRespondidoJobs')
            && function_exists('botLlmIsEnabled')
            && botLlmIsEnabled()
            && function_exists('botLlmJobTableExists')
            && botLlmJobTableExists($db, $prefix)) {
            $llmAtk = botLlmAttackApplyRespondidoJobs(
                $db,
                $prefix,
                $user,
                $planets,
                $state,
                $shipsByPlanet,
                $profile,
                $profileName,
                $researchRow,
                $pricelist,
                $universeSpeed,
                $now
            );
            foreach ($llmAtk['logs'] as $l) {
                $logs[] = $l;
            }
            foreach ($llmAtk['metrics_delta'] as $mk => $mv) {
                $metricsDelta[$mk] = ($metricsDelta[$mk] ?? 0) + (int) $mv;
            }
        }

        // Reserve ships committed to armed plans. Until each plan resolves
        // (botAttackHandlePendingPlans below: confirm or abort), those
        // ships must not be picked for new spies/attacks this loop. When
        // a plan aborts, the handler releases the reservation back into
        // $shipsByPlanet so a fresh decision can use those ships again.
        $pendingAttacksMap = is_array($state['bot_quirks']['pending_attacks'] ?? null)
            ? $state['bot_quirks']['pending_attacks']
            : [];
        // Snapshot the targets that already had a plan at loop start, so
        // that even after the handler resolves them (confirm OR abort)
        // we don't re-spy / re-arm the same target later this loop.
        $targetsLockedThisLoop = [];
        foreach ($pendingAttacksMap as $reservedTpid => $reservedPlan) {
            $targetsLockedThisLoop[(int) $reservedTpid] = true;
            $rsPid = (int) ($reservedPlan['source_planet_id'] ?? 0);
            $rsMix = is_array($reservedPlan['ship_mix'] ?? null) ? $reservedPlan['ship_mix'] : [];
            if ($rsPid <= 0 || !isset($shipsByPlanet[$rsPid])) {
                continue;
            }
            foreach ($rsMix as $shipId => $cnt) {
                $shipsByPlanet[$rsPid][(int) $shipId]
                    = max(0, (int) ($shipsByPlanet[$rsPid][(int) $shipId] ?? 0) - (int) $cnt);
            }
        }

        $personality = (string) ($state['bot_personality'] ?? 'minero');
        $attackCdSec = botAttackPersonalityCooldown($personality);
        $spyCdSec = defined('BOT_SPY_COOLDOWN_BASE_SECONDS') ? (int) BOT_SPY_COOLDOWN_BASE_SECONDS : 600;

        $maxAttacks = defined('BOT_ATTACK_MAX_PER_LOOP_PER_USER') ? (int) BOT_ATTACK_MAX_PER_LOOP_PER_USER : 2;
        $maxSpies = defined('BOT_SPY_MAX_PER_LOOP_PER_USER') ? (int) BOT_SPY_MAX_PER_LOOP_PER_USER : 4;
        $minLoot = defined('BOT_ATTACK_MIN_LOOT_ESTIMATE') ? (int) BOT_ATTACK_MIN_LOOT_ESTIMATE : 50_000;

        $attacksDone = 0;
        $spiesDone = 0;

        // Fleet slot budget: bots, like humans, cannot exceed
        // `1 + research_computer_technology` simultaneous fleets. If the
        // engine rejects an insert we'd burn a transaction for nothing;
        // worse, the reservation accounting goes out of sync. Bail out
        // pre-emptively when there's no slot left, and decrement locally
        // every time we send a new spy or arm a plan (recheck spy).
        $slots = botAttackFleetSlotsInfo($db, $prefix, $botUserId, $researchRow);
        $freeSlots = (int) $slots['free'];
        if ($freeSlots <= 0) {
            $logs[] = sprintf(
                'attack: idle no_fleet_slots (used=%d max=%d)',
                (int) $slots['used'],
                (int) $slots['max']
            );
            $metricsDelta['fleet_slot_full_events'] = ($metricsDelta['fleet_slot_full_events'] ?? 0) + 1;
        }

        // Step 2.5: process plans whose recheck spy has arrived. May
        // confirm or abort each one. Confirmed plans become real attack
        // fleets and bump attacks_in_flight; aborted plans release their
        // reservation back into $shipsByPlanet for re-use this loop.
        $pendingResult = botAttackHandlePendingPlans(
            $db,
            $prefix,
            $state,
            $user,
            $planets,
            $shipsByPlanet,
            $attackCooldowns,
            $researchRow,
            $pricelist,
            $universeSpeed,
            $now,
            $maxAttacks,
            $attacksDone,
            $profile,
            $profileName
        );
        foreach ($pendingResult['logs'] as $pl) {
            $logs[] = $pl;
        }
        foreach ($pendingResult['metrics_delta'] as $pmk => $pmv) {
            $metricsDelta[$pmk] = ($metricsDelta[$pmk] ?? 0) + (int) $pmv;
        }
        $confirmedAttacks = (int) $pendingResult['attacks_confirmed'];
        $attacksDone += $confirmedAttacks;
        // Each confirmation inserted a new attack fleet via the engine,
        // so the local slot counter has to follow.
        $freeSlots = max(0, $freeSlots - $confirmedAttacks);

        // Refresh local intel: the handler may have written fresh
        // snapshots for the targets it processed.
        $intel = is_array($state['bot_quirks']['intel'] ?? null)
            ? $state['bot_quirks']['intel']
            : $intel;

        // Observability counters: aggregated per-loop so we can emit a single
        // "attack: idle ..." line when nothing actionable happened despite
        // having candidates. Avoids per-target spam while still surfacing the
        // common silent-failure modes (no probes, no cargo, raid mix can't be
        // built, target in cooldown, etc.).
        $idleCounters = [
            'candidates' => count($candidates),
            'fresh_intel' => 0,
            'cooldown_spy' => 0,
            'no_spy_src' => 0,
            'no_atk_src' => 0,
            'raidmix_fail' => 0,
        ];

        // Step 3: process candidates.
        foreach ($candidates as $target) {
            if ($attacksDone >= $maxAttacks && $spiesDone >= $maxSpies) {
                break;
            }
            $targetPid = (int) $target['planet_id'];
            $targetCoords = sprintf(
                '%d:%d:%d',
                (int) $target['planet_galaxy'],
                (int) $target['planet_system'],
                (int) $target['planet_planet']
            );

            // A plan was armed against this target at loop start (or is
            // still pending after the recheck handler). Don't re-spy or
            // re-arm the same target this loop: we'd send duplicate probes
            // or arm a second redundant plan after the first one aborted.
            if (isset($targetsLockedThisLoop[$targetPid])) {
                continue;
            }

            $intelEntry = $intel[$targetPid] ?? null;
            $hasFreshIntel = is_array($intelEntry) && botAttackIntelIsFresh($intelEntry, $now);

            if (!$hasFreshIntel) {
                if ($spiesDone >= $maxSpies) {
                    $metricsDelta['spies_throttled'] = ($metricsDelta['spies_throttled'] ?? 0) + 1;

                    continue;
                }
                if ($freeSlots <= 0) {
                    $metricsDelta['spies_skipped_no_slot'] = ($metricsDelta['spies_skipped_no_slot'] ?? 0) + 1;

                    continue;
                }
                if (isset($spyCooldowns[$targetPid]) && ($now - $spyCooldowns[$targetPid]) < $spyCdSec) {
                    $idleCounters['cooldown_spy']++;
                    $metricsDelta['cooldown_spy_events'] = ($metricsDelta['cooldown_spy_events'] ?? 0) + 1;

                    continue;
                }
                $sourcePlanet = botAttackPickSourcePlanetForSpy(
                    $planets,
                    $shipsByPlanet,
                    $target,
                    $spyCooldowns,
                    $now,
                    $spyCdSec
                );
                if ($sourcePlanet === null) {
                    // No planet of ours has free probes (or all in spy cooldown).
                    // Counted in the per-loop aggregate; per-target log would be
                    // far too noisy when the bot has many candidates.
                    $idleCounters['no_spy_src']++;
                    $metricsDelta['no_spy_src_events'] = ($metricsDelta['no_spy_src_events'] ?? 0) + 1;

                    continue;
                }
                $probeCount = defined('BOT_SPY_PROBES_PER_MISSION') ? (int) BOT_SPY_PROBES_PER_MISSION : 4;
                $fleetId = botAttackInsertSpy(
                    $db,
                    $prefix,
                    $user,
                    $sourcePlanet,
                    $target,
                    $probeCount,
                    $researchRow,
                    $universeSpeed
                );
                if ($fleetId === null) {
                    $logs[] = sprintf('attack: skip %s (insert spy failed)', $targetCoords);
                    $metricsDelta['spies_insert_fail'] = ($metricsDelta['spies_insert_fail'] ?? 0) + 1;

                    continue;
                }
                $sourceCoords = [
                    'galaxy' => (int) $sourcePlanet['planet_galaxy'],
                    'system' => (int) $sourcePlanet['planet_system'],
                    'planet' => (int) $sourcePlanet['planet_planet'],
                ];
                $tCoordsArr = [
                    'galaxy' => (int) $target['planet_galaxy'],
                    'system' => (int) $target['planet_system'],
                    'planet' => (int) $target['planet_planet'],
                ];
                $est = botTravelEstimate($sourceCoords, $tCoordsArr, [210 => $probeCount], $researchRow, $universeSpeed);
                $expectedArrival = $now + (int) $est['duration'];

                $pendingSpies[$fleetId] = [
                    'target_planet_id' => $targetPid,
                    'expected_arrival' => $expectedArrival,
                    'sent_at' => $now,
                ];
                $spyCooldowns[$targetPid] = $now;
                $spiesDone++;
                $freeSlots--;
                $metricsDelta['spies_sent'] = ($metricsDelta['spies_sent'] ?? 0) + 1;
                $metricsDelta['last_spy_at'] = $now;
                // Decrement local copy of probes so the next iteration picks
                // a different source planet if needed.
                $sourcePid = (int) $sourcePlanet['planet_id'];
                if (isset($shipsByPlanet[$sourcePid][210])) {
                    $shipsByPlanet[$sourcePid][210] = max(0, $shipsByPlanet[$sourcePid][210] - $probeCount);
                }
                $logs[] = sprintf(
                    'attack: spy %s probes=%d eta=%ds',
                    $targetCoords,
                    $probeCount,
                    (int) $est['duration']
                );

                continue;
            }

            // Fresh intel branch.
            $idleCounters['fresh_intel']++;
            $metricsDelta['fresh_intel_events'] = ($metricsDelta['fresh_intel_events'] ?? 0) + 1;
            if ($attacksDone >= $maxAttacks) {
                $metricsDelta['attacks_throttled'] = ($metricsDelta['attacks_throttled'] ?? 0) + 1;

                continue;
            }
            $sourcePick = botAttackPickSourcePlanetForAttack(
                $planets,
                $shipsByPlanet,
                $target,
                $attackCooldowns,
                $now,
                $attackCdSec
            );
            if ($sourcePick === null) {
                // We have fresh intel but no planet of ours can field a viable
                // raid right now (no cargo + escort, or every source still in
                // attack cooldown). Counted in the aggregate; the per-target
                // log would explode when the bot has dozens of stale intels.
                $idleCounters['no_atk_src']++;
                $metricsDelta['no_atk_src_events'] = ($metricsDelta['no_atk_src_events'] ?? 0) + 1;

                continue;
            }
            $sourcePlanet = $sourcePick['planet'];
            $sourceShips = $sourcePick['ships'];

            $intelLoot = botAttackEstimateLoot($intelEntry, PHP_INT_MAX);
            if ($intelLoot['loot'] < $minLoot) {
                $logs[] = sprintf('attack: skip %s (intel loot=%d < min=%d)', $targetCoords, $intelLoot['loot'], $minLoot);
                $metricsDelta['attacks_skipped_low_loot'] = ($metricsDelta['attacks_skipped_low_loot'] ?? 0) + 1;

                continue;
            }

            $defenderShips = ($intelEntry['snapshot']['ships'] ?? []);
            $defenderDefenses = ($intelEntry['snapshot']['defenses'] ?? []);

            // Estimate defender total cost, used to size the raid mix.
            $defenderValue = botCombatLossesValue($defenderShips, $pricelist)
                + botCombatLossesValue($defenderDefenses, $pricelist);

            $raidMix = botPickRaidMix(
                (int) $intelLoot['loot'],
                (int) $defenderValue,
                $sourceShips,
                $researchRow
            );
            if ($raidMix === null) {
                $idleCounters['raidmix_fail']++;
                $metricsDelta['raidmix_fail'] = ($metricsDelta['raidmix_fail'] ?? 0) + 1;
                $logs[] = sprintf('attack: skip %s (no viable raid mix)', $targetCoords);

                continue;
            }
            $shipMix = $raidMix['by_id'];
            // Cap loot by actual cargo capacity of the chosen mix.
            $loot = min((int) $intelLoot['loot'], max(0, (int) $raidMix['cargo_capacity']));
            if ($loot < $minLoot) {
                $logs[] = sprintf('attack: skip %s (cap loot=%d < min=%d)', $targetCoords, $loot, $minLoot);
                $metricsDelta['attacks_skipped_capped_loot'] = ($metricsDelta['attacks_skipped_capped_loot'] ?? 0) + 1;
                // Cap-loot skip means: the target IS profitable, but our raid
                // mix capacity is the bottleneck. Raise a cargo-pressure
                // signal so the build module prioritizes big cargos in the
                // next loops. Signal expires after BOT_CARGO_PRESSURE_TTL.
                botCargoPressureMark($state, $now);

                continue;
            }

            // Travel cost / fuel.
            $sourceCoords = [
                'galaxy' => (int) $sourcePlanet['planet_galaxy'],
                'system' => (int) $sourcePlanet['planet_system'],
                'planet' => (int) $sourcePlanet['planet_planet'],
            ];
            $tCoordsArr = [
                'galaxy' => (int) $target['planet_galaxy'],
                'system' => (int) $target['planet_system'],
                'planet' => (int) $target['planet_planet'],
            ];
            $estimate = botTravelEstimate($sourceCoords, $tCoordsArr, $shipMix, $researchRow, $universeSpeed);
            $fuel = max(0, (int) $estimate['consumption']);

            // Battle simulation.
            $sim = botCombatSimulate(
                $shipMix,
                $defenderShips,
                $defenderDefenses,
                $researchRow,
                $researchRow, // we use attacker's research as a proxy: simpler, slightly pessimistic
                $pricelist
            );
            if ($sim['winner'] === 'defender') {
                $logs[] = sprintf(
                    'attack: skip %s (sim defender wins, my_losses=%d)',
                    $targetCoords,
                    (int) $sim['attacker_losses_value']
                );
                $metricsDelta['attacks_skipped_sim_lose'] = ($metricsDelta['attacks_skipped_sim_lose'] ?? 0) + 1;

                continue;
            }

            $netLoot = $loot - $fuel;
            $myLosses = max(1, (int) $sim['attacker_losses_value']);
            $ratio = $netLoot / $myLosses;

            $sourcePid = (int) $sourcePlanet['planet_id'];
            $rolesMap = is_array($state['bot_planet_roles'] ?? null) ? $state['bot_planet_roles'] : [];
            $sourceRole = is_string($rolesMap[(string) $sourcePid] ?? null) ? $rolesMap[(string) $sourcePid] : null;
            $aggr = botAttackAggressivenessScore($profile, $state, $sourceRole);
            $threshold = botAttackProfitabilityRatio($aggr, $profile);

            // War discount: if the target is an enemy alliance (war active
            // between the bot's alliance and theirs), apply a configurable
            // discount on the rentability threshold. Keeps the bot pickier
            // for opportunistic raids and looser for revenge raids.
            $myAllyId = (int) ($user['user_ally_id'] ?? 0);
            $targetUserId = (int) ($target['user_id'] ?? $target['planet_user_id'] ?? 0);
            if ($myAllyId > 0 && $targetUserId > 0 && function_exists('botDiplomacyIsAtWar')) {
                $targetAllyId = (int) ($target['user_ally_id'] ?? 0);
                if ($targetAllyId > 0 && botDiplomacyIsAtWar($db, $prefix, $myAllyId, $targetAllyId)) {
                    $discountX100 = defined('BOT_DIPLO_WAR_RENTABILITY_DISCOUNT_X100')
                        ? (int) BOT_DIPLO_WAR_RENTABILITY_DISCOUNT_X100
                        : 20;
                    $threshold = $threshold * max(0.0, 1.0 - $discountX100 / 100.0);
                    $metricsDelta['diplo_war_threshold_applied']
                        = ($metricsDelta['diplo_war_threshold_applied'] ?? 0) + 1;
                }
            }

            if ($ratio < $threshold) {
                $logs[] = sprintf(
                    'attack: skip %s (ratio=%.2f < %.2f, loot=%d losses=%d)',
                    $targetCoords,
                    $ratio,
                    $threshold,
                    $netLoot,
                    $myLosses
                );
                $metricsDelta['attacks_skipped_ratio'] = ($metricsDelta['attacks_skipped_ratio'] ?? 0) + 1;

                continue;
            }
            if ($sim['winner'] === 'draw') {
                // Drawn outcomes are usually not worth committing the fleet
                // even if the math passes; bail unless we're 'very_aggressive'.
                if (botAttackAggressivenessBracket($aggr) !== 'very_aggressive') {
                    $logs[] = sprintf('attack: skip %s (sim draw, mood not aggressive)', $targetCoords);
                    $metricsDelta['attacks_skipped_draw'] = ($metricsDelta['attacks_skipped_draw'] ?? 0) + 1;

                    continue;
                }
            }

            $stakesInit = function_exists('botLlmAttackIsHighStakes')
                && botLlmAttackIsHighStakes((int) $netLoot, (float) $ratio);
            if ($stakesInit
                && function_exists('botLlmIsEnabled')
                && botLlmIsEnabled()
                && function_exists('botLlmJobTableExists')
                && botLlmJobTableExists($db, $prefix)) {
                if ($freeSlots <= 0) {
                    $metricsDelta['attacks_skipped_no_slot'] = ($metricsDelta['attacks_skipped_no_slot'] ?? 0) + 1;

                    continue;
                }
                $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
                $gates = is_array($quirks['attack_llm_gate'] ?? null) ? $quirks['attack_llm_gate'] : [];
                $dedupeI = 'atk_v1:i:' . $targetPid;
                if (isset($gates[$targetPid])) {
                    $stGate = function_exists('botLlmJobLatestStatus') && function_exists('botLlmAttackSkill')
                        ? botLlmJobLatestStatus($db, $prefix, $botUserId, botLlmAttackSkill(), $dedupeI)
                        : '';
                    if (in_array($stGate, ['pedido', 'procesando', 'respondido'], true)) {
                        $targetsLockedThisLoop[$targetPid] = true;
                        $logs[] = sprintf('attack: LLM gate esperando %s status=%s', $targetCoords, $stGate);

                        continue;
                    }
                    unset($gates[$targetPid]);
                    $quirks['attack_llm_gate'] = $gates;
                    $state['bot_quirks'] = $quirks;
                }
                $vicName = (string) ($target['user_name'] ?? '');
                if (function_exists('botLlmAttackTryEnqueue')) {
                    $enq = botLlmAttackTryEnqueue(
                        $db,
                        $prefix,
                        $botUserId,
                        (string) ($user['user_name'] ?? ''),
                        $dedupeI,
                        $profile,
                        $profileName,
                        $now,
                        'initial',
                        $targetPid,
                        $targetCoords,
                        $targetUserId,
                        $vicName,
                        (int) $netLoot,
                        (int) $myLosses,
                        (float) $ratio,
                        (float) $threshold,
                        $minLoot,
                        (string) $sim['winner'],
                        (int) ($sim['rounds_used'] ?? 0),
                        $researchRow,
                        $planets,
                        is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : []
                    );
                    if ($enq === 'queued' || $enq === 'duplicate') {
                        $gates[$targetPid] = [
                            'dedupe' => $dedupeI,
                            'source_planet_id' => $sourcePid,
                            'ship_mix' => $shipMix,
                            'ratio_threshold' => (float) $threshold,
                            'min_loot' => $minLoot,
                            'estimated_loot' => (int) $netLoot,
                            'estimated_losses' => (int) $myLosses,
                            'target_planet_id' => $targetPid,
                        ];
                        $quirks['attack_llm_gate'] = $gates;
                        $state['bot_quirks'] = $quirks;
                        $targetsLockedThisLoop[$targetPid] = true;
                        $logs[] = sprintf(
                            'attack: LLM gate %s enq=%s loot=%d ratio=%.2f',
                            $targetCoords,
                            $enq,
                            $netLoot,
                            $ratio
                        );
                        $metricsDelta['attack_llm_initial_queued']
                            = ($metricsDelta['attack_llm_initial_queued'] ?? 0) + 1;

                        continue;
                    }
                }
            }

            // Arm a plan instead of launching directly. The intel we have
            // is up to BOT_INTEL_TTL_SECONDS old; before committing the
            // fleet we want one last snapshot. Send a recheck spy and
            // store the full decision in bot_quirks.pending_attacks. When
            // the recheck lands (1-5 min typically), the handler at the
            // top of the next loop replays sim+ratio+loot with fresh data
            // and either CONFIRMS (launches via botAttackInsertAttack) or
            // ABORTS (releases the reservation, no fleet sent).
            if ($freeSlots <= 0) {
                // We have a valid attack decision but no fleet slot to even
                // send the recheck spy. Bail without arming so we don't
                // overcommit. Plan will be re-evaluated on a later loop
                // when an in-flight fleet returns.
                $metricsDelta['attacks_skipped_no_slot'] = ($metricsDelta['attacks_skipped_no_slot'] ?? 0) + 1;

                continue;
            }
            $probeCount = defined('BOT_SPY_PROBES_PER_MISSION') ? (int) BOT_SPY_PROBES_PER_MISSION : 4;
            $recheckFleetId = botAttackInsertSpy(
                $db,
                $prefix,
                $user,
                $sourcePlanet,
                $target,
                $probeCount,
                $researchRow,
                $universeSpeed
            );
            if ($recheckFleetId === null) {
                $logs[] = sprintf('attack: skip %s (insert recheck spy failed)', $targetCoords);
                $metricsDelta['spies_insert_fail'] = ($metricsDelta['spies_insert_fail'] ?? 0) + 1;

                continue;
            }
            $sourceCoordsForRecheck = [
                'galaxy' => (int) $sourcePlanet['planet_galaxy'],
                'system' => (int) $sourcePlanet['planet_system'],
                'planet' => (int) $sourcePlanet['planet_planet'],
            ];
            $targetCoordsForRecheck = [
                'galaxy' => (int) $target['planet_galaxy'],
                'system' => (int) $target['planet_system'],
                'planet' => (int) $target['planet_planet'],
            ];
            $recheckEstimate = botTravelEstimate(
                $sourceCoordsForRecheck,
                $targetCoordsForRecheck,
                [210 => $probeCount],
                $researchRow,
                $universeSpeed
            );
            $recheckArrival = $now + max(1, (int) $recheckEstimate['duration']);

            $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
            $pendingAttacks = is_array($quirks['pending_attacks'] ?? null)
                ? $quirks['pending_attacks']
                : [];
            $pendingAttacks[$targetPid] = [
                'armed_at' => $now,
                'recheck_fleet_id' => (int) $recheckFleetId,
                'recheck_arrival' => $recheckArrival,
                'source_planet_id' => $sourcePid,
                'ship_mix' => $shipMix,
                'ratio_threshold' => (float) $threshold,
                'min_loot' => $minLoot,
                'estimated_loot' => (int) $netLoot,
                'estimated_losses' => (int) $myLosses,
                'target_planet_id' => $targetPid,
            ];
            $quirks['pending_attacks'] = $pendingAttacks;
            $state['bot_quirks'] = $quirks;
            $targetsLockedThisLoop[$targetPid] = true;

            $attackCooldowns[$sourcePid] = $now;
            $attacksDone++;
            $freeSlots--;
            $metricsDelta['attacks_armed'] = ($metricsDelta['attacks_armed'] ?? 0) + 1;
            $metricsDelta['last_attack_at'] = $now;

            // Reserve ships for the plan. Same source can't be re-used
            // for another spy/attack this loop until either the plan
            // confirms or aborts.
            foreach ($shipMix as $shipId => $cnt) {
                if (isset($shipsByPlanet[$sourcePid][$shipId])) {
                    $shipsByPlanet[$sourcePid][$shipId] = max(
                        0,
                        $shipsByPlanet[$sourcePid][$shipId] - (int) $cnt
                    );
                }
            }
            // The recheck spy also consumes probes from the source.
            if (isset($shipsByPlanet[$sourcePid][210])) {
                $shipsByPlanet[$sourcePid][210] = max(
                    0,
                    $shipsByPlanet[$sourcePid][210] - $probeCount
                );
            }

            $logs[] = sprintf(
                'attack: armed %s recheck_eta=%ds ratio=%.2f loot=%d losses=%d',
                $targetCoords,
                $recheckArrival - $now,
                $ratio,
                $netLoot,
                $myLosses
            );
        }

        // Aggregate idle line: only emitted when the bot is "productively
        // stuck", i.e. it has fresh intel ready to act on but couldn't, or it
        // hit raidmix_fail. We deliberately don't log when the bot simply has
        // no probes yet (early game): that would spam one line per bot per
        // loop for every bot still researching espionage tech.
        $productivelyStuck = $idleCounters['fresh_intel'] > 0
            || $idleCounters['no_atk_src'] > 0
            || $idleCounters['raidmix_fail'] > 0;
        if ($spiesDone === 0 && $attacksDone === 0 && $productivelyStuck) {
            $logs[] = sprintf(
                'attack: idle candidates=%d fresh_intel=%d no_spy_src=%d no_atk_src=%d raidmix_fail=%d cooldown_spy=%d',
                $idleCounters['candidates'],
                $idleCounters['fresh_intel'],
                $idleCounters['no_spy_src'],
                $idleCounters['no_atk_src'],
                $idleCounters['raidmix_fail'],
                $idleCounters['cooldown_spy']
            );
        }

        botAttackSaveCooldowns(
            $db,
            $prefix,
            $state,
            $attackCooldowns,
            $spyCooldowns,
            $pendingSpies,
            $intel,
            $metricsDelta
        );

        return $logs;
    }
}

require_once __DIR__ . '/llm_attack_decision.php';
