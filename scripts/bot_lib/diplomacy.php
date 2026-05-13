<?php

declare(strict_types=1);

/**
 * Alliance diplomacy: thin SQL helpers around xgp_alliance_diplomacy.
 *
 * Pairs are stored normalised with alliance_a <= alliance_b. War is
 * symmetric, so callers should not care about ordering.
 *
 * For now the bot only READS this table (war state gates ACS attacks).
 * Declaration / acceptance flows live in the bot diplomacy module that
 * arrives in the next phase. The table exists already so callers don't
 * need to be re-written when that lands.
 */
if (!function_exists('botDiplomacyNormalisePair')) {
    /**
     * Returns [min, max] for a pair so SELECTs hit the unique key.
     *
     * @return array{0:int,1:int}|null null if either side is non-positive.
     */
    function botDiplomacyNormalisePair(int $a, int $b): ?array
    {
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return null;
        }
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }

        return [$a, $b];
    }
}

if (!function_exists('botDiplomacyIsAtWar')) {
    /**
     * True if alliances $a and $b have an active 'war' row (not expired).
     */
    function botDiplomacyIsAtWar(mysqli $db, string $prefix, int $a, int $b): bool
    {
        $pair = botDiplomacyNormalisePair($a, $b);
        if ($pair === null) {
            return false;
        }
        $tbl = $prefix . 'alliance_diplomacy';
        $now = time();
        $sql = "SELECT 1
                FROM `{$tbl}`
                WHERE `alliance_a` = {$pair[0]}
                  AND `alliance_b` = {$pair[1]}
                  AND `status` = 'war'
                  AND (`expires_at` = 0 OR `expires_at` > {$now})
                LIMIT 1";
        $res = $db->query($sql);
        if (!$res) {
            return false;
        }
        $found = $res->fetch_assoc() !== null;
        $res->free();

        return $found;
    }
}

if (!function_exists('botDiplomacyListEnemyAlliances')) {
    /**
     * All alliance ids currently at war with $myAllyId.
     *
     * @return list<int>
     */
    function botDiplomacyListEnemyAlliances(mysqli $db, string $prefix, int $myAllyId): array
    {
        if ($myAllyId <= 0) {
            return [];
        }
        $tbl = $prefix . 'alliance_diplomacy';
        $now = time();
        $sql = "SELECT
                    CASE
                        WHEN `alliance_a` = {$myAllyId} THEN `alliance_b`
                        ELSE `alliance_a`
                    END AS `other_id`
                FROM `{$tbl}`
                WHERE (`alliance_a` = {$myAllyId} OR `alliance_b` = {$myAllyId})
                  AND `status` = 'war'
                  AND (`expires_at` = 0 OR `expires_at` > {$now})";
        $res = $db->query($sql);
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $other = (int) ($row['other_id'] ?? 0);
            if ($other > 0) {
                $out[] = $other;
            }
        }
        $res->free();

        return array_values(array_unique($out));
    }
}

if (!function_exists('botDiplomacyListEnemyUserIds')) {
    /**
     * All user ids belonging to alliances at war with $myAllyId.
     *
     * @return list<int>
     */
    function botDiplomacyListEnemyUserIds(mysqli $db, string $prefix, int $myAllyId): array
    {
        $enemies = botDiplomacyListEnemyAlliances($db, $prefix, $myAllyId);
        if ($enemies === []) {
            return [];
        }
        $u = $prefix . 'users';
        $idList = implode(',', array_map('intval', $enemies));
        $res = $db->query(
            "SELECT `user_id` FROM `{$u}` WHERE `user_ally_id` IN ({$idList})"
        );
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid > 0) {
                $out[] = $uid;
            }
        }
        $res->free();

        return $out;
    }
}

if (!function_exists('botDiplomacyPeaceLossThresholdDamageReceived')) {
    /**
     * Damage the opponent must have inflicted on us before we count as
     * "losing" enough to seek peace (same rule as inbox peace proposals).
     */
    function botDiplomacyPeaceLossThresholdDamageReceived(int $myDamageDealt): int
    {
        $floor = defined('BOT_DIPLO_PEACE_LOSS_DAMAGE_FLOOR')
            ? (int) BOT_DIPLO_PEACE_LOSS_DAMAGE_FLOOR
            : 50_000;
        $mult = defined('BOT_DIPLO_PEACE_LOSS_MULT_NUM')
            ? (int) BOT_DIPLO_PEACE_LOSS_MULT_NUM
            : 2;

        return max($floor, $myDamageDealt * $mult);
    }
}

