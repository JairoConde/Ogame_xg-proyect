<?php

declare(strict_types=1);

/**
 * Bot safety module.
 *
 * Provides:
 *  - Hard caps: building, research and unit counts.
 *  - Action-time guard that returns a "skip reason" when an action would
 *    exceed any cap, so the caller can pick a different action instead of
 *    crashing or blocking.
 *  - Schema validation that aborts early with a precise diagnostic when a
 *    required column type or table is missing, so admins know exactly which
 *    migration script to run.
 *
 * All values here are intentionally conservative; if the user wants a higher
 * cap, edit the constants below and re-run the bot. Caps are enforced both
 * at decision time (so the bot avoids generating capped actions) and at
 * action-application time (defense in depth).
 */
if (!defined('BOT_CAP_BUILDING_LEVEL')) {
    define('BOT_CAP_BUILDING_LEVEL', 70);
}

if (!defined('BOT_CAP_RESEARCH_LEVEL')) {
    define('BOT_CAP_RESEARCH_LEVEL', 50);
}

if (!defined('BOT_CAP_UNIT_PER_TYPE')) {
    // Per-column ceiling for any single ship/defense type on a planet.
    //
    // The actual storage cap depends on whether the optional migration
    // scripts/migrate_widen_units_to_bigint.php has been applied:
    //   - int(11) signed:  ~2.15B  (default install before that migration)
    //   - bigint(20):      ~9.2 quintillion (after the migration)
    //
    // We keep this conservative at 1B so the bot can't accidentally drive a
    // value over the int(11) limit on installs that haven't migrated yet.
    // Raise it manually if your DB is widened AND you actually want huge
    // unit counts. There's no automatic detection — the bot doesn't query
    // schema for this on every action by design (perf).
    define('BOT_CAP_UNIT_PER_TYPE', 1_000_000_000);
}

if (!defined('BOT_CAP_UNITS_PER_QUEUE_ENTRY')) {
    // The game's ShipyardController limits a single "build order" to 9999
    // units (constants.php / MAX_FLEET_OR_DEFS_PER_ROW). The bot writes the
    // hangar queue directly via SQL, so we mirror that limit ourselves to
    // avoid producing rows the human-side controllers would never accept.
    // Multiple sub-9999 rows in the queue are fine; the in-game queue
    // processor handles them sequentially.
    define(
        'BOT_CAP_UNITS_PER_QUEUE_ENTRY',
        defined('MAX_FLEET_OR_DEFS_PER_ROW') ? (int) MAX_FLEET_OR_DEFS_PER_ROW : 9999
    );
}

if (!defined('BOT_MINING_DRILL_LIMIT_PER_PLANET')) {
    // Same hard cap as ShipyardController::MINING_DRILL_LIMIT_PER_PLANET (built
    // + hangar queue cannot exceed this on one planet).
    define('BOT_MINING_DRILL_LIMIT_PER_PLANET', 1000);
}

if (!function_exists('botMiningDrillBuildableRemaining')) {
    /**
     * How many more mining drills (ship id 216) may still be ordered on this
     * planet, mirroring ShipyardController::getMiningDrillItemLimit().
     *
     * @param array<string, mixed> $planet
     */
    function botMiningDrillBuildableRemaining(array $planet): int
    {
        $built = (int) ($planet['ship_mining_drill'] ?? 0);
        $queued = function_exists('getQueuedHangarAmount')
            ? getQueuedHangarAmount($planet, 216)
            : 0;
        $effective = $built + $queued;

        return max(0, BOT_MINING_DRILL_LIMIT_PER_PLANET - $effective);
    }
}

if (!defined('BOT_CAP_RESOURCE_VALUE')) {
    // Hard ceiling for any single-step resource cost the bot should be
    // willing to pay (in metal+crystal+deuterium). Above this we abort to
    // avoid pathological states.
    define('BOT_CAP_RESOURCE_VALUE', 1.0e30);
}

// ----------------------------------------------------------------------------
// Bot attack module constants.
//
// All knobs for the attack/spy/intel pipeline live here so a single file
// captures every side-effect-cap of the new code path. Edit and restart.
// ----------------------------------------------------------------------------

if (!defined('BOT_ATTACK_MAX_PER_LOOP_PER_USER')) {
    // Cap how many real attack fleets a single user emits per loop. Two is
    // a sane default that mirrors transport's pacing without flooding the
    // fleets table on a single tick.
    define('BOT_ATTACK_MAX_PER_LOOP_PER_USER', 2);
}

if (!defined('BOT_SPY_MAX_PER_LOOP_PER_USER')) {
    // Cap how many spy fleets a single user emits per loop. Higher than
    // attacks because spying is cheap and gating attacks behind intel.
    define('BOT_SPY_MAX_PER_LOOP_PER_USER', 4);
}

