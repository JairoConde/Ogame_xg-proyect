<?php

declare(strict_types=1);

require_once __DIR__ . '/alliance_policy.php';

if (!function_exists('botAllianceInitQuirks')) {
    /**
     * @param array<string, mixed> $state bot_state row (quirks decoded)
     */
    function botAllianceInitQuirks(array &$state): void
    {
        if (!isset($state['bot_quirks']) || !is_array($state['bot_quirks'])) {
            $state['bot_quirks'] = [];
        }
        $q = &$state['bot_quirks'];
        if (!isset($q['alliance']) || !is_array($q['alliance'])) {
            $q['alliance'] = [
                'rejected_by' => [],
                'since_no_ally_at' => 0,
                'applications_failed_streak' => 0,
                'last_owner_review_at' => 0,
                'post_action_cooldown_until' => 0,
                'pending_alliance_id' => 0,
                'pending_since' => 0,
                'idle_no_candidate_streak' => 0,
            ];
        }
        if (!isset($q['metrics']) || !is_array($q['metrics'])) {
            $q['metrics'] = [];
        }
        $a = &$q['alliance'];
        if (!is_array($a['rejected_by'] ?? null)) {
            $a['rejected_by'] = [];
        }
    }
}

if (!function_exists('botAllianceMergeMetrics')) {
    /**
     * @param array<string, mixed> $state
     * @param array<string, int> $delta
     */
    function botAllianceMergeMetrics(array &$state, array $delta): void
    {
        botAllianceInitQuirks($state);
        $m = &$state['bot_quirks']['metrics'];
        foreach ($delta as $k => $v) {
            $m[$k] = (int) ($m[$k] ?? 0) + (int) $v;
        }
    }
}

if (!function_exists('botAlliancePruneRejectedBy')) {
    /**
     * @param array<string, mixed> $allianceQuirk
     */
    function botAlliancePruneRejectedBy(array &$allianceQuirk, int $now): void
    {
        $rb = $allianceQuirk['rejected_by'] ?? [];
        if (!is_array($rb)) {
            $allianceQuirk['rejected_by'] = [];

            return;
        }
        foreach ($rb as $aid => $exp) {
            if ((int) $exp <= $now) {
                unset($rb[$aid]);
            }
        }
        $allianceQuirk['rejected_by'] = $rb;
    }
}

if (!function_exists('botAllianceEsc')) {
    function botAllianceEsc(mysqli $db, string $s): string
    {
        return $db->real_escape_string($s);
    }
}

if (!function_exists('botAllianceNotifyInbox')) {
    function botAllianceNotifyInbox(
        mysqli $db,
        string $prefix,
        int $toUserId,
        int $fromUserId,
        string $fromName,
        string $subject,
        string $body,
        int $now
    ): void {
        if ($toUserId <= 0) {
            return;
        }
        $subj = botAllianceEsc($db, $subject);
        $fromN = botAllianceEsc($db, $fromName);
        $txt = botAllianceEsc($db, $body);
        $tbl = "{$prefix}messages";
        $db->query(
            "INSERT INTO `{$tbl}` (`message_sender`,`message_receiver`,`message_time`,`message_type`,`message_from`,`message_subject`,`message_text`,`message_read`)
             VALUES ({$fromUserId}, {$toUserId}, {$now}, 3, '{$fromN}', '{$subj}', '{$txt}', 0)"
        );
    }
}

if (!function_exists('botAllianceCountBotLed')) {
    function botAllianceCountBotLed(mysqli $db, string $prefix): int
    {
        $tbl = "{$prefix}alliance";
        $res = $db->query(
            "SELECT COUNT(*) AS c FROM `{$tbl}` WHERE `alliance_request` LIKE '%\"bot_managed\":true%' OR `alliance_request` LIKE '%\"bot_managed\": true%'"
        );
        if (!$res) {
            return 0;
        }
        $row = $res->fetch_assoc();

        return (int) ($row['c'] ?? 0);
    }
}

if (!function_exists('botAllianceLoadUserStats')) {
    /**
     * @return array{total: float, buildings: float, research: float, ships: float, defenses: float, military: float}
     */
    function botAllianceLoadUserStats(mysqli $db, string $prefix, int $userId): array
    {
        $defaults = [
            'total' => 0.0, 'buildings' => 0.0, 'research' => 0.0, 'ships' => 0.0, 'defenses' => 0.0, 'military' => 0.0,
        ];
        if ($userId <= 0) {
            return $defaults;
        }
        $tbl = "{$prefix}users_statistics";
        $res = $db->query(
            "SELECT `user_statistic_total_points`,`user_statistic_buildings_points`,`user_statistic_technology_points`,`user_statistic_ships_points`,`user_statistic_defenses_points`
             FROM `{$tbl}` WHERE `user_statistic_user_id` = {$userId} LIMIT 1"
        );
        if (!$res) {
            return $defaults;
        }
        $r = $res->fetch_assoc();
        if (!is_array($r)) {
            return $defaults;
        }
        $ships = (float) ($r['user_statistic_ships_points'] ?? 0);
        $defs = (float) ($r['user_statistic_defenses_points'] ?? 0);

        return [
            'total' => (float) ($r['user_statistic_total_points'] ?? 0),
            'buildings' => (float) ($r['user_statistic_buildings_points'] ?? 0),
            'research' => (float) ($r['user_statistic_technology_points'] ?? 0),
            'ships' => $ships,
            'defenses' => $defs,
            'military' => $ships + $defs,
        ];
    }
}