if (!function_exists('botDiplomacyAllianceIsLosingSideOfWar')) {
    /**
     * True if $myAllyId is the losing side for this war row (damage columns
     * are stored on the normalised alliance_a / alliance_b pair).
     */
    function botDiplomacyAllianceIsLosingSideOfWar(
        int $myAllyId,
        int $allianceA,
        int $allianceB,
        int $damageAtoB,
        int $damageBtoA
    ): bool {
        if ($myAllyId !== $allianceA && $myAllyId !== $allianceB) {
            return false;
        }
        $myDealt = $myAllyId === $allianceA ? $damageAtoB : $damageBtoA;
        $myReceived = $myAllyId === $allianceA ? $damageBtoA : $damageAtoB;
        $threshold = botDiplomacyPeaceLossThresholdDamageReceived($myDealt);

        return $myReceived >= $threshold;
    }
}

if (!function_exists('botDiplomacyPeaceBribeOfferedByLoser')) {
    /**
     * Resource "offer" amount the losing proposer attaches (same formula as
     * bot inbox peace markers). Not deducted from any treasury in-game.
     */
    function botDiplomacyPeaceBribeOfferedByLoser(
        int $myAllyId,
        int $allianceA,
        int $allianceB,
        int $damageAtoB,
        int $damageBtoA
    ): int {
        if ($myAllyId !== $allianceA && $myAllyId !== $allianceB) {
            return 0;
        }
        $myReceived = $myAllyId === $allianceA ? $damageBtoA : $damageAtoB;
        $ratio = defined('BOT_DIPLO_PEACE_BRIBE_RATIO_X100')
            ? (int) BOT_DIPLO_PEACE_BRIBE_RATIO_X100
            : 60;

        return (int) max(0, ceil($myReceived * $ratio / 100.0));
    }
}

if (!function_exists('botDiplomacyPeaceBribeRequiredForReceiver')) {
    /**
     * Minimum offered amount for the receiver (winner) to accept peace,
     * from the war row damage columns and the two alliance ids.
     */
    function botDiplomacyPeaceBribeRequiredForReceiver(
        int $receiverAllianceId,
        int $proposerAllianceId,
        int $damageAtoB,
        int $damageBtoA
    ): int {
        $pair = botDiplomacyNormalisePair($receiverAllianceId, $proposerAllianceId);
        if ($pair === null) {
            return PHP_INT_MAX;
        }
        $damageWeInflicted = $receiverAllianceId === $pair[0] ? $damageAtoB : $damageBtoA;
        $ratio = defined('BOT_DIPLO_PEACE_BRIBE_RATIO_X100')
            ? (int) BOT_DIPLO_PEACE_BRIBE_RATIO_X100
            : 60;

        return (int) ceil($damageWeInflicted * $ratio / 100.0);
    }
}

if (!function_exists('botDiplomacyUpsertWar')) {
    /**
     * Convenience for tests/admin SQL: upsert a war between two alliances.
     * The bot never calls this; the diplomacy phase replaces it with
     * `botDiplomacyDeclareWar` once the proper actor pipeline lands.
     */
    function botDiplomacyUpsertWar(
        mysqli $db,
        string $prefix,
        int $a,
        int $b,
        int $expiresAt = 0
    ): bool {
        $pair = botDiplomacyNormalisePair($a, $b);
        if ($pair === null) {
            return false;
        }
        $tbl = $prefix . 'alliance_diplomacy';
        $now = time();
        $sql = "INSERT INTO `{$tbl}` (`alliance_a`, `alliance_b`, `status`, `since`, `expires_at`, `declared_by`, `last_action_at`)
                VALUES ({$pair[0]}, {$pair[1]}, 'war', {$now}, {$expiresAt}, {$pair[0]}, {$now})
                ON DUPLICATE KEY UPDATE
                    `status` = 'war',
                    `since` = VALUES(`since`),
                    `expires_at` = VALUES(`expires_at`),
                    `last_action_at` = VALUES(`last_action_at`)";

        return (bool) $db->query($sql);
    }
}