if (!defined('BOT_ATTACK_COOLDOWN_BASE_SECONDS')) {
    // Minimum gap between two attacks leaving the same source planet.
    // Personality scales this (cazador shorter, defensor longer).
    define('BOT_ATTACK_COOLDOWN_BASE_SECONDS', 1800); // 30 min
}

if (!defined('BOT_SPY_COOLDOWN_BASE_SECONDS')) {
    // Minimum gap between two spy missions targeting the SAME enemy planet.
    // Prevents spy-spam while still allowing fresh intel rotations.
    define('BOT_SPY_COOLDOWN_BASE_SECONDS', 600); // 10 min
}

if (!defined('BOT_INTEL_TTL_SECONDS')) {
    // After this many seconds, an intel snapshot in bot_quirks expires and
    // the bot must re-spy the target before it can decide to attack.
    define('BOT_INTEL_TTL_SECONDS', 1800); // 30 min
}

if (!defined('BOT_INTEL_MAX_ENTRIES')) {
    // Hard cap on how many planet intel snapshots we keep in bot_quirks after
    // TTL filtering. Keeps JSON decode/encode cheap for bots that spy many
    // neighbours. Set to 0 to disable truncation (not recommended).
    define('BOT_INTEL_MAX_ENTRIES', 50);
}

if (!defined('BOT_ATTACK_PLAN_TTL_SECONDS')) {
    // After this many seconds, an armed pending_attacks plan is dropped
    // even if its recheck spy never arrived (target deleted, engine
    // hiccup, bot was offline a long time). Stops stale plans from
    // growing the bot_quirks JSON forever.
    define('BOT_ATTACK_PLAN_TTL_SECONDS', 6 * 3600); // 6h
}

if (!defined('BOT_ATTACK_REACTIVE_BONUS_MAX')) {
    // Peak revenge bonus added to botAttackAggressivenessScore right after
    // the bot has been hit (decays linearly to 0 as bot_attack_reactive_until
    // approaches). Only applied to bots with an offensive leaning (cazador,
    // flotero, turbo, opportunist, raider style) - quiet miners stay quiet.
    define('BOT_ATTACK_REACTIVE_BONUS_MAX', 2.0);
}

if (!defined('BOT_ATTACK_MIN_LOOT_ESTIMATE')) {
    // Don't even consider attacking a target whose estimated half-resource
    // loot falls below this floor. Avoids log noise on freshly-stripped
    // planets and tiny accounts.
    define('BOT_ATTACK_MIN_LOOT_ESTIMATE', 50_000);
}

if (!defined('BOT_ATTACK_FLEET_RESERVE_PCT')) {
    // Fraction of the source planet's combat ships and cargos that the bot
    // refuses to commit to a single raid. Ensures the planet keeps a
    // residual force at home (less juicy as a counter-target).
    define('BOT_ATTACK_FLEET_RESERVE_PCT', 0.20);
}

if (!defined('BOT_SPY_PROBES_PER_MISSION')) {
    // How many espionage probes the bot sends per spy mission. Higher gives
    // better visibility per the engine's formula but costs more probes.
    define('BOT_SPY_PROBES_PER_MISSION', 4);
}

if (!defined('BOT_ATTACK_RESERVED_SYSTEMS')) {
    // Solar systems that bots are forbidden from attacking or spying in,
    // applied to ALL galaxies. Reserved for human players / the admin.
    // This is INDEPENDENT of the colonization reservation rule.
    define('BOT_ATTACK_RESERVED_SYSTEMS', json_encode([1]));
}

// Cargo pressure: when a bot has fresh intel showing a target IS profitable
// but its current raid mix capacity is too small to reach BOT_ATTACK_MIN_LOOT
// (i.e. the "cap loot < min" skip is fired), we set a signal in bot_quirks so
// the build module prioritizes big cargos in upcoming loops. The signal is
// time-bounded: if no new skip happens within the TTL it expires on its own.
if (!defined('BOT_CARGO_PRESSURE_TTL_SECONDS')) {
    // 6 hours of "we still wish we had more cargo".
    define('BOT_CARGO_PRESSURE_TTL_SECONDS', 6 * 3600);
}
if (!defined('BOT_CARGO_PRESSURE_MIN_SKIPS_TO_PRIORITIZE')) {
    // How many capped-loot skips we need to see before the build module
    // upgrades big cargos to "priority" weight. 1 means: any single
    // cap-loot skip already lifts the priority.
    define('BOT_CARGO_PRESSURE_MIN_SKIPS_TO_PRIORITIZE', 1);
}
if (!defined('BOT_CARGO_PRESSURE_BIG_CARGO_TARGET')) {
    // Soft target of big cargos the bot wants under active cargo pressure.
    // Big cargo capacity is 25,000 each, so 20 already covers a 500k loot.
    define('BOT_CARGO_PRESSURE_BIG_CARGO_TARGET', 20);
}