if (!function_exists('botAllianceLoadCandidates')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function botAllianceLoadCandidates(mysqli $db, string $prefix): array
    {
        $a = "{$prefix}alliance";
        $u = "{$prefix}users";
        $us = "{$prefix}users_statistics";
        $sql = "SELECT a.`alliance_id`, a.`alliance_name`, a.`alliance_tag`, a.`alliance_owner`, a.`alliance_register_time`,
                       a.`alliance_request`, a.`alliance_request_notallow`,
                       (SELECT COUNT(*) FROM `{$u}` uu WHERE uu.`user_ally_id` = a.`alliance_id`) AS `member_count`,
                       (SELECT COALESCE(SUM(us2.`user_statistic_total_points`),0) FROM `{$u}` u2
                          INNER JOIN `{$us}` us2 ON us2.`user_statistic_user_id` = u2.`user_id`
                          WHERE u2.`user_ally_id` = a.`alliance_id`) AS `stat_total`,
                       (SELECT COALESCE(SUM(us2.`user_statistic_ships_points` + us2.`user_statistic_defenses_points`),0) FROM `{$u}` u2
                          INNER JOIN `{$us}` us2 ON us2.`user_statistic_user_id` = u2.`user_id`
                          WHERE u2.`user_ally_id` = a.`alliance_id`) AS `stat_military`,
                       (SELECT COALESCE(SUM(us2.`user_statistic_technology_points`),0) FROM `{$u}` u2
                          INNER JOIN `{$us}` us2 ON us2.`user_statistic_user_id` = u2.`user_id`
                          WHERE u2.`user_ally_id` = a.`alliance_id`) AS `stat_research`
                FROM `{$a}` AS a";
        $res = $db->query($sql);
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }

        return $out;
    }
}

if (!function_exists('botAllianceLoadAllianceRow')) {
    /**
     * @return array<string, mixed>|null
     */
    function botAllianceLoadAllianceRow(mysqli $db, string $prefix, int $allianceId): ?array
    {
        if ($allianceId <= 0) {
            return null;
        }
        $tbl = "{$prefix}alliance";
        $res = $db->query("SELECT * FROM `{$tbl}` WHERE `alliance_id` = {$allianceId} LIMIT 1");
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('botAllianceLoadApplicants')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function botAllianceLoadApplicants(mysqli $db, string $prefix, int $allianceId): array
    {
        if ($allianceId <= 0) {
            return [];
        }
        $u = "{$prefix}users";
        $us = "{$prefix}users_statistics";
        $bs = "{$prefix}bot_state";
        $sql = "SELECT u.`user_id`, u.`user_name`, u.`user_onlinetime`, u.`user_ally_request_text`, u.`user_ally_register_time`,
                       us.`user_statistic_total_points`, us.`user_statistic_ships_points`, us.`user_statistic_defenses_points`,
                       us.`user_statistic_technology_points`,
                       bs.`bot_personality`, bs.`bot_archetype`
                FROM `{$u}` AS u
                LEFT JOIN `{$us}` AS us ON us.`user_statistic_user_id` = u.`user_id`
                LEFT JOIN `{$bs}` AS bs ON bs.`bot_user_id` = u.`user_id`
                WHERE u.`user_ally_request` = {$allianceId}
                ORDER BY u.`user_ally_register_time` ASC";
        $res = $db->query($sql);
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }

        return $out;
    }
}

if (!function_exists('botAllianceMeetsHardCreateRequirements')) {
    function botAllianceMeetsHardCreateRequirements(array $ctx): bool
    {
        $total = (float) ($ctx['stats']['total'] ?? 0);
        if ($total < (float) BOT_ALLIANCE_MIN_POINTS_TO_CREATE) {
            return false;
        }
        $now = (int) ($ctx['now'] ?? time());
        $reg = (int) ($ctx['user_register_time'] ?? 0);
        $minAge = (int) BOT_ALLIANCE_MIN_AGE_HOURS_TO_CREATE * 3600;
        if ($reg > 0 && ($now - $reg) < $minAge) {
            return false;
        }
        if ((int) ($ctx['bot_led_count'] ?? 0) >= (int) BOT_ALLIANCE_MAX_BOT_LED) {
            return false;
        }

        return true;
    }
}

if (!function_exists('botAllianceDefaultRanksJson')) {
    function botAllianceDefaultRanksJson(mysqli $db, string $founderRank, string $newcomerRank): string
    {
        $rights = '[{"rank":"Founder","rights":{"1":1,"2":1,"3":1,"4":1,"5":1,"6":1,"7":1,"8":1,"9":1}},{"rank":"Newcomer","rights":{"1":0,"2":0,"3":0,"4":0,"5":0,"6":0,"7":0,"8":0,"9":0}}]';

        return strtr($rights, ['Founder' => botAllianceEsc($db, $founderRank), 'Newcomer' => botAllianceEsc($db, $newcomerRank)]);
    }
}