// ---------------------------------------------------------------------------
// Pressure
// ---------------------------------------------------------------------------

if (!function_exists('botDiplomacyComputeDecay')) {
    /**
     * Linear decay: pressure[t] = max(0, pressure - rate_per_hour * hours).
     * Returns the new pressure value without writing it.
     */
    function botDiplomacyComputeDecay(int $pressure, int $lastDecayAt, int $now, ?int $ratePerHour = null): int
    {
        if ($pressure <= 0) {
            return 0;
        }
        if ($lastDecayAt <= 0 || $lastDecayAt >= $now) {
            return $pressure;
        }
        $rate = $ratePerHour ?? (defined('BOT_DIPLO_PRESSURE_DECAY_PER_HOUR')
            ? (int) BOT_DIPLO_PRESSURE_DECAY_PER_HOUR
            : 50_000);
        if ($rate <= 0) {
            return $pressure;
        }
        $elapsed = $now - $lastDecayAt;
        $loss = (int) floor(($rate * $elapsed) / 3600.0);

        return max(0, $pressure - $loss);
    }
}

if (!function_exists('botDiplomacyRecordAttack')) {
    /**
     * Records that `attackerAllianceId` hit `victimAllianceId` with the
     * given attack value (metal+crystal lost+looted is a sensible unit).
     *
     * - Applies linear decay to the previous pressure value before adding
     *   the new delta, so the row stays current without a global decay
     *   pass.
     * - Skips if attacker_ally == victim_ally or any is zero (no
     *   diplomacy with stateless players).
     *
     * Returns the new pressure value or null on failure.
     */
    function botDiplomacyRecordAttack(
        mysqli $db,
        string $prefix,
        int $attackerAllianceId,
        int $victimAllianceId,
        int $attackValue,
        ?int $now = null
    ): ?int {
        if ($attackerAllianceId <= 0 || $victimAllianceId <= 0
            || $attackerAllianceId === $victimAllianceId
        ) {
            return null;
        }
        $now = $now ?? time();
        $min = defined('BOT_DIPLO_PRESSURE_PER_ATTACK_MIN')
            ? (int) BOT_DIPLO_PRESSURE_PER_ATTACK_MIN
            : 1_000;
        $delta = max($min, (int) $attackValue);
        $tbl = $prefix . 'alliance_diplomacy_pressure';

        try {
            $db->begin_transaction();
            $sel = $db->query(
                "SELECT `pressure`, `last_decay_at` FROM `{$tbl}`
                 WHERE `victim_alliance_id` = {$victimAllianceId}
                   AND `attacker_alliance_id` = {$attackerAllianceId}
                 LIMIT 1
                 FOR UPDATE"
            );
            $current = 0;
            $lastDecay = $now;
            if ($sel && ($row = $sel->fetch_assoc()) !== null) {
                $current = (int) ($row['pressure'] ?? 0);
                $lastDecay = (int) ($row['last_decay_at'] ?? $now);
            }
            if ($sel) {
                $sel->free();
            }
            $decayed = botDiplomacyComputeDecay($current, $lastDecay, $now);
            $newPressure = $decayed + $delta;

            $ok = $db->query(
                "INSERT INTO `{$tbl}` (`victim_alliance_id`, `attacker_alliance_id`, `pressure`, `last_attack_at`, `last_decay_at`)
                 VALUES ({$victimAllianceId}, {$attackerAllianceId}, {$newPressure}, {$now}, {$now})
                 ON DUPLICATE KEY UPDATE
                    `pressure` = VALUES(`pressure`),
                    `last_attack_at` = VALUES(`last_attack_at`),
                    `last_decay_at` = VALUES(`last_decay_at`)"
            );
            if (!$ok) {
                $db->rollback();

                return null;
            }
            $db->commit();

            return $newPressure;
        } catch (Throwable $e) {
            $db->rollback();

            return null;
        }
    }
}