// Profitability ratios (loot_neto / valor_naves_perdidas). Range [3, 6] by
// default; clamped to BOT_ATTACK_RATIO_MIN..MAX. Edit these to relax/tighten
// the entire universe of bots' aggression at once.
if (!defined('BOT_ATTACK_RATIO_VERY_AGGRESSIVE')) {
    define('BOT_ATTACK_RATIO_VERY_AGGRESSIVE', 3.0);
}
if (!defined('BOT_ATTACK_RATIO_AGGRESSIVE')) {
    define('BOT_ATTACK_RATIO_AGGRESSIVE', 4.0);
}
if (!defined('BOT_ATTACK_RATIO_MODERATE')) {
    define('BOT_ATTACK_RATIO_MODERATE', 5.0);
}
if (!defined('BOT_ATTACK_RATIO_CONSERVATIVE')) {
    define('BOT_ATTACK_RATIO_CONSERVATIVE', 6.0);
}
if (!defined('BOT_ATTACK_RATIO_MIN')) {
    define('BOT_ATTACK_RATIO_MIN', 1.5);
}
if (!defined('BOT_ATTACK_RATIO_MAX')) {
    define('BOT_ATTACK_RATIO_MAX', 10.0);
}

if (!function_exists('botAttackReservedSystems')) {
    /**
     * Returns the list of solar systems that bots must never attack/spy in.
     * The constant is stored as a JSON-encoded list so it can be redefined
     * via define() without losing array semantics across constant systems.
     *
     * @return array<int, int>
     */
    function botAttackReservedSystems(): array
    {
        $raw = BOT_ATTACK_RESERVED_SYSTEMS;
        if (is_array($raw)) {
            return array_values(array_map('intval', $raw));
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_map('intval', $decoded));
            }
        }
        if (is_int($raw)) {
            return [$raw];
        }

        return [];
    }
}