if (!function_exists('botAllianceCreate')) {
    function botAllianceCreate(
        mysqli $db,
        string $prefix,
        int $userId,
        string $name,
        string $tag,
        string $requestJson,
        int $now
    ): ?int {
        $tbl = "{$prefix}alliance";
        $st = "{$prefix}alliance_statistics";
        $u = "{$prefix}users";
        $nameE = botAllianceEsc($db, $name);
        $tagE = botAllianceEsc($db, $tag);
        $reqE = botAllianceEsc($db, $requestJson);
        $ranks = botAllianceDefaultRanksJson($db, 'Founder', 'Newcomer');
        $chk = $db->query("SELECT `alliance_id` FROM `{$tbl}` WHERE `alliance_tag` = '{$tagE}' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            return null;
        }
        $chk2 = $db->query("SELECT `alliance_id` FROM `{$tbl}` WHERE `alliance_name` = '{$nameE}' LIMIT 1");
        if ($chk2 && $chk2->num_rows > 0) {
            return null;
        }
        $ok = $db->begin_transaction();

        try {
            if ($ok) {
                $db->query(
                    "INSERT INTO `{$tbl}` SET
                     `alliance_name` = '{$nameE}',
                     `alliance_tag` = '{$tagE}',
                     `alliance_owner` = {$userId},
                     `alliance_register_time` = {$now},
                     `alliance_ranks` = '{$ranks}',
                     `alliance_request` = '{$reqE}',
                     `alliance_request_notallow` = 1,
                     `alliance_description` = '',
                     `alliance_text` = '',
                     `alliance_web` = '',
                     `alliance_image` = ''"
                );
            } else {
                $db->query(
                    "INSERT INTO `{$tbl}` SET
                     `alliance_name` = '{$nameE}',
                     `alliance_tag` = '{$tagE}',
                     `alliance_owner` = {$userId},
                     `alliance_register_time` = {$now},
                     `alliance_ranks` = '{$ranks}',
                     `alliance_request` = '{$reqE}',
                     `alliance_request_notallow` = 1,
                     `alliance_description` = '',
                     `alliance_text` = '',
                     `alliance_web` = '',
                     `alliance_image` = ''"
                );
            }
            $newId = (int) $db->insert_id;
            if ($newId <= 0) {
                if ($ok) {
                    $db->rollback();
                }

                return null;
            }
            $db->query("INSERT INTO `{$st}` SET `alliance_statistic_alliance_id` = {$newId}");
            $db->query(
                "UPDATE `{$u}` SET `user_ally_id` = {$newId}, `user_ally_register_time` = {$now}, `user_ally_rank_id` = 0
                 WHERE `user_id` = {$userId} LIMIT 1"
            );
            if ($ok) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($ok) {
                $db->rollback();
            }

            return null;
        }

        return $newId;
    }
}

if (!function_exists('botAllianceApply')) {
    function botAllianceApply(mysqli $db, string $prefix, int $userId, int $allianceId, string $text, int $now): bool
    {
        if ($userId <= 0 || $allianceId <= 0) {
            return false;
        }
        $u = "{$prefix}users";
        $txt = botAllianceEsc($db, $text);

        return (bool) $db->query(
            "UPDATE `{$u}` SET `user_ally_request` = {$allianceId}, `user_ally_request_text` = '{$txt}',
             `user_ally_register_time` = {$now}, `user_ally_rank_id` = 1
             WHERE `user_id` = {$userId} AND `user_ally_id` = 0 LIMIT 1"
        );
    }
}

if (!function_exists('botAllianceCancelRequest')) {
    function botAllianceCancelRequest(mysqli $db, string $prefix, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $u = "{$prefix}users";
        $db->query(
            "UPDATE `{$u}` SET `user_ally_request` = 0, `user_ally_request_text` = ''
             WHERE `user_id` = {$userId} LIMIT 1"
        );
    }
}

if (!function_exists('botAllianceAcceptApplicant')) {
    function botAllianceAcceptApplicant(mysqli $db, string $prefix, int $applicantId, int $allianceId, int $now): bool
    {
        if ($applicantId <= 0 || $allianceId <= 0) {
            return false;
        }
        $u = "{$prefix}users";

        return (bool) $db->query(
            "UPDATE `{$u}` SET `user_ally_request` = 0, `user_ally_request_text` = '',
             `user_ally_id` = {$allianceId}, `user_ally_rank_id` = 1, `user_ally_register_time` = {$now}
             WHERE `user_id` = {$applicantId} AND `user_ally_request` = {$allianceId} LIMIT 1"
        );
    }
}

if (!function_exists('botAllianceRejectApplicant')) {
    function botAllianceRejectApplicant(mysqli $db, string $prefix, int $applicantId, int $allianceId): bool
    {
        if ($applicantId <= 0) {
            return false;
        }
        $u = "{$prefix}users";

        return (bool) $db->query(
            "UPDATE `{$u}` SET `user_ally_request` = 0, `user_ally_request_text` = ''
             WHERE `user_id` = {$applicantId} AND `user_ally_request` = {$allianceId} LIMIT 1"
        );
    }
}

if (!function_exists('botAllianceLeave')) {
    function botAllianceLeave(mysqli $db, string $prefix, int $userId, int $allianceId): bool
    {
        if ($userId <= 0 || $allianceId <= 0) {
            return false;
        }
        $u = "{$prefix}users";

        return (bool) $db->query(
            "UPDATE `{$u}` SET `user_ally_id` = 0, `user_ally_rank_id` = 0
             WHERE `user_id` = {$userId} AND `user_ally_id` = {$allianceId} LIMIT 1"
        );
    }
}

if (!function_exists('botAllianceKickMember')) {
    function botAllianceKickMember(mysqli $db, string $prefix, int $memberId, int $allianceId): bool
    {
        if ($memberId <= 0 || $allianceId <= 0) {
            return false;
        }
        $u = "{$prefix}users";

        return (bool) $db->query(
            "UPDATE `{$u}` SET `user_ally_id` = 0, `user_ally_rank_id` = 0
             WHERE `user_id` = {$memberId} AND `user_ally_id` = {$allianceId} LIMIT 1"
        );
    }
}