if (!function_exists('botDiplomacyDecayPressure')) {
    /**
     * Periodic global decay pass. Safe to call from any bot loop. Returns
     * the number of rows deleted (pressure -> 0).
     */
    function botDiplomacyDecayPressure(mysqli $db, string $prefix, ?int $now = null): int
    {
        $now = $now ?? time();
        $tbl = $prefix . 'alliance_diplomacy_pressure';
        $rate = defined('BOT_DIPLO_PRESSURE_DECAY_PER_HOUR')
            ? (int) BOT_DIPLO_PRESSURE_DECAY_PER_HOUR
            : 50_000;
        if ($rate <= 0) {
            return 0;
        }

        $ok = $db->query(
            "UPDATE `{$tbl}` SET
                `pressure` = GREATEST(
                    0,
                    `pressure` - CAST(({$rate} * ({$now} - `last_decay_at`)) / 3600 AS UNSIGNED)
                ),
                `last_decay_at` = {$now}
             WHERE `last_decay_at` < {$now}"
        );
        if (!$ok) {
            return 0;
        }
        $del = $db->query("DELETE FROM `{$tbl}` WHERE `pressure` = 0");
        if (!$del) {
            return 0;
        }

        return (int) $db->affected_rows;
    }
}

if (!function_exists('botDiplomacyListPressureForVictim')) {
    /**
     * @return list<array{attacker_alliance_id:int, pressure:int, last_attack_at:int}>
     */
    function botDiplomacyListPressureForVictim(mysqli $db, string $prefix, int $victimAllianceId): array
    {
        if ($victimAllianceId <= 0) {
            return [];
        }
        $tbl = $prefix . 'alliance_diplomacy_pressure';
        $res = $db->query(
            "SELECT `attacker_alliance_id`, `pressure`, `last_attack_at`
             FROM `{$tbl}`
             WHERE `victim_alliance_id` = {$victimAllianceId}
             ORDER BY `pressure` DESC"
        );
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'attacker_alliance_id' => (int) $row['attacker_alliance_id'],
                'pressure' => (int) $row['pressure'],
                'last_attack_at' => (int) $row['last_attack_at'],
            ];
        }
        $res->free();

        return $out;
    }
}

// ---------------------------------------------------------------------------
// Cooldowns (NAP-break penalty)
// ---------------------------------------------------------------------------

if (!function_exists('botDiplomacyApplyCooldown')) {
    function botDiplomacyApplyCooldown(
        mysqli $db,
        string $prefix,
        int $allianceId,
        string $kind,
        int $durationSeconds,
        ?string $reason = null,
        ?int $now = null
    ): bool {
        $allowed = ['peace_block', 'nap_block', 'war_block'];
        if ($allianceId <= 0 || !in_array($kind, $allowed, true) || $durationSeconds <= 0) {
            return false;
        }
        $now = $now ?? time();
        $until = $now + $durationSeconds;
        $tbl = $prefix . 'alliance_diplomacy_cooldown';
        $kindEsc = $db->real_escape_string($kind);
        $reasonEsc = $reason === null ? 'NULL' : "'" . $db->real_escape_string($reason) . "'";
        $sql = "INSERT INTO `{$tbl}` (`alliance_id`, `kind`, `until_at`, `reason`)
                VALUES ({$allianceId}, '{$kindEsc}', {$until}, {$reasonEsc})
                ON DUPLICATE KEY UPDATE
                    `until_at` = VALUES(`until_at`),
                    `reason` = VALUES(`reason`)";

        return (bool) $db->query($sql);
    }
}

if (!function_exists('botDiplomacyCooldownActive')) {
    function botDiplomacyCooldownActive(
        mysqli $db,
        string $prefix,
        int $allianceId,
        string $kind,
        ?int $now = null
    ): bool {
        if ($allianceId <= 0) {
            return false;
        }
        $now = $now ?? time();
        $tbl = $prefix . 'alliance_diplomacy_cooldown';
        $kindEsc = $db->real_escape_string($kind);
        $res = $db->query(
            "SELECT 1 FROM `{$tbl}`
             WHERE `alliance_id` = {$allianceId}
               AND `kind` = '{$kindEsc}'
               AND `until_at` > {$now}
             LIMIT 1"
        );
        if (!$res) {
            return false;
        }
        $found = $res->fetch_assoc() !== null;
        $res->free();

        return $found;
    }
}