if (!function_exists('botCargoPressureMark')) {
    /**
     * Records a "cap loot" skip event in bot_quirks.cargo_pressure so that
     * subsequent build decisions can prioritize big cargos.
     *
     * Shape:
     *   bot_quirks.cargo_pressure = {
     *     "skips": int,                 // total skips inside this window
     *     "last_signal_at": int,        // unix ts of last cap-loot skip
     *     "expires_at": int             // ts after which we treat it as fresh again
     *   }
     *
     * Each new event refreshes expires_at to now + TTL. If the previous
     * window already expired, skips count resets to 1. Mutates $state.
     *
     * @param array<string, mixed> $state
     */
    function botCargoPressureMark(array &$state, int $now): void
    {
        if (!is_array($state['bot_quirks'] ?? null)) {
            $state['bot_quirks'] = [];
        }
        $quirks = $state['bot_quirks'];
        $cp = is_array($quirks['cargo_pressure'] ?? null) ? $quirks['cargo_pressure'] : [];
        $expiresAt = (int) ($cp['expires_at'] ?? 0);
        $skips = (int) ($cp['skips'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < $now) {
            $skips = 0;
        }
        $cp['skips'] = $skips + 1;
        $cp['last_signal_at'] = $now;
        $cp['expires_at'] = $now + BOT_CARGO_PRESSURE_TTL_SECONDS;
        $state['bot_quirks']['cargo_pressure'] = $cp;

        // Lifetime counter (never expires): every cap-loot skip the bot
        // emits is counted here so the admin panel can spot bots that
        // chronically lack cargo, even after a signal already expired.
        $metrics = is_array($quirks['metrics'] ?? null) ? $quirks['metrics'] : [];
        $metrics['cargo_pressure_signals'] = (int) ($metrics['cargo_pressure_signals'] ?? 0) + 1;
        $state['bot_quirks']['metrics'] = $metrics;
    }
}

if (!function_exists('botCargoPressureRecordQueued')) {
    /**
     * Counter for "how many times did the bot pick a big-cargo build action
     * while cargo_pressure was active". Useful to confirm the signal is
     * actually translating into production. Increments
     * bot_quirks.metrics.big_cargos_queued_under_pressure.
     *
     * @param array<string, mixed> $state
     */
    function botCargoPressureRecordQueued(array &$state, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        if (!is_array($state['bot_quirks'] ?? null)) {
            $state['bot_quirks'] = [];
        }
        $metrics = is_array($state['bot_quirks']['metrics'] ?? null)
            ? $state['bot_quirks']['metrics']
            : [];
        $metrics['big_cargos_queued_under_pressure']
            = (int) ($metrics['big_cargos_queued_under_pressure'] ?? 0) + $amount;
        $state['bot_quirks']['metrics'] = $metrics;
    }
}

if (!function_exists('botCargoPressureActive')) {
    /**
     * Returns true when the bot has recent "cap loot" skip signals and the
     * window has not yet expired. Used by the build module to lift the
     * weight of big cargos in upcoming loops.
     *
     * @param array<string, mixed> $state
     */
    function botCargoPressureActive(array $state, int $now): bool
    {
        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
        $cp = is_array($quirks['cargo_pressure'] ?? null) ? $quirks['cargo_pressure'] : null;
        if ($cp === null) {
            return false;
        }
        $expiresAt = (int) ($cp['expires_at'] ?? 0);
        $skips = (int) ($cp['skips'] ?? 0);
        if ($expiresAt <= 0 || $expiresAt < $now) {
            return false;
        }

        return $skips >= BOT_CARGO_PRESSURE_MIN_SKIPS_TO_PRIORITIZE;
    }
}

// ----------------------------------------------------------------------------
// Bot alliance module (create / apply / accept / leave / kick / dissolve).
// ----------------------------------------------------------------------------

if (!defined('BOT_ALLIANCE_POST_ACTION_COOLDOWN_SECONDS')) {
    define('BOT_ALLIANCE_POST_ACTION_COOLDOWN_SECONDS', 30);
}

if (!defined('BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS')) {
    define('BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS', 3600);
}

if (!defined('BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS')) {
    define('BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS', 86400 * 14);
}

if (!defined('BOT_ALLIANCE_OWNER_TRANSFER_REVIEW_COOLDOWN_SECONDS')) {
    define('BOT_ALLIANCE_OWNER_TRANSFER_REVIEW_COOLDOWN_SECONDS', 300);
}

if (!defined('BOT_ALLIANCE_BETTER_ALT_MIN_MEMBERS')) {
    // Minimum member_count of an alternative alliance the bot would
    // accept as "clearly better" while still within its own
    // dissolve grace window. If at least one candidate alliance the bot
    // can apply to has >= this many members, a lone founder dissolves
    // immediately instead of waiting for the grace to expire.
    define('BOT_ALLIANCE_BETTER_ALT_MIN_MEMBERS', 2);
}

// Bots don't apply to alliances with more than this many members. Big
// rosters tend to be human-led and saturated, so an automated bot
// application is noise rather than signal.
if (!defined('BOT_ALLIANCE_APPLY_MAX_MEMBERS')) {
    define('BOT_ALLIANCE_APPLY_MAX_MEMBERS', 10);
}

// ----------------------------------------------------------------------------
// Ally logistics (transport + templated resource requests). See
// scripts/bot_lib/ally_logistics.php and planes/bot_ally_logistics_acs_diplomacy.md
// ----------------------------------------------------------------------------

if (!defined('BOT_ALLY_TRANSPORT_MAX_PER_LOOP')) {
    define('BOT_ALLY_TRANSPORT_MAX_PER_LOOP', 2);
}

if (!defined('BOT_ALLY_TRANSPORT_MIN_ALLY_NEED')) {
    // Minimum total deficit (metal+crystal+deuterium) on an ally bot planet
    // before we consider shipping from ourselves.
    define('BOT_ALLY_TRANSPORT_MIN_ALLY_NEED', 25_000);
}

if (!defined('BOT_ALLY_MSG_TO_HUMAN_COOLDOWN_SECONDS')) {
    define('BOT_ALLY_MSG_TO_HUMAN_COOLDOWN_SECONDS', 43_200); // 12h
}

if (!defined('BOT_ALLY_MSG_TO_HUMAN_MIN_DEFICIT')) {
    // Sum of own queue deficits required before bothering human allies.
    define('BOT_ALLY_MSG_TO_HUMAN_MIN_DEFICIT', 150_000);
}

if (!defined('BOT_ALLY_HUMAN_REQ_PER_RESOURCE_CAP')) {
    define('BOT_ALLY_HUMAN_REQ_PER_RESOURCE_CAP', 50_000_000);
}

// ----------------------------------------------------------------------------
// Bot ACS attack module. See scripts/bot_lib/acs_attack.php and
// planes/bot_acs_module.md.
//
// ACS is gated by xgp_alliance_diplomacy: a bot only opens or joins an
// ACS group whose target user belongs to an alliance currently at war
// with the bot's own alliance.
// ----------------------------------------------------------------------------

if (!defined('BOT_ACS_MAX_OPEN_GROUPS_PER_BOT')) {
    define('BOT_ACS_MAX_OPEN_GROUPS_PER_BOT', 1);
}

if (!defined('BOT_ACS_LEADER_EXTRA_SECONDS')) {
    // Buffer added on top of the leader's own travel time so allies can
    // sync up their own arrivals. Lower bound; the leader will still
    // pick a real travel duration that already covers its own flight.
    define('BOT_ACS_LEADER_EXTRA_SECONDS', 600); // 10 min
}

if (!defined('BOT_ACS_JOINER_MIN_SLACK_SECONDS')) {
    // A joiner only enters a group if (T_attack - now) >= my_duration + slack.
    // Slack avoids racing the game engine's mission tick.
    define('BOT_ACS_JOINER_MIN_SLACK_SECONDS', 60);
}

if (!defined('BOT_ACS_GROUP_MAX_MEMBERS')) {
    // Hard cap on members in a single ACS group (matches the human UI limit).
    define('BOT_ACS_GROUP_MAX_MEMBERS', 5);
}

if (!defined('BOT_ACS_LEADER_MIN_FLEET_VALUE')) {
    // Leader minimum combat fleet value at the source planet before it
    // even considers opening an ACS group. Keeps tiny attackers from
    // creating groups they can't bring meaningful fleet to.
    define('BOT_ACS_LEADER_MIN_FLEET_VALUE', 100_000);
}

// ----------------------------------------------------------------------------
// Bot diplomacy module. See scripts/bot_lib/diplomacy.php and
// planes/bot_diplomacy_module.md.
// ----------------------------------------------------------------------------

// Minimum pressure delta added per attack, even if the loss/loot
// estimation is below this. Avoids long sequences of cheap attacks not
// being noticed.
if (!defined('BOT_DIPLO_PRESSURE_PER_ATTACK_MIN')) {
    define('BOT_DIPLO_PRESSURE_PER_ATTACK_MIN', 1_000);
}

// Pressure threshold above which an alliance owner bot starts evaluating
// `declare_war` against the attacker.
if (!defined('BOT_DIPLO_PRESSURE_WAR_THRESHOLD')) {
    define('BOT_DIPLO_PRESSURE_WAR_THRESHOLD', 1_000_000);
}

// Linear decay applied per hour. Without further activity, a saturated
// pressure value (~THRESHOLD) takes roughly THRESHOLD / DECAY hours to
// reach zero. Defaults imply ~20 hours of memory.
if (!defined('BOT_DIPLO_PRESSURE_DECAY_PER_HOUR')) {
    define('BOT_DIPLO_PRESSURE_DECAY_PER_HOUR', 50_000);
}

// When pressure crosses the threshold, this is the probability (out of
// 100) that an evaluating bot owner declares war on a given tick. Keeps
// declarations deterministic-ish but not robotic.
if (!defined('BOT_DIPLO_AUTO_DECLARE_CHANCE_X100')) {
    define('BOT_DIPLO_AUTO_DECLARE_CHANCE_X100', 80);
}

// Peace bribe ratio. If a peace proposal carries resources whose total
// >= damage_attacker_to_victim * RATIO/100, the peace is auto-signed.
if (!defined('BOT_DIPLO_PEACE_BRIBE_RATIO_X100')) {
    define('BOT_DIPLO_PEACE_BRIBE_RATIO_X100', 60);
}

// Discount applied to the rentability threshold of bot attacks when the
// target belongs to an alliance the bot is at war with. Threshold *
// (1 - DISCOUNT/100).
if (!defined('BOT_DIPLO_WAR_RENTABILITY_DISCOUNT_X100')) {
    define('BOT_DIPLO_WAR_RENTABILITY_DISCOUNT_X100', 20);
}

// Cooldown applied to a NAP-breaking alliance: it can't propose/accept
// peace nor NAP, nor declare war, for this many seconds. 24h by default.
if (!defined('BOT_DIPLO_BREAK_NAP_COOLDOWN_SECONDS')) {
    define('BOT_DIPLO_BREAK_NAP_COOLDOWN_SECONDS', 86_400);
}

// Default duration for a NAP if the proposer doesn't specify one.
if (!defined('BOT_DIPLO_NAP_DEFAULT_DURATION_SECONDS')) {
    define('BOT_DIPLO_NAP_DEFAULT_DURATION_SECONDS', 604_800); // 7 days
}

// Minimum war age before a bot alliance may send an inbox peace proposal.
if (!defined('BOT_DIPLO_PEACE_MIN_WAR_AGE_SECONDS')) {
    define('BOT_DIPLO_PEACE_MIN_WAR_AGE_SECONDS', 10_800); // 3h
}

// After proposing peace to an enemy, wait this long before offering again.
if (!defined('BOT_DIPLO_PEACE_PROPOSE_COOLDOWN_SECONDS')) {
    define('BOT_DIPLO_PEACE_PROPOSE_COOLDOWN_SECONDS', 600); // 10 min
}

// Losing side: propose peace when damage received >= max(FLOOR, MULT * damage dealt).
if (!defined('BOT_DIPLO_PEACE_LOSS_DAMAGE_FLOOR')) {
    define('BOT_DIPLO_PEACE_LOSS_DAMAGE_FLOOR', 50_000);
}
if (!defined('BOT_DIPLO_PEACE_LOSS_MULT_NUM')) {
    define('BOT_DIPLO_PEACE_LOSS_MULT_NUM', 2);
}

// Seconds between diplomacy reviews by the same alliance owner bot.
if (!defined('BOT_DIPLO_LEADER_REVIEW_COOLDOWN')) {
    define('BOT_DIPLO_LEADER_REVIEW_COOLDOWN', 30);
}

if (!defined('BOT_ALLIANCE_ANTI_REBOUND_SECONDS')) {
    // After a bot dissolves its own alliance, it cannot create a new
    // one for this many seconds. During the window the bot must apply
    // to an existing alliance (or stay idle). Prevents the
    // "dissolve → recreate → dissolve" loop.
    define('BOT_ALLIANCE_ANTI_REBOUND_SECONDS', 3600);
}

if (!defined('BOT_ALLIANCE_OWNER_REVIEW_COOLDOWN_SECONDS')) {
    define('BOT_ALLIANCE_OWNER_REVIEW_COOLDOWN_SECONDS', 10);
}

if (!defined('BOT_ALLIANCE_NEW_MEMBER_GRACE_SECONDS')) {
    define('BOT_ALLIANCE_NEW_MEMBER_GRACE_SECONDS', 3600);
}

if (!defined('BOT_ALLIANCE_MEMBER_REVIEW_CHANCE_X100')) {
    define('BOT_ALLIANCE_MEMBER_REVIEW_CHANCE_X100', 2);
}

if (!defined('BOT_ALLIANCE_APPLY_PENDING_TTL_SECONDS')) {
    define('BOT_ALLIANCE_APPLY_PENDING_TTL_SECONDS', 86400 * 3);
}

if (!defined('BOT_ALLIANCE_REJECTED_BY_TTL_SECONDS')) {
    define('BOT_ALLIANCE_REJECTED_BY_TTL_SECONDS', 86400 * 2);
}

if (!defined('BOT_ALLIANCE_DESPERATE_AFTER_SECONDS')) {
    define('BOT_ALLIANCE_DESPERATE_AFTER_SECONDS', 86400 * 3);
}

if (!defined('BOT_ALLIANCE_DESPERATE_MIN_FAILED_STREAK')) {
    define('BOT_ALLIANCE_DESPERATE_MIN_FAILED_STREAK', 3);
}

if (!defined('BOT_ALLIANCE_MIN_POINTS_TO_CREATE')) {
    define('BOT_ALLIANCE_MIN_POINTS_TO_CREATE', 3000);
}

if (!defined('BOT_ALLIANCE_MIN_AGE_HOURS_TO_CREATE')) {
    define('BOT_ALLIANCE_MIN_AGE_HOURS_TO_CREATE', 48);
}

if (!defined('BOT_ALLIANCE_MAX_BOT_LED')) {
    define('BOT_ALLIANCE_MAX_BOT_LED', 8);
}

if (!defined('BOT_ALLIANCE_FOUND_MIN_SELF_SCORE')) {
    define('BOT_ALLIANCE_FOUND_MIN_SELF_SCORE', 7.0);
}

if (!function_exists('botCapBuildingAction')) {
    /**
     * Returns null if the action is safe, or a string reason if it must be
     * skipped because of caps.
     *
     * @param array<string, mixed> $action
     * @param array<string, mixed> $planet
     */
    function botCapBuildingAction(array $action, array $planet): ?string
    {
        $type = (string) ($action['type'] ?? '');
        $column = (string) ($action['column'] ?? '');
        $amount = (int) ($action['amount'] ?? 1);

        if ($type === 'building') {
            $current = (int) ($planet[$column] ?? 0);
            if ($current >= BOT_CAP_BUILDING_LEVEL) {
                return "{$column}@" . $current . ' >= cap ' . BOT_CAP_BUILDING_LEVEL;
            }

            return null;
        }

        if ($type === 'ship' || $type === 'defense') {
            if ($column === 'ship_mining_drill') {
                $headroom = botMiningDrillBuildableRemaining($planet);
                if ($headroom <= 0) {
                    return "{$column}@mining drill planet cap " . BOT_MINING_DRILL_LIMIT_PER_PLANET;
                }
                if ($amount > $headroom) {
                    return "{$column}@requested {$amount} exceeds drill headroom {$headroom}";
                }
            }
            $current = (int) ($planet[$column] ?? 0);
            if ($current + $amount > BOT_CAP_UNIT_PER_TYPE) {
                $allowed = max(0, BOT_CAP_UNIT_PER_TYPE - $current);
                if ($allowed <= 0) {
                    return "{$column}@" . $current . ' >= cap ' . BOT_CAP_UNIT_PER_TYPE;
                }
                $action['amount'] = $allowed; // caller can read mutated copy.
            }

            return null;
        }

        return null;
    }
}

if (!function_exists('botCapClampUnitAmount')) {
    /**
     * Returns the largest amount that respects BOTH:
     *  - BOT_CAP_UNIT_PER_TYPE (absolute ceiling per ship/defense column).
     *  - BOT_CAP_UNITS_PER_QUEUE_ENTRY (per-queue-row cap, 9999 by default,
     *    matching the in-game shipyard limit). The bot can still queue more
     *    units across multiple ticks; each enqueue just stays at most 9999.
     *
     * Returns 0 if already at/above the absolute cap.
     */
    function botCapClampUnitAmount(int $currentCount, int $desiredAmount): int
    {
        if ($currentCount >= BOT_CAP_UNIT_PER_TYPE) {
            return 0;
        }
        $remainingHeadroom = BOT_CAP_UNIT_PER_TYPE - $currentCount;

        return max(0, min($desiredAmount, $remainingHeadroom, BOT_CAP_UNITS_PER_QUEUE_ENTRY));
    }
}

if (!function_exists('botCapResearchTargetReached')) {
    function botCapResearchTargetReached(int $currentLevel): bool
    {
        return $currentLevel >= BOT_CAP_RESEARCH_LEVEL;
    }
}

if (!function_exists('botCapBuildingTargetReached')) {
    function botCapBuildingTargetReached(int $currentLevel): bool
    {
        return $currentLevel >= BOT_CAP_BUILDING_LEVEL;
    }
}

if (!function_exists('botCapCostExceeded')) {
    /**
     * Returns true if a given total cost would explode our value ceiling.
     * Used to avoid trying to enqueue absurd actions on already-degenerate
     * planets / users (e.g. plasma 60+ which costs 10^20+).
     *
     * @param array{metal:int|float, crystal:int|float, deuterium:int|float} $cost
     */
    function botCapCostExceeded(array $cost): bool
    {
        $total = (float) ($cost['metal'] ?? 0)
            + (float) ($cost['crystal'] ?? 0)
            + (float) ($cost['deuterium'] ?? 0);

        return $total > BOT_CAP_RESOURCE_VALUE;
    }
}

if (!function_exists('botSchemaCheck')) {
    /**
     * Verifies the database schema has all the columns/tables the bot needs,
     * with the correct types. Returns a list of human-readable error messages
     * (empty when everything is fine).
     *
     * Called at bot startup. If errors are returned, the bot prints them and
     * exits, so admins know exactly which migration script to run.
     *
     * @return array<int, string>
     */
    function botSchemaCheck(mysqli $db, string $prefix): array
    {
        $errors = [];

        // ---- Required perhour bigint columns ---------------------------------
        $requiredBigints = [
            'planets' => [
                'planet_metal_perhour',
                'planet_crystal_perhour',
                'planet_deuterium_perhour',
                'planet_energy_used',
            ],
        ];
        foreach ($requiredBigints as $shortTable => $cols) {
            $fullTable = $prefix . $shortTable;
            foreach ($cols as $col) {
                $type = botSchemaColumnType($db, $fullTable, $col);
                if ($type === null) {
                    $errors[] = "Missing column `{$col}` on `{$fullTable}`. "
                        . 'Run: php scripts/migrate_widen_planet_perhour_columns.php';

                    continue;
                }
                if ($type !== 'bigint') {
                    $errors[] = "Column `{$fullTable}`.`{$col}` is `{$type}`, expected `bigint`. "
                        . 'Run: php scripts/migrate_widen_planet_perhour_columns.php '
                        . '(otherwise high-level mines will overflow and the game will crash on planet update).';
                }
            }
        }

        // ---- bot_state table ----------------------------------------------
        $botStateTable = $prefix . 'bot_state';
        if (!botSchemaTableExists($db, $botStateTable)) {
            $errors[] = "Missing table `{$botStateTable}`. "
                . 'Run: php scripts/migrate_create_bot_state.php '
                . '(this table stores per-bot personality/state and is required for personalized bots).';
        } else {
            $criticalCols = [
                'bot_user_id', 'bot_seed', 'bot_archetype', 'bot_personality',
                'bot_personal_targets', 'bot_defense_recipe', 'bot_research_order',
                'bot_planet_roles', 'bot_quirks', 'bot_current_focus',
            ];
            foreach ($criticalCols as $col) {
                if (botSchemaColumnType($db, $botStateTable, $col) === null) {
                    $errors[] = "Column `{$botStateTable}`.`{$col}` missing. "
                        . 'Drop the table and re-run: php scripts/migrate_create_bot_state.php';
                }
            }
        }

        return $errors;
    }
}

if (!function_exists('botSchemaTableExists')) {
    function botSchemaTableExists(mysqli $db, string $tableName): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS total FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ((int) ($row['total'] ?? 0)) > 0;
    }
}