if (!function_exists('botAllianceTransferOwnership')) {
    /**
     * Atomically move the alliance_owner pointer from $oldOwnerId to
     * $newOwnerId. The UPDATE is guarded by the current owner id to
     * prevent two members racing to claim ownership.
     */
    function botAllianceTransferOwnership(
        mysqli $db,
        string $prefix,
        int $allianceId,
        int $newOwnerId,
        int $oldOwnerId
    ): bool {
        if ($allianceId <= 0 || $newOwnerId <= 0 || $oldOwnerId <= 0 || $newOwnerId === $oldOwnerId) {
            return false;
        }
        $tbl = "{$prefix}alliance";
        $u = "{$prefix}users";
        $ok = $db->begin_transaction();

        try {
            $res = $db->query(
                "SELECT `alliance_owner` FROM `{$tbl}` WHERE `alliance_id` = {$allianceId} FOR UPDATE"
            );
            if (!$res) {
                $db->rollback();

                return false;
            }
            $row = $res->fetch_assoc();
            if (!is_array($row) || (int) $row['alliance_owner'] !== $oldOwnerId) {
                $db->rollback();

                return false;
            }
            $ok1 = (bool) $db->query(
                "UPDATE `{$tbl}` SET `alliance_owner` = {$newOwnerId}
                 WHERE `alliance_id` = {$allianceId} AND `alliance_owner` = {$oldOwnerId} LIMIT 1"
            );
            if (!$ok1) {
                $db->rollback();

                return false;
            }
            // Ensure the chosen member is in the alliance and bump rank.
            $db->query(
                "UPDATE `{$u}` SET `user_ally_rank_id` = 1
                 WHERE `user_id` = {$newOwnerId} AND `user_ally_id` = {$allianceId} LIMIT 1"
            );
            $db->commit();

            return true;
        } catch (Throwable $e) {
            $db->rollback();

            return false;
        }
    }
}

if (!function_exists('botAllianceLoadMembersForTransfer')) {
    /**
     * Returns active bot members of an alliance (excluding the current
     * owner) annotated with stats and a transfer score, ready to feed
     * `BotAlliancePolicyRules::decideOwnershipTransfer`.
     *
     * @return array<int, array<string, mixed>>
     */
    function botAllianceLoadMembersForTransfer(
        mysqli $db,
        string $prefix,
        int $allianceId,
        int $excludeOwnerId
    ): array {
        if ($allianceId <= 0) {
            return [];
        }
        $u = "{$prefix}users";
        $us = "{$prefix}users_statistics";
        $bs = "{$prefix}bot_state";
        $sql = "SELECT u.`user_id`, u.`user_name`, u.`user_onlinetime`, u.`user_ally_register_time`,
                       us.`user_statistic_total_points`, us.`user_statistic_ships_points`,
                       us.`user_statistic_defenses_points`, us.`user_statistic_technology_points`,
                       bs.`bot_personality`
                FROM `{$u}` AS u
                INNER JOIN `{$bs}` AS bs ON bs.`bot_user_id` = u.`user_id`
                LEFT JOIN `{$us}` AS us ON us.`user_statistic_user_id` = u.`user_id`
                WHERE u.`user_ally_id` = {$allianceId} AND u.`user_id` != {$excludeOwnerId}";
        $res = $db->query($sql);
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $member = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'user_name' => (string) ($row['user_name'] ?? ''),
                'user_ally_register_time' => (int) ($row['user_ally_register_time'] ?? 0),
                'user_onlinetime' => (int) ($row['user_onlinetime'] ?? 0),
                'total_points' => (float) ($row['user_statistic_total_points'] ?? 0),
                'military_points' => (float) ($row['user_statistic_ships_points'] ?? 0)
                    + (float) ($row['user_statistic_defenses_points'] ?? 0),
                'fleet_points' => (float) ($row['user_statistic_ships_points'] ?? 0),
                'research_points' => (float) ($row['user_statistic_technology_points'] ?? 0),
                'personality' => (string) ($row['bot_personality'] ?? ''),
            ];
            $member['transfer_score'] = BotAlliancePolicyRules::computeTransferScore($member);
            $out[] = $member;
        }

        return $out;
    }
}

if (!function_exists('botAllianceIsBotUser')) {
    function botAllianceIsBotUser(mysqli $db, string $prefix, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $bs = "{$prefix}bot_state";
        $res = $db->query("SELECT 1 FROM `{$bs}` WHERE `bot_user_id` = {$userId} LIMIT 1");

        return $res ? $res->num_rows > 0 : false;
    }
}

if (!function_exists('botAllianceIncrementRemoteMetric')) {
    /**
     * Increment a counter inside another bot's `bot_quirks.metrics`. Used
     * when an action observed by bot A must be accounted on bot B (e.g.
     * the previous owner loses ownership but isn't in the active loop).
     * Falls back to read-modify-write if JSON_SET isn't available.
     */
    function botAllianceIncrementRemoteMetric(
        mysqli $db,
        string $prefix,
        int $userId,
        string $metric,
        int $delta = 1
    ): bool {
        if ($userId <= 0 || $metric === '' || $delta === 0) {
            return false;
        }
        $bs = "{$prefix}bot_state";
        $res = $db->query("SELECT `bot_quirks` FROM `{$bs}` WHERE `bot_user_id` = {$userId} LIMIT 1");
        if (!$res) {
            return false;
        }
        $row = $res->fetch_assoc();
        $current = is_array($row) ? (string) ($row['bot_quirks'] ?? '') : '';
        $quirks = $current !== '' ? json_decode($current, true) : null;
        if (!is_array($quirks)) {
            $quirks = [];
        }
        if (!isset($quirks['metrics']) || !is_array($quirks['metrics'])) {
            $quirks['metrics'] = [];
        }
        $quirks['metrics'][$metric] = (int) ($quirks['metrics'][$metric] ?? 0) + (int) $delta;
        $encoded = json_encode($quirks);
        if ($encoded === false) {
            return false;
        }
        $esc = botAllianceEsc($db, $encoded);

        return (bool) $db->query(
            "UPDATE `{$bs}` SET `bot_quirks` = '{$esc}' WHERE `bot_user_id` = {$userId} LIMIT 1"
        );
    }
}