if (!function_exists('botDiplomacyApplyBreakNapCooldowns')) {
    /**
     * Convenience: applies peace_block + nap_block + war_block at once
     * with the same duration. Used by `botDiplomacyBreakNap`.
     */
    function botDiplomacyApplyBreakNapCooldowns(
        mysqli $db,
        string $prefix,
        int $allianceId,
        ?int $durationSeconds = null,
        ?int $now = null
    ): bool {
        $duration = $durationSeconds ?? (defined('BOT_DIPLO_BREAK_NAP_COOLDOWN_SECONDS')
            ? (int) BOT_DIPLO_BREAK_NAP_COOLDOWN_SECONDS
            : 86_400);
        $reason = 'broke_nap';
        $a = botDiplomacyApplyCooldown($db, $prefix, $allianceId, 'peace_block', $duration, $reason, $now);
        $b = botDiplomacyApplyCooldown($db, $prefix, $allianceId, 'nap_block', $duration, $reason, $now);
        $c = botDiplomacyApplyCooldown($db, $prefix, $allianceId, 'war_block', $duration, $reason, $now);

        return $a && $b && $c;
    }
}

// ---------------------------------------------------------------------------
// Log
// ---------------------------------------------------------------------------

if (!function_exists('botDiplomacyLogAction')) {
    function botDiplomacyLogAction(
        mysqli $db,
        string $prefix,
        int $fromAllianceId,
        int $toAllianceId,
        int $actorUserId,
        string $action,
        ?array $payload = null,
        ?int $now = null
    ): bool {
        if ($fromAllianceId <= 0 || $toAllianceId <= 0 || $action === '') {
            return false;
        }
        $now = $now ?? time();
        $tbl = $prefix . 'alliance_diplomacy_log';
        $actionEsc = $db->real_escape_string(substr($action, 0, 32));
        $payloadJson = $payload === null ? 'NULL' : "'" . $db->real_escape_string(json_encode($payload)) . "'";
        $sql = "INSERT INTO `{$tbl}` (`from_alliance_id`, `to_alliance_id`, `actor_user_id`, `action`, `payload`, `created_at`)
                VALUES ({$fromAllianceId}, {$toAllianceId}, {$actorUserId}, '{$actionEsc}', {$payloadJson}, {$now})";

        return (bool) $db->query($sql);
    }
}

// ---------------------------------------------------------------------------
// Status writes (war, NAP, peace)
// ---------------------------------------------------------------------------

if (!function_exists('botDiplomacyDeclareWar')) {
    /**
     * Unilateral war declaration. Returns true if a new war row was
     * created or an existing row flipped to 'war'. Refuses if the
     * declaring alliance has an active `war_block` cooldown.
     */
    function botDiplomacyDeclareWar(
        mysqli $db,
        string $prefix,
        int $declaringAllianceId,
        int $targetAllianceId,
        int $actorUserId = 0,
        ?int $now = null
    ): bool {
        if ($declaringAllianceId <= 0 || $targetAllianceId <= 0
            || $declaringAllianceId === $targetAllianceId
        ) {
            return false;
        }
        $now = $now ?? time();
        if (botDiplomacyCooldownActive($db, $prefix, $declaringAllianceId, 'war_block', $now)) {
            return false;
        }
        $pair = botDiplomacyNormalisePair($declaringAllianceId, $targetAllianceId);
        if ($pair === null) {
            return false;
        }
        $tbl = $prefix . 'alliance_diplomacy';
        $sql = "INSERT INTO `{$tbl}` (`alliance_a`, `alliance_b`, `status`, `since`, `expires_at`, `declared_by`, `last_action_at`)
                VALUES ({$pair[0]}, {$pair[1]}, 'war', {$now}, 0, {$declaringAllianceId}, {$now})
                ON DUPLICATE KEY UPDATE
                    `status` = 'war',
                    `since` = IF(`status` = 'war', `since`, VALUES(`since`)),
                    `declared_by` = IF(`status` = 'war', `declared_by`, VALUES(`declared_by`)),
                    `expires_at` = 0,
                    `last_action_at` = VALUES(`last_action_at`)";
        if (!$db->query($sql)) {
            return false;
        }
        botDiplomacyLogAction(
            $db,
            $prefix,
            $declaringAllianceId,
            $targetAllianceId,
            $actorUserId,
            $actorUserId === 0 ? 'auto_declare_war' : 'declare_war',
            null,
            $now
        );

        return true;
    }
}