if (!function_exists('botSchemaColumnType')) {
    function botSchemaColumnType(mysqli $db, string $tableName, string $columnName): ?string
    {
        $stmt = $db->prepare(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        $type = $row['DATA_TYPE'] ?? null;

        return is_string($type) ? strtolower($type) : null;
    }
}

if (!function_exists('botSchemaAssertOrExit')) {
    /**
     * Convenience wrapper used at bot startup. Calls botSchemaCheck and, if
     * any error is returned, prints the full list and exits with code 1.
     */
    function botSchemaAssertOrExit(mysqli $db, string $prefix): void
    {
        $errors = botSchemaCheck($db, $prefix);
        if (empty($errors)) {
            return;
        }

        fwrite(STDERR, "================================================================\n");
        fwrite(STDERR, "Bot startup aborted: database schema is not ready.\n");
        fwrite(STDERR, "================================================================\n");
        foreach ($errors as $err) {
            fwrite(STDERR, " - {$err}\n");
        }
        fwrite(STDERR, "================================================================\n");
        fwrite(STDERR, "Apply the listed migrations and re-run the bot.\n");
        fwrite(STDERR, "================================================================\n");
        exit(1);
    }
}

if (!function_exists('botFleetInsertTransactional')) {
    /**
     * Atomically launches a fleet from a source planet: the closure $work
     * does the INSERT into xgp_fleets PLUS any UPDATEs on ships/planets,
     * and this helper wraps them in a single transaction with row-level
     * locks on the source planet/ships rows.
     *
     * Guarantees:
     *   1. All writes commit together. If $work returns null/0 or throws,
     *      the transaction is rolled back; no fleet ghost can survive a
     *      mid-flight failure between INSERT and the ship/planet updates.
     *   2. Concurrent workers writing to the same source planet
     *      serialize via SELECT ... FOR UPDATE locks on planet_id and
     *      ship_planet_id. So the closure can read ship counts or
     *      deuterium without racing with another bot process or the
     *      game engine processing a returning fleet on the same planet.
     *
     * The closure must:
     *   - Return a positive int (fleet_id) on success → triggers COMMIT.
     *   - Return null or any non-positive int → triggers ROLLBACK; helper
     *     returns null.
     *   - Throwing an exception is allowed and triggers ROLLBACK; helper
     *     swallows the exception and returns null (keeps the bot loop
     *     alive even on hard DB errors).
     *
     * Fallback: if $db->begin_transaction() refuses (autocommit off in
     * the connection, MySQL in a weird state, etc.) the helper invokes
     * $work *without* a transaction wrapping. This is strictly safer
     * than aborting; the legacy behaviour is preserved.
     *
     * @param callable(mysqli):?int $work Closure that performs INSERT + updates and returns the new fleet_id or null on caller-side abort.
     * @return int|null fleet_id on commit, null on rollback or fatal error.
     */
    function botFleetInsertTransactional(
        mysqli $db,
        string $prefix,
        int $sourcePlanetId,
        callable $work
    ): ?int {
        if ($sourcePlanetId <= 0) {
            return null;
        }

        $beginOk = false;

        try {
            $beginOk = (bool) $db->begin_transaction();
        } catch (\Throwable $e) {
            $beginOk = false;
        }

        if (!$beginOk) {
            // No transaction available: run the closure raw. Same risk as
            // the pre-helper code, but never worse.
            $fallbackId = $work($db);

            return (is_int($fallbackId) && $fallbackId > 0) ? $fallbackId : null;
        }

        try {
            // Row locks: serialize with concurrent updates to the same
            // source planet (e.g. game engine processing a returning
            // fleet of ours, or another worker for the same bot).
            $resPlanet = $db->query(
                "SELECT 1 FROM `{$prefix}planets` "
                . "WHERE `planet_id` = {$sourcePlanetId} LIMIT 1 FOR UPDATE"
            );
            if ($resPlanet instanceof mysqli_result) {
                $resPlanet->free();
            }
            $resShips = $db->query(
                "SELECT 1 FROM `{$prefix}ships` "
                . "WHERE `ship_planet_id` = {$sourcePlanetId} LIMIT 1 FOR UPDATE"
            );
            if ($resShips instanceof mysqli_result) {
                $resShips->free();
            }

            $fleetId = $work($db);
            if (!is_int($fleetId) || $fleetId <= 0) {
                $db->rollback();

                return null;
            }

            $db->commit();

            return $fleetId;
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $e2) {
                // Ignored: an unrecoverable connection error here would
                // also kill the bot loop; better swallow and return null.
            }

            return null;
        }
    }
}