if (!function_exists('botAllianceDissolve')) {
    function botAllianceDissolve(mysqli $db, string $prefix, int $allianceId): bool
    {
        if ($allianceId <= 0) {
            return false;
        }
        $u = "{$prefix}users";
        $a = "{$prefix}alliance";
        $st = "{$prefix}alliance_statistics";
        $ok = $db->begin_transaction();

        try {
            $db->query(
                "UPDATE `{$u}` SET `user_ally_id` = 0, `user_ally_rank_id` = 0 WHERE `user_ally_id` = {$allianceId}"
            );
            $db->query("UPDATE `{$u}` SET `user_ally_request` = 0 WHERE `user_ally_request` = {$allianceId}");
            $db->query("DELETE FROM `{$st}` WHERE `alliance_statistic_alliance_id` = {$allianceId} LIMIT 1");
            $db->query("DELETE FROM `{$a}` WHERE `alliance_id` = {$allianceId} LIMIT 1");
            if ($ok) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($ok) {
                $db->rollback();
            }

            return false;
        }

        return true;
    }
}

if (!function_exists('botAllianceBuildContext')) {
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $botState
     * @param array<string, mixed> $profile
     * @param array<string, float> $stats
     * @param array<string, mixed> $allianceQuirk
     * @return array<string, mixed>
     */
    function botAllianceBuildContext(
        array $user,
        array $botState,
        array $profile,
        array $stats,
        array $allianceQuirk,
        int $now,
        int $botLedCount,
        ?array $allianceRow = null
    ): array {
        $uid = (int) ($user['user_id'] ?? 0);
        $since = (int) ($allianceQuirk['since_no_ally_at'] ?? 0);
        if ($since <= 0) {
            $since = $now;
        }

        return [
            'now' => $now,
            'user_id' => $uid,
            'user_name' => (string) ($user['user_name'] ?? ''),
            'user_register_time' => (int) ($user['user_register_time'] ?? 0),
            'seed' => botSeed($uid, (string) ($user['user_name'] ?? '')),
            'personality' => (string) ($botState['bot_personality'] ?? 'flotero'),
            'archetype' => (string) ($botState['bot_archetype'] ?? 'balanced'),
            'style' => (string) ($profile['bot_style'] ?? 'granja'),
            'stats' => $stats,
            'rejected_by' => is_array($allianceQuirk['rejected_by'] ?? null) ? $allianceQuirk['rejected_by'] : [],
            'since_no_ally_seconds' => max(0, $now - $since),
            'applications_failed_streak' => (int) ($allianceQuirk['applications_failed_streak'] ?? 0),
            'bot_led_count' => $botLedCount,
            'alliance_row' => $allianceRow,
            'alliance_meta' => $allianceRow !== null
                ? botAllianceParseRequirements((string) ($allianceRow['alliance_request'] ?? ''))
                : null,
            'member_count' => $allianceRow !== null
                ? (int) ($allianceRow['member_count'] ?? 0)
                : 0,
            'is_owner' => false,
            'owner_inactive_seconds' => 0,
            'active_members_last_7d' => 0,
        ];
    }
}