if (!function_exists('botDiplomacySignPeace')) {
    /**
     * Removes the war between two alliances by flipping status to
     * 'neutral' (we keep the row to preserve `damage_*` history for the
     * UI). The caller decides whether this is a "free" peace or a bribe.
     *
     * Returns true if the row existed and was set to 'neutral'.
     */
    function botDiplomacySignPeace(
        mysqli $db,
        string $prefix,
        int $aId,
        int $bId,
        int $actorUserId = 0,
        string $logAction = 'accept_peace',
        ?array $payload = null,
        ?int $now = null
    ): bool {
        $pair = botDiplomacyNormalisePair($aId, $bId);
        if ($pair === null) {
            return false;
        }
        $now = $now ?? time();
        $tbl = $prefix . 'alliance_diplomacy';
        $sql = "UPDATE `{$tbl}` SET
                    `status` = 'neutral',
                    `expires_at` = 0,
                    `last_action_at` = {$now}
                WHERE `alliance_a` = {$pair[0]}
                  AND `alliance_b` = {$pair[1]}
                  AND `status` = 'war'
                LIMIT 1";
        if (!$db->query($sql) || $db->affected_rows < 1) {
            return false;
        }
        botDiplomacyLogAction($db, $prefix, $aId, $bId, $actorUserId, $logAction, $payload, $now);

        return true;
    }
}

if (!function_exists('botDiplomacyUpsertNap')) {
    /**
     * Creates or refreshes a NAP between two alliances. Refuses if
     * either side has an active `nap_block` cooldown.
     */
    function botDiplomacyUpsertNap(
        mysqli $db,
        string $prefix,
        int $aId,
        int $bId,
        int $actorUserId = 0,
        ?int $duration = null,
        ?int $now = null
    ): bool {
        $pair = botDiplomacyNormalisePair($aId, $bId);
        if ($pair === null) {
            return false;
        }
        $now = $now ?? time();
        if (botDiplomacyCooldownActive($db, $prefix, $aId, 'nap_block', $now)
            || botDiplomacyCooldownActive($db, $prefix, $bId, 'nap_block', $now)
        ) {
            return false;
        }
        $dur = $duration ?? (defined('BOT_DIPLO_NAP_DEFAULT_DURATION_SECONDS')
            ? (int) BOT_DIPLO_NAP_DEFAULT_DURATION_SECONDS
            : 604_800);
        $expires = $now + $dur;
        $tbl = $prefix . 'alliance_diplomacy';
        $sql = "INSERT INTO `{$tbl}` (`alliance_a`, `alliance_b`, `status`, `since`, `expires_at`, `declared_by`, `last_action_at`)
                VALUES ({$pair[0]}, {$pair[1]}, 'nap', {$now}, {$expires}, 0, {$now})
                ON DUPLICATE KEY UPDATE
                    `status` = 'nap',
                    `since` = {$now},
                    `expires_at` = {$expires},
                    `declared_by` = 0,
                    `last_action_at` = {$now}";
        if (!$db->query($sql)) {
            return false;
        }
        botDiplomacyLogAction($db, $prefix, $aId, $bId, $actorUserId, 'accept_nap', null, $now);

        return true;
    }
}