if (!function_exists('botAllianceTick')) {
    /**
     * @param array<string, mixed> $user Must include alliance-related columns
     * @param array<string, mixed> $botState Full row (decoded JSON)
     * @param array<string, mixed> $profile
     * @param array<int, array<string, mixed>> $planets Unused for now (reserved for ctx)
     */
    function botAllianceTick(
        mysqli $db,
        string $prefix,
        array $user,
        array &$botState,
        array $profile,
        array $planets,
        int $now,
        int $loopIndex
    ): array {
        $logs = [];
        $userId = (int) ($user['user_id'] ?? 0);
        $userName = (string) ($user['user_name'] ?? '');
        botAllianceInitQuirks($botState);
        $q = &$botState['bot_quirks'];
        $a = &$q['alliance'];
        botAlliancePruneRejectedBy($a, $now);

        $allyId = (int) ($user['user_ally_id'] ?? 0);
        $reqIdEarly = (int) ($user['user_ally_request'] ?? 0);
        // post-action cooldown only blocks NEW application attempts.
        // Bots in an alliance (owners reviewing applicants, members
        // considering leave) must keep running regardless.
        if ($allyId === 0 && $reqIdEarly === 0 && $now < (int) ($a['post_action_cooldown_until'] ?? 0)) {
            $a['idle_no_candidate_streak'] = (int) ($a['idle_no_candidate_streak'] ?? 0) + 1;
            if ($a['idle_no_candidate_streak'] % 30 === 0) {
                botAllianceMergeMetrics($botState, ['alliance_skip_cooldown' => 1]);
                $logs[] = 'alliance: idle cooldown';
            }

            return $logs;
        }

        if ($allyId > 0) {
            $a['since_no_ally_at'] = 0;
            $a['idle_no_candidate_streak'] = 0;
        }

        $reqId = (int) ($user['user_ally_request'] ?? 0);
        $stats = botAllianceLoadUserStats($db, $prefix, $userId);
        $botLed = botAllianceCountBotLed($db, $prefix);

        $policy = new BotAlliancePolicyRules();

        // --- Pending outbound application tracking (order: accepted before rejected) ---
        $pendingAid = (int) ($a['pending_alliance_id'] ?? 0);
        if ($pendingAid > 0 && $allyId > 0) {
            if ($allyId === $pendingAid) {
                $pendingSince = (int) ($a['pending_since'] ?? 0);
                $delta = $pendingSince > 0 ? max(0, $now - $pendingSince) : 0;
                $extra = [
                    'alliance_applications_accepted' => 1,
                    'alliance_apply_to_join_count' => 1,
                ];
                if ($delta > 0) {
                    $extra['alliance_apply_to_join_seconds_sum'] = $delta;
                }
                botAllianceMergeMetrics($botState, $extra);
                $logs[] = "alliance: joined ally={$allyId}";
            }
            $a['pending_alliance_id'] = 0;
            $a['pending_since'] = 0;
            $a['applications_failed_streak'] = 0;
        } elseif ($pendingAid > 0 && $reqId === 0 && $allyId === 0) {
            $a['rejected_by'][(string) $pendingAid] = $now + (int) BOT_ALLIANCE_REJECTED_BY_TTL_SECONDS;
            $a['applications_failed_streak'] = (int) ($a['applications_failed_streak'] ?? 0) + 1;
            $a['pending_alliance_id'] = 0;
            $a['pending_since'] = 0;
            $a['post_action_cooldown_until'] = $now + (int) BOT_ALLIANCE_POST_ACTION_COOLDOWN_SECONDS;
            botAllianceMergeMetrics($botState, ['alliance_applications_rejected_received' => 1]);
            $logs[] = "alliance: application cleared (rejected or cancelled) ally={$pendingAid}";
        }

        $allyId = (int) ($user['user_ally_id'] ?? 0);
        $reqId = (int) ($user['user_ally_request'] ?? 0);

        if ($reqId > 0) {
            $appliedAt = (int) ($user['user_ally_register_time'] ?? 0);
            if ($appliedAt > 0 && ($now - $appliedAt) > (int) BOT_ALLIANCE_APPLY_PENDING_TTL_SECONDS) {
                botAllianceCancelRequest($db, $prefix, $userId);
                $a['rejected_by'][(string) $reqId] = $now + (int) BOT_ALLIANCE_REJECTED_BY_TTL_SECONDS;
                $a['applications_failed_streak'] = (int) ($a['applications_failed_streak'] ?? 0) + 1;
                $a['pending_alliance_id'] = 0;
                $a['pending_since'] = 0;
                $a['post_action_cooldown_until'] = $now + (int) BOT_ALLIANCE_POST_ACTION_COOLDOWN_SECONDS;
                botAllianceMergeMetrics($botState, ['alliance_request_expired' => 1]);
                $logs[] = "alliance: request expired ally={$reqId}";
            }

            return $logs;
        }

        if ($allyId > 0) {
            $row = botAllianceLoadAllianceRow($db, $prefix, $allyId);
            if ($row === null) {
                return $logs;
            }
            $ownerId = (int) ($row['alliance_owner'] ?? 0);
            $isOwner = $ownerId === $userId;
            $mcRes = $db->query("SELECT COUNT(*) AS c FROM `{$prefix}users` WHERE `user_ally_id` = {$allyId}");
            $memberCount = ($mcRes && ($mcRow = $mcRes->fetch_assoc())) ? (int) ($mcRow['c'] ?? 0) : 0;

            $ownerRow = $ownerId > 0
                ? $db->query("SELECT `user_onlinetime` FROM `{$prefix}users` WHERE `user_id` = {$ownerId} LIMIT 1")->fetch_assoc()
                : null;
            $ownerOnline = is_array($ownerRow) ? (int) ($ownerRow['user_onlinetime'] ?? 0) : 0;
            $activeRes = $db->query(
                "SELECT COUNT(*) AS c FROM `{$prefix}users` WHERE `user_ally_id` = {$allyId} AND `user_onlinetime` >= " . ($now - 86400 * 7)
            );
            $active7 = ($activeRes && ($ar = $activeRes->fetch_assoc())) ? (int) ($ar['c'] ?? 0) : 0;

            $ctx = botAllianceBuildContext($user, $botState, $profile, $stats, $a, $now, $botLed, array_merge($row, ['member_count' => $memberCount]));
            $ctx['is_owner'] = $isOwner;
            $ctx['owner_inactive_seconds'] = $ownerOnline > 0 ? ($now - $ownerOnline) : 999999999;
            $ctx['active_members_last_7d'] = $active7;
            $ctx['member_count'] = $memberCount;

            if ($isOwner) {
                $lastRev = (int) ($a['last_owner_review_at'] ?? 0);
                if (($now - $lastRev) < (int) BOT_ALLIANCE_OWNER_REVIEW_COOLDOWN_SECONDS) {
                    return $logs;
                }
                $a['last_owner_review_at'] = $now;

                $apps = botAllianceLoadApplicants($db, $prefix, $allyId);
                foreach ($apps as $ap) {
                    $appId = (int) ($ap['user_id'] ?? 0);
                    if ($appId <= 0 || $appId === $userId) {
                        continue;
                    }
                    $hasBot = (($ap['bot_personality'] ?? '') !== '');
                    $appStats = [
                        'total_points' => (float) ($ap['user_statistic_total_points'] ?? 0),
                        'military_points' => (float) ($ap['user_statistic_ships_points'] ?? 0) + (float) ($ap['user_statistic_defenses_points'] ?? 0),
                        'fleet_points' => (float) ($ap['user_statistic_ships_points'] ?? 0),
                        'research_points' => (float) ($ap['user_statistic_technology_points'] ?? 0),
                        'personality' => (string) ($ap['bot_personality'] ?? 'flotero'),
                        'style' => 'granja',
                        'user_onlinetime' => (int) ($ap['user_onlinetime'] ?? 0),
                        'user_id' => $appId,
                        'is_bot_applicant' => $hasBot,
                    ];
                    $dec = $policy->decideAcceptApplicant($ctx, $appStats);
                    $tag = (string) ($row['alliance_tag'] ?? '');
                    if ($dec['action'] === 'accept') {
                        if (botAllianceAcceptApplicant($db, $prefix, $appId, $allyId, $now)) {
                            botAllianceMergeMetrics($botState, ['alliance_applicants_accepted' => 1]);
                            botAllianceNotifyInbox($db, $prefix, $appId, $userId, $userName, "[{$tag}] Accepted", 'Your application was accepted.', $now);
                            $logs[] = "alliance: accept user={$appId}";
                        }
                    } elseif ($dec['action'] === 'reject') {
                        if (botAllianceRejectApplicant($db, $prefix, $appId, $allyId)) {
                            botAllianceMergeMetrics($botState, ['alliance_applicants_rejected' => 1]);
                            botAllianceNotifyInbox($db, $prefix, $appId, $userId, $userName, "[{$tag}] Rejected", 'Your application was rejected.', $now);
                            $logs[] = "alliance: reject user={$appId}";
                        }
                    }
                }

                $memRows = $db->query(
                    "SELECT u.`user_id`, u.`user_name`, u.`user_onlinetime`, u.`user_ally_register_time`, bs.`bot_personality`,
                            us.`user_statistic_total_points`, us.`user_statistic_ships_points`, us.`user_statistic_defenses_points`, us.`user_statistic_technology_points`
                     FROM `{$prefix}users` u
                     LEFT JOIN `{$prefix}users_statistics` us ON us.`user_statistic_user_id` = u.`user_id`
                     LEFT JOIN `{$prefix}bot_state` bs ON bs.`bot_user_id` = u.`user_id`
                     WHERE u.`user_ally_id` = {$allyId} AND u.`user_id` != {$userId}"
                );
                if ($memRows) {
                    while ($mem = $memRows->fetch_assoc()) {
                        $mid = (int) ($mem['user_id'] ?? 0);
                        if ($mid <= 0) {
                            continue;
                        }
                        $mctx = [
                            'user_id' => $mid,
                            'user_name' => (string) ($mem['user_name'] ?? ''),
                            'user_onlinetime' => (int) ($mem['user_onlinetime'] ?? 0),
                            'user_ally_register_time' => (int) ($mem['user_ally_register_time'] ?? 0),
                            'total_points' => (float) ($mem['user_statistic_total_points'] ?? 0),
                            'military_points' => (float) ($mem['user_statistic_ships_points'] ?? 0) + (float) ($mem['user_statistic_defenses_points'] ?? 0),
                            'fleet_points' => (float) ($mem['user_statistic_ships_points'] ?? 0),
                            'research_points' => (float) ($mem['user_statistic_technology_points'] ?? 0),
                            'personality' => (string) ($mem['bot_personality'] ?? ''),
                        ];
                        $kd = $policy->decideKick($ctx, $mctx);
                        if ($kd['action'] === 'kick') {
                            if (botAllianceKickMember($db, $prefix, $mid, $allyId)) {
                                botAllianceMergeMetrics($botState, ['alliance_members_kicked' => 1]);
                                botAllianceNotifyInbox($db, $prefix, $mid, $userId, $userName, "[{$tag}] Kicked", 'You were removed from the alliance.', $now);
                                $logs[] = "alliance: kick user={$mid}";
                            }
                        }
                    }
                }

                // Before deciding to dissolve, look at the best alternative
                // alliance the bot could apply to. If a lone founder still
                // inside its grace window can join a clearly populated
                // alliance, dissolve early to avoid wasting the slot.
                $bestAlt = 0;
                if ($memberCount <= 1) {
                    $altRows = botAllianceLoadCandidates($db, $prefix);
                    foreach ($altRows as $alt) {
                        $altId = (int) ($alt['alliance_id'] ?? 0);
                        if ($altId === $allyId || $altId === 0) {
                            continue;
                        }
                        $mc = (int) ($alt['member_count'] ?? 0);
                        if ($mc > $bestAlt) {
                            $bestAlt = $mc;
                        }
                    }
                    $ctx['better_alternative_member_count'] = $bestAlt;
                }

                $diss = $policy->decideDissolve($ctx);
                if ($diss['action'] === 'dissolve') {
                    if (botAllianceDissolve($db, $prefix, $allyId)) {
                        botAllianceMergeMetrics($botState, ['alliances_dissolved' => 1]);
                        if (($diss['reason'] ?? '') === 'better_alternative_in_grace') {
                            botAllianceMergeMetrics($botState, ['alliances_dissolved_for_better_alt' => 1]);
                        }
                        $a['since_no_ally_at'] = $now;
                        $a['last_dissolved_at'] = $now;
                        $a['post_action_cooldown_until'] = $now + (int) BOT_ALLIANCE_POST_ACTION_COOLDOWN_SECONDS;
                        $logs[] = 'alliance: dissolve reason=' . (string) ($diss['reason'] ?? '');
                    }
                }

                return $logs;
            }

            // Member (not owner): ownership transfer check first, then rare leave review.
            $ownerIsBot = $ownerId > 0 && botAllianceIsBotUser($db, $prefix, $ownerId);
            $ctx['owner_is_bot'] = $ownerIsBot;
            if ($ownerIsBot) {
                $lastTr = (int) ($a['last_transfer_review_at'] ?? 0);
                $trCool = defined('BOT_ALLIANCE_OWNER_TRANSFER_REVIEW_COOLDOWN_SECONDS')
                    ? (int) BOT_ALLIANCE_OWNER_TRANSFER_REVIEW_COOLDOWN_SECONDS
                    : 300;
                if (($now - $lastTr) >= $trCool) {
                    $a['last_transfer_review_at'] = $now;
                    $eligible = botAllianceLoadMembersForTransfer($db, $prefix, $allyId, $ownerId);
                    $td = $policy->decideOwnershipTransfer($ctx, $eligible);
                    if ($td['action'] === 'transfer') {
                        $newOwner = (int) ($td['params']['new_owner_id'] ?? 0);
                        if ($newOwner === $userId) {
                            if (botAllianceTransferOwnership($db, $prefix, $allyId, $newOwner, $ownerId)) {
                                botAllianceMergeMetrics($botState, [
                                    'alliances_ownership_received' => 1,
                                ]);
                                botAllianceIncrementRemoteMetric(
                                    $db,
                                    $prefix,
                                    $ownerId,
                                    'alliances_ownership_transferred',
                                    1
                                );
                                $tag = (string) ($row['alliance_tag'] ?? '');
                                $msg = "[{$tag}] Ownership transferred to {$userName} after "
                                    . (int) ($td['params']['inactive_seconds'] ?? 0) . 's of inactivity.';
                                botAllianceNotifyInbox($db, $prefix, $ownerId, $userId, $userName, "[{$tag}] Ownership", $msg, $now);
                                $logs[] = "alliance: ownership transfer from={$ownerId} to={$userId}";

                                return $logs;
                            }
                        }
                    }
                }
            }

            $r = botRng(botSeed($userId, $userName), 'ally_member_review_' . $loopIndex);
            if ($r * 100.0 > (float) BOT_ALLIANCE_MEMBER_REVIEW_CHANCE_X100) {
                return $logs;
            }
            $ld = $policy->decideLeave($ctx);
            if ($ld['action'] === 'leave') {
                if (botAllianceLeave($db, $prefix, $userId, $allyId)) {
                    botAllianceMergeMetrics($botState, ['alliance_left_voluntary' => 1]);
                    $a['since_no_ally_at'] = $now;
                    $a['post_action_cooldown_until'] = $now + (int) BOT_ALLIANCE_POST_ACTION_COOLDOWN_SECONDS;
                    $logs[] = 'alliance: leave';
                }
            }

            return $logs;
        }

        // No alliance: ensure since_no_ally_at
        if ((int) ($a['since_no_ally_at'] ?? 0) <= 0) {
            $a['since_no_ally_at'] = $now;
        }

        $ctx = botAllianceBuildContext($user, $botState, $profile, $stats, $a, $now, $botLed, null);
        $candidates = botAllianceLoadCandidates($db, $prefix);
        $filtered = BotAlliancePolicyRules::filterCandidatesForApply($ctx, $candidates, false);
        $sorted = BotAlliancePolicyRules::sortCandidatesByScore($ctx, $filtered, false);
        $decApply = $policy->decideApply($ctx, $sorted);
        if ($decApply['action'] === 'apply') {
            $target = (int) ($decApply['params']['alliance_id'] ?? 0);
            if ($target > 0) {
                $msg = 'Bot application — auto-generated.';
                if (botAllianceApply($db, $prefix, $userId, $target, $msg, $now)) {
                    $a['pending_alliance_id'] = $target;
                    $a['pending_since'] = $now;
                    botAllianceMergeMetrics($botState, ['alliance_applications_sent' => 1]);
                    $logs[] = "alliance: apply ally={$target}";
                }

                return $logs;
            }
        }

        $canHard = botAllianceMeetsHardCreateRequirements($ctx);
        $createDec = $policy->decideCreate($ctx);
        // Anti-rebound: if this bot just dissolved its own alliance, it
        // must apply to an existing one instead of immediately founding
        // another. The cooldown gives time for `apply` to actually land
        // and prevents the dissolve→recreate→dissolve loop.
        $antiRebound = BotAlliancePolicyRules::isWithinAntiRebound($a, $now);
        if ($antiRebound && $createDec['action'] === 'create') {
            botAllianceMergeMetrics($botState, ['alliance_create_anti_rebound_skips' => 1]);
            $a['idle_no_candidate_streak'] = (int) ($a['idle_no_candidate_streak'] ?? 0) + 1;
            $logs[] = 'alliance: create skipped (anti-rebound)';

            return $logs;
        }
        if ($canHard && $createDec['action'] === 'create') {
            $ident = $policy->generateAllianceIdentity($ctx);
            $meta = BotAlliancePolicyRules::buildFounderMeta($ctx);
            $meta['requirements'] = BotAlliancePolicyRules::defaultRequirementsForFounder($ctx);
            $meta['soft_text'] = (string) ($ident['soft_text'] ?? '');
            $json = botAllianceEncodeRequirements($meta);
            $tag = (string) ($ident['tag'] ?? 'B12');
            $name = (string) ($ident['name'] ?? 'Alliance');
            for ($t = 0; $t < 8; $t++) {
                $tryTag = $t === 0 ? $tag : substr((string) $ident['tag'], 0, 4) . (10 + $t);
                $tryTag = substr($tryTag, 0, 8);
                $newId = botAllianceCreate($db, $prefix, $userId, $name, $tryTag, $json, $now);
                if ($newId !== null) {
                    botAllianceMergeMetrics($botState, ['alliances_created' => 1]);
                    $a['since_no_ally_at'] = 0;
                    $a['applications_failed_streak'] = 0;
                    $logs[] = "alliance: create id={$newId} tag={$tryTag}";

                    break;
                }
            }
            if ($logs !== []) {
                return $logs;
            }
        }

        $desperate = ((int) ($ctx['since_no_ally_seconds'] ?? 0) >= (int) BOT_ALLIANCE_DESPERATE_AFTER_SECONDS)
            && ((int) ($ctx['applications_failed_streak'] ?? 0) >= (int) BOT_ALLIANCE_DESPERATE_MIN_FAILED_STREAK);
        if ($desperate) {
            $f2 = BotAlliancePolicyRules::filterCandidatesForApply($ctx, $candidates, true);
            $s2 = BotAlliancePolicyRules::sortCandidatesByScore($ctx, $f2, true);
            $d2 = $policy->decideApply($ctx, $s2);
            if ($d2['action'] === 'apply') {
                $target = (int) ($d2['params']['alliance_id'] ?? 0);
                if ($target > 0 && botAllianceApply($db, $prefix, $userId, $target, 'Bot application (desperate).', $now)) {
                    $a['pending_alliance_id'] = $target;
                    $a['pending_since'] = $now;
                    botAllianceMergeMetrics($botState, ['alliance_applications_sent' => 1, 'alliance_desperate_applies' => 1]);
                    $logs[] = "alliance: apply desperate ally={$target}";
                }

                return $logs;
            }
        }

        botAllianceMergeMetrics($botState, ['alliance_skip_no_candidate' => 1]);
        $a['idle_no_candidate_streak'] = (int) ($a['idle_no_candidate_streak'] ?? 0) + 1;
        if ($a['idle_no_candidate_streak'] % 20 === 0) {
            $logs[] = 'alliance: idle no_candidate';
        }

        return $logs;
    }
}