if (!function_exists('botDiplomacyBreakNap')) {
    /**
     * Breaks an active NAP: removes the row and applies the 24h cooldown
     * on the breaker (peace_block + nap_block + war_block).
     */
    function botDiplomacyBreakNap(
        mysqli $db,
        string $prefix,
        int $breakerAllianceId,
        int $otherAllianceId,
        int $actorUserId = 0,
        ?int $now = null
    ): bool {
        $pair = botDiplomacyNormalisePair($breakerAllianceId, $otherAllianceId);
        if ($pair === null) {
            return false;
        }
        $now = $now ?? time();
        $tbl = $prefix . 'alliance_diplomacy';
        $sql = "UPDATE `{$tbl}` SET
                    `status` = 'neutral',
                    `expires_at` = 0,
                    `last_action_at` = {$now}
                WHERE `alliance_a` = {$pair[0]}
                  AND `alliance_b` = {$pair[1]}
                  AND `status` = 'nap'
                LIMIT 1";
        if (!$db->query($sql) || $db->affected_rows < 1) {
            return false;
        }
        botDiplomacyApplyBreakNapCooldowns($db, $prefix, $breakerAllianceId, null, $now);
        botDiplomacyLogAction($db, $prefix, $breakerAllianceId, $otherAllianceId, $actorUserId, 'break_nap', null, $now);

        return true;
    }
}

// ---------------------------------------------------------------------------
// Damage accumulator (used by peace-bribe threshold)
// ---------------------------------------------------------------------------

if (!function_exists('botDiplomacyAddDamage')) {
    /**
     * Adds damage inflicted by `attackerAllianceId` to `victimAllianceId`
     * for the current war row. Writes to `damage_a_to_b` or
     * `damage_b_to_a` depending on which side the attacker is on.
     */
    function botDiplomacyAddDamage(
        mysqli $db,
        string $prefix,
        int $attackerAllianceId,
        int $victimAllianceId,
        int $damage,
        ?int $now = null
    ): bool {
        if ($attackerAllianceId <= 0 || $victimAllianceId <= 0 || $damage <= 0) {
            return false;
        }
        $pair = botDiplomacyNormalisePair($attackerAllianceId, $victimAllianceId);
        if ($pair === null) {
            return false;
        }
        $now = $now ?? time();
        $tbl = $prefix . 'alliance_diplomacy';
        $col = $attackerAllianceId === $pair[0] ? 'damage_a_to_b' : 'damage_b_to_a';
        $sql = "UPDATE `{$tbl}` SET
                    `{$col}` = `{$col}` + {$damage},
                    `last_action_at` = {$now}
                WHERE `alliance_a` = {$pair[0]}
                  AND `alliance_b` = {$pair[1]}
                  AND `status` = 'war'
                LIMIT 1";

        return (bool) $db->query($sql);
    }
}

if (!function_exists('botDiplomacyGetWarStats')) {
    /**
     * @return array{since:int, declared_by:int, damage_attacker:int, damage_victim:int}|null
     *         Where "attacker" is the side that originally declared the
     *         war. Returns null if no war row.
     */
    function botDiplomacyGetWarStats(mysqli $db, string $prefix, int $aId, int $bId): ?array
    {
        $pair = botDiplomacyNormalisePair($aId, $bId);
        if ($pair === null) {
            return null;
        }
        $tbl = $prefix . 'alliance_diplomacy';
        $res = $db->query(
            "SELECT `since`, `declared_by`, `damage_a_to_b`, `damage_b_to_a`
             FROM `{$tbl}`
             WHERE `alliance_a` = {$pair[0]}
               AND `alliance_b` = {$pair[1]}
               AND `status` = 'war'
             LIMIT 1"
        );
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        $res->free();
        if ($row === null) {
            return null;
        }
        $declaredBy = (int) ($row['declared_by'] ?? 0);
        $a2b = (int) ($row['damage_a_to_b'] ?? 0);
        $b2a = (int) ($row['damage_b_to_a'] ?? 0);
        $damageAttacker = $declaredBy === $pair[0] ? $a2b : ($declaredBy === $pair[1] ? $b2a : 0);
        $damageVictim = $declaredBy === $pair[0] ? $b2a : ($declaredBy === $pair[1] ? $a2b : 0);

        return [
            'since' => (int) ($row['since'] ?? 0),
            'declared_by' => $declaredBy,
            'damage_attacker' => $damageAttacker,
            'damage_victim' => $damageVictim,
        ];
    }
}
