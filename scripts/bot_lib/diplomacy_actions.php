<?php

declare(strict_types=1);

/**
 * Bot diplomacy orchestrator. Runs from the owner bot of each alliance
 * each loop. Web-tab proposals (1) are processed every time; inbox +
 * pressure + war review (2–4) are gated by BOT_DIPLO_LEADER_REVIEW_COOLDOWN.
 *
 *  1) Accepts or rejects incoming web-tab proposals in
 *     `alliance_diplomacy_proposal` (NAP / peace), mirroring
 *     DiplomacyController — runs before the leader review cooldown so
 *     human-sent NAPs are not stuck waiting 30s on inbox-only logic.
 *  2) Evaluates incoming inbox proposals ([DIPLO_PEACE], [DIPLO_NAP],
 *     [DIPLO_PEACE_ACK], [DIPLO_NAP_ACK]).
 *  3) Evaluates pressure: declare war on any alliance whose pressure
 *     exceeds the threshold (BOT_DIPLO_PRESSURE_WAR_THRESHOLD).
 *  4) Evaluates active wars: if losing badly, propose peace (with bribe
 *     if affordable, otherwise plain).
 *  5) (NAP proactive proposals between bot alliances live here as a
 *     future hook; for v1 NAP is reactive — only as a response to a
 *     received [DIPLO_NAP] proposal.)
 *
 * The NAP-breach detection sits in this file as well because the attack
 * harvesting flow (scripts/bot_lib/attack.php) calls it directly when
 * an attack lands on a target whose alliance has an active NAP with us.
 *
 * Inbox marker format (single line, parsed loosely):
 *
 *     [DIPLO_PEACE from_ally=12 peer_ally=7 offered=150000]
 *     [DIPLO_NAP   from_ally=12 peer_ally=7 duration=604800]
 *     [DIPLO_PEACE_ACK from_ally=7 peer_ally=12 accepted=1]
 *     [DIPLO_NAP_ACK   from_ally=7 peer_ally=12 accepted=1]
 *
 * The marker is a flat key=value sequence, robust to extra prose.
 */

require_once __DIR__ . '/safety.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/diplomacy.php';
require_once __DIR__ . '/alliance.php';

if (!function_exists('botDiplomacyAllianceOwner')) {
    function botDiplomacyAllianceOwner(mysqli $db, string $prefix, int $allianceId): int
    {
        if ($allianceId <= 0) {
            return 0;
        }
        $tbl = $prefix . 'alliance';
        $res = $db->query(
            "SELECT `alliance_owner` FROM `{$tbl}` WHERE `alliance_id` = {$allianceId} LIMIT 1"
        );
        if (!$res) {
            return 0;
        }
        $row = $res->fetch_assoc();
        $res->free();

        return (int) ($row['alliance_owner'] ?? 0);
    }
}

if (!function_exists('botDiplomacyHandleNapBreach')) {
    /**
     * Called from botAttackHarvestReturns whenever an attack lands. If
     * the attacker and victim alliances have an active NAP, this counts
     * as a breach: the row is wiped and the 24h cooldown is applied to
     * the attacker.
     */
    function botDiplomacyHandleNapBreach(
        mysqli $db,
        string $prefix,
        int $attackerAllianceId,
        int $victimAllianceId,
        ?int $now = null
    ): bool {
        $pair = botDiplomacyNormalisePair($attackerAllianceId, $victimAllianceId);
        if ($pair === null) {
            return false;
        }
        $now = $now ?? time();
        $tbl = $prefix . 'alliance_diplomacy';
        $res = $db->query(
            "SELECT `status` FROM `{$tbl}`
             WHERE `alliance_a` = {$pair[0]} AND `alliance_b` = {$pair[1]}
             LIMIT 1"
        );
        if (!$res) {
            return false;
        }
        $row = $res->fetch_assoc();
        $res->free();
        if (!is_array($row) || ($row['status'] ?? '') !== 'nap') {
            return false;
        }

        return botDiplomacyBreakNap($db, $prefix, $attackerAllianceId, $victimAllianceId, 0, $now);
    }
}

if (!function_exists('botDiplomacyParseMarker')) {
    /**
     * Parses one inbox marker text such as
     * "[DIPLO_PEACE from_ally=12 offered=15000]" into an assoc array
     * with the prefix stripped.
     *
     * @return array<string, int>|null Numeric keys only.
     */
    function botDiplomacyParseMarker(string $text, string $marker): ?array
    {
        $needle = '[' . $marker;
        $pos = stripos($text, $needle);
        if ($pos === false) {
            return null;
        }
        $end = strpos($text, ']', $pos);
        if ($end === false) {
            return null;
        }
        $body = substr($text, $pos + strlen($needle), $end - ($pos + strlen($needle)));
        $out = [];
        if (preg_match_all('/\b([a-z_]+)\s*=\s*(-?\d+)/i', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $out[strtolower((string) $row[1])] = (int) $row[2];
            }
        }

        return $out;
    }
}

if (!function_exists('botDiplomacySendInboxMarker')) {
    /**
     * Sends an alliance-typed message to $toUserId from $fromUser
     * containing the given marker as the last line so a parser can
     * find it without parsing free-form prose.
     */
    function botDiplomacySendInboxMarker(
        mysqli $db,
        string $prefix,
        int $toUserId,
        int $fromUserId,
        string $fromName,
        string $subject,
        string $body,
        string $marker,
        array $payload,
        int $now
    ): void {
        $kv = [];
        foreach ($payload as $k => $v) {
            $kv[] = (string) $k . '=' . (int) $v;
        }
        $markerLine = '[' . $marker . ' ' . implode(' ', $kv) . ']';
        $full = rtrim($body) . "\n\n" . $markerLine;
        botAllianceNotifyInbox($db, $prefix, $toUserId, $fromUserId, $fromName, $subject, $full, $now);
    }
}

if (!function_exists('botDiplomacyEvaluatePressure')) {
    /**
     * For each attacker_alliance with pressure >= threshold against
     * $myAllyId, roll the auto-declare chance and call
     * botDiplomacyDeclareWar. Returns log lines.
     *
     * @return list<string>
     */
    function botDiplomacyEvaluatePressure(
        mysqli $db,
        string $prefix,
        array &$state,
        int $myUserId,
        int $myAllyId,
        int $now
    ): array {
        $logs = [];
        if ($myAllyId <= 0) {
            return $logs;
        }
        $threshold = defined('BOT_DIPLO_PRESSURE_WAR_THRESHOLD')
            ? (int) BOT_DIPLO_PRESSURE_WAR_THRESHOLD
            : 1_000_000;
        $chance = defined('BOT_DIPLO_AUTO_DECLARE_CHANCE_X100')
            ? (int) BOT_DIPLO_AUTO_DECLARE_CHANCE_X100
            : 80;
        if (botDiplomacyCooldownActive($db, $prefix, $myAllyId, 'war_block', $now)) {
            return $logs;
        }
        $rows = botDiplomacyListPressureForVictim($db, $prefix, $myAllyId);
        foreach ($rows as $row) {
            $attacker = (int) $row['attacker_alliance_id'];
            $pressure = (int) $row['pressure'];
            if ($pressure < $threshold) {
                break; // rows are ordered DESC by pressure.
            }
            // Already at war? skip.
            if (botDiplomacyIsAtWar($db, $prefix, $myAllyId, $attacker)) {
                continue;
            }
            $roll = random_int(0, 99);
            if ($roll >= $chance) {
                continue;
            }
            if (botDiplomacyDeclareWar($db, $prefix, $myAllyId, $attacker, $myUserId, $now)) {
                botAllianceMergeMetrics($state, ['diplo_war_declared' => 1]);
                $logs[] = sprintf(
                    'diplo: auto-war ally=%d -> enemy=%d (pressure=%d)',
                    $myAllyId,
                    $attacker,
                    $pressure
                );
            }
        }

        return $logs;
    }
}

if (!function_exists('botDiplomacyEvaluateActiveWars')) {
    /**
     * For each active war where the bot's alliance is on the losing
     * side (see botDiplomacyAllianceIsLosingSideOfWar), propose peace
     * by inbox after a minimum war age and a per-enemy cooldown.
     *
     * @return list<string>
     */
    function botDiplomacyEvaluateActiveWars(
        mysqli $db,
        string $prefix,
        array &$state,
        int $myUserId,
        string $myUserName,
        int $myAllyId,
        int $now
    ): array {
        $logs = [];
        if ($myAllyId <= 0) {
            return $logs;
        }
        if (botDiplomacyCooldownActive($db, $prefix, $myAllyId, 'peace_block', $now)) {
            return $logs;
        }

        $tbl = $prefix . 'alliance_diplomacy';
        $res = $db->query(
            "SELECT `alliance_a`, `alliance_b`, `declared_by`, `since`,
                    `damage_a_to_b`, `damage_b_to_a`
             FROM `{$tbl}`
             WHERE (`alliance_a` = {$myAllyId} OR `alliance_b` = {$myAllyId})
               AND `status` = 'war'"
        );
        if (!$res) {
            return $logs;
        }
        $wars = [];
        while ($row = $res->fetch_assoc()) {
            $wars[] = $row;
        }
        $res->free();

        $minWarAge = defined('BOT_DIPLO_PEACE_MIN_WAR_AGE_SECONDS')
            ? (int) BOT_DIPLO_PEACE_MIN_WAR_AGE_SECONDS
            : 10_800;
        $peaceCooldown = defined('BOT_DIPLO_PEACE_PROPOSE_COOLDOWN_SECONDS')
            ? (int) BOT_DIPLO_PEACE_PROPOSE_COOLDOWN_SECONDS
            : 600;

        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
        $diplo = is_array($quirks['diplomacy'] ?? null) ? $quirks['diplomacy'] : [];
        if (!isset($diplo['peace_offer_at']) || !is_array($diplo['peace_offer_at'])) {
            $diplo['peace_offer_at'] = [];
        }

        foreach ($wars as $w) {
            $a = (int) $w['alliance_a'];
            $b = (int) $w['alliance_b'];
            $other = $a === $myAllyId ? $b : $a;
            $a2b = (int) $w['damage_a_to_b'];
            $b2a = (int) $w['damage_b_to_a'];
            $since = (int) ($w['since'] ?? 0);

            if ($since > 0 && ($now - $since) < $minWarAge) {
                continue;
            }
            if (!botDiplomacyAllianceIsLosingSideOfWar($myAllyId, $a, $b, $a2b, $b2a)) {
                continue;
            }

            $lastOffer = (int) ($diplo['peace_offer_at'][(string) $other] ?? 0);
            if ($lastOffer > 0 && ($now - $lastOffer) < $peaceCooldown) {
                continue;
            }

            $otherOwnerId = botDiplomacyAllianceOwner($db, $prefix, $other);
            if ($otherOwnerId <= 0) {
                continue;
            }

            $offered = botDiplomacyPeaceBribeOfferedByLoser($myAllyId, $a, $b, $a2b, $b2a);

            botDiplomacySendInboxMarker(
                $db,
                $prefix,
                $otherOwnerId,
                $myUserId,
                $myUserName,
                'Peace proposal',
                "Our alliance offers peace. Resource amount transferred: {$offered}.",
                'DIPLO_PEACE',
                [
                    'from_ally' => $myAllyId,
                    'peer_ally' => $other,
                    'offered' => $offered,
                ],
                $now
            );
            botDiplomacyLogAction($db, $prefix, $myAllyId, $other, $myUserId, 'propose_peace', ['offered' => $offered], $now);
            botAllianceMergeMetrics($state, ['diplo_peace_proposed' => 1]);
            $diplo['peace_offer_at'][(string) $other] = $now;
            $logs[] = sprintf(
                'diplo: propose peace ally=%d -> peer=%d offered=%d',
                $myAllyId,
                $other,
                $offered
            );
        }

        $quirks['diplomacy'] = $diplo;
        $state['bot_quirks'] = $quirks;

        return $logs;
    }
}

if (!function_exists('botDiplomacyProcessIncomingMessages')) {
    /**
     * Walks unread alliance-typed messages in the owner's inbox looking
     * for [DIPLO_*] markers. Processes them and marks them read.
     *
     * @return list<string>
     */
    function botDiplomacyProcessIncomingMessages(
        mysqli $db,
        string $prefix,
        array &$state,
        int $myUserId,
        string $myUserName,
        int $myAllyId,
        int $now
    ): array {
        $logs = [];
        if ($myAllyId <= 0) {
            return $logs;
        }
        $msgs = $prefix . 'messages';
        $res = $db->query(
            "SELECT `message_id`, `message_sender`, `message_text`
             FROM `{$msgs}`
             WHERE `message_receiver` = {$myUserId}
               AND `message_type` = 3
               AND `message_read` = 0
               AND INSTR(`message_text`, '[DIPLO_') > 0
             ORDER BY `message_id` ASC
             LIMIT 30"
        );
        if (!$res) {
            return $logs;
        }
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $res->free();

        foreach ($rows as $row) {
            $mid = (int) $row['message_id'];
            $sender = (int) $row['message_sender'];
            $text = (string) $row['message_text'];

            $peace = botDiplomacyParseMarker($text, 'DIPLO_PEACE');
            $nap = botDiplomacyParseMarker($text, 'DIPLO_NAP');
            $peaceAck = botDiplomacyParseMarker($text, 'DIPLO_PEACE_ACK');
            $napAck = botDiplomacyParseMarker($text, 'DIPLO_NAP_ACK');

            if ($peaceAck !== null) {
                // No-op for now: counterparty just confirms what we already
                // wrote. Useful for the audit log but no state change.
                $logs[] = sprintf('diplo: peace ack from user=%d', $sender);
            } elseif ($napAck !== null) {
                $logs[] = sprintf('diplo: nap ack from user=%d', $sender);
            } elseif ($peace !== null) {
                $logs = array_merge(
                    $logs,
                    botDiplomacyHandlePeaceProposal(
                        $db,
                        $prefix,
                        $state,
                        $myUserId,
                        $myUserName,
                        $myAllyId,
                        $sender,
                        $peace,
                        $now
                    )
                );
            } elseif ($nap !== null) {
                $logs = array_merge(
                    $logs,
                    botDiplomacyHandleNapProposal(
                        $db,
                        $prefix,
                        $state,
                        $myUserId,
                        $myUserName,
                        $myAllyId,
                        $sender,
                        $nap,
                        $now
                    )
                );
            }

            $db->query(
                "UPDATE `{$msgs}` SET `message_read` = 1 WHERE `message_id` = {$mid} LIMIT 1"
            );
        }

        return $logs;
    }
}

if (!function_exists('botDiplomacyHandlePeaceProposal')) {
    /**
     * @param array<string, int> $marker
     * @return list<string>
     */
    function botDiplomacyHandlePeaceProposal(
        mysqli $db,
        string $prefix,
        array &$state,
        int $myUserId,
        string $myUserName,
        int $myAllyId,
        int $senderUserId,
        array $marker,
        int $now
    ): array {
        $logs = [];
        $fromAlly = (int) ($marker['from_ally'] ?? 0);
        $peerAlly = (int) ($marker['peer_ally'] ?? 0);
        $offered = (int) ($marker['offered'] ?? 0);
        if ($fromAlly <= 0 || $peerAlly !== $myAllyId) {
            return $logs;
        }
        if (!botDiplomacyIsAtWar($db, $prefix, $myAllyId, $fromAlly)) {
            return $logs;
        }
        $pair = botDiplomacyNormalisePair($myAllyId, $fromAlly);
        $tbl = $prefix . 'alliance_diplomacy';
        $resD = $db->query(
            "SELECT `damage_a_to_b`, `damage_b_to_a` FROM `{$tbl}`
             WHERE `alliance_a` = {$pair[0]} AND `alliance_b` = {$pair[1]} LIMIT 1"
        );
        if (!$resD) {
            return $logs;
        }
        $dRow = $resD->fetch_assoc();
        $resD->free();
        $a2b = (int) ($dRow['damage_a_to_b'] ?? 0);
        $b2a = (int) ($dRow['damage_b_to_a'] ?? 0);
        $bribeRequired = botDiplomacyPeaceBribeRequiredForReceiver($myAllyId, $fromAlly, $a2b, $b2a);
        $accept = $offered >= $bribeRequired && $bribeRequired > 0;

        if ($accept) {
            if (botDiplomacySignPeace(
                $db,
                $prefix,
                $myAllyId,
                $fromAlly,
                $myUserId,
                'accept_peace',
                ['offered' => $offered, 'required' => $bribeRequired, 'bribed' => true],
                $now
            )) {
                botAllianceMergeMetrics($state, [
                    'diplo_peace_accepted' => 1,
                    'diplo_peace_bribed' => 1,
                ]);
                botDiplomacySendInboxMarker(
                    $db,
                    $prefix,
                    $senderUserId,
                    $myUserId,
                    $myUserName,
                    'Peace accepted',
                    'Peace accepted.',
                    'DIPLO_PEACE_ACK',
                    ['from_ally' => $myAllyId, 'peer_ally' => $fromAlly, 'accepted' => 1],
                    $now
                );
                $logs[] = sprintf('diplo: peace accepted (bribe) peer=%d offered=%d', $fromAlly, $offered);
            }
        } else {
            // Without a sufficient bribe, the receiving owner rejects.
            botDiplomacyLogAction(
                $db,
                $prefix,
                $myAllyId,
                $fromAlly,
                $myUserId,
                'reject_peace',
                ['offered' => $offered, 'required' => $bribeRequired],
                $now
            );
            botDiplomacySendInboxMarker(
                $db,
                $prefix,
                $senderUserId,
                $myUserId,
                $myUserName,
                'Peace rejected',
                'Offer insufficient.',
                'DIPLO_PEACE_ACK',
                ['from_ally' => $myAllyId, 'peer_ally' => $fromAlly, 'accepted' => 0],
                $now
            );
            $logs[] = sprintf('diplo: peace rejected peer=%d offered=%d required=%d', $fromAlly, $offered, $bribeRequired);
        }

        return $logs;
    }
}

if (!function_exists('botDiplomacyHandleNapProposal')) {
    /**
     * @param array<string, int> $marker
     * @return list<string>
     */
    function botDiplomacyHandleNapProposal(
        mysqli $db,
        string $prefix,
        array &$state,
        int $myUserId,
        string $myUserName,
        int $myAllyId,
        int $senderUserId,
        array $marker,
        int $now
    ): array {
        $logs = [];
        $fromAlly = (int) ($marker['from_ally'] ?? 0);
        $peerAlly = (int) ($marker['peer_ally'] ?? 0);
        $duration = (int) ($marker['duration'] ?? 0);
        if ($fromAlly <= 0 || $peerAlly !== $myAllyId) {
            return $logs;
        }
        // Refuse if we're at war: peace must come first.
        if (botDiplomacyIsAtWar($db, $prefix, $myAllyId, $fromAlly)) {
            return $logs;
        }
        if (botDiplomacyCooldownActive($db, $prefix, $myAllyId, 'nap_block', $now)) {
            return $logs;
        }
        $okDur = $duration > 0 ? $duration : null;
        if (botDiplomacyUpsertNap($db, $prefix, $myAllyId, $fromAlly, $myUserId, $okDur, $now)) {
            botAllianceMergeMetrics($state, ['diplo_nap_accepted' => 1]);
            botDiplomacySendInboxMarker(
                $db,
                $prefix,
                $senderUserId,
                $myUserId,
                $myUserName,
                'Pact accepted',
                'Pact accepted.',
                'DIPLO_NAP_ACK',
                ['from_ally' => $myAllyId, 'peer_ally' => $fromAlly, 'accepted' => 1],
                $now
            );
            $logs[] = sprintf('diplo: nap accepted peer=%d duration=%d', $fromAlly, $okDur ?? 0);
        }

        return $logs;
    }
}

if (!function_exists('botDiplomacyProposalTableExists')) {
    function botDiplomacyProposalTableExists(mysqli $db, string $prefix): bool
    {
        static $cache = [];

        if (array_key_exists($prefix, $cache)) {
            return $cache[$prefix];
        }

        $physical = $db->real_escape_string($prefix . 'alliance_diplomacy_proposal');
        $res = $db->query(
            "SELECT COUNT(*) AS `c` FROM information_schema.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE()
               AND `TABLE_NAME` = '{$physical}'
             LIMIT 1"
        );
        if (!$res) {
            return false;
        }
        $row = $res->fetch_assoc();
        $res->free();
        $cache[$prefix] = is_array($row) && (int) ($row['c'] ?? 0) > 0;

        return $cache[$prefix];
    }
}

if (!function_exists('botDiplomacyProcessIncomingWebProposals')) {
    /**
     * NAP/peace rows created via the Diplomacy tab (`alliance_diplomacy_proposal`).
     * Mirrors the web accept/reject behaviour in DiplomacyController.
     *
     * @return list<string>
     */
    function botDiplomacyProcessIncomingWebProposals(
        mysqli $db,
        string $prefix,
        array &$state,
        int $myUserId,
        int $myAllyId,
        int $now
    ): array {
        $logs = [];
        if ($myAllyId <= 0 || !botDiplomacyProposalTableExists($db, $prefix)) {
            return $logs;
        }

        $tbl = $prefix . 'alliance_diplomacy_proposal';
        $res = $db->query(
            "SELECT `proposal_id`, `from_alliance_id`, `kind`, `payload`
             FROM `{$tbl}`
             WHERE `to_alliance_id` = {$myAllyId}
               AND `expires_at` > {$now}
             ORDER BY `proposal_id` ASC
             LIMIT 20"
        );
        if (!$res) {
            return $logs;
        }
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $res->free();

        foreach ($rows as $row) {
            $pid = (int) $row['proposal_id'];
            $fromAlly = (int) $row['from_alliance_id'];
            $kind = (string) ($row['kind'] ?? '');
            $payload = [];
            if (!empty($row['payload'])) {
                $decoded = json_decode((string) $row['payload'], true);
                $payload = is_array($decoded) ? $decoded : [];
            }

            if ($fromAlly <= 0 || $fromAlly === $myAllyId) {
                $db->query("DELETE FROM `{$tbl}` WHERE `proposal_id` = {$pid} LIMIT 1");

                continue;
            }

            if ($kind === 'nap') {
                if (botDiplomacyIsAtWar($db, $prefix, $myAllyId, $fromAlly)) {
                    $db->query("DELETE FROM `{$tbl}` WHERE `proposal_id` = {$pid} LIMIT 1");
                    $logs[] = sprintf('diplo: web nap dropped (war) proposal=%d peer=%d', $pid, $fromAlly);

                    continue;
                }
                if (botDiplomacyCooldownActive($db, $prefix, $myAllyId, 'nap_block', $now)
                    || botDiplomacyCooldownActive($db, $prefix, $fromAlly, 'nap_block', $now)
                ) {
                    $logs[] = sprintf('diplo: web nap deferred (nap_block) proposal=%d peer=%d', $pid, $fromAlly);

                    continue;
                }
                $durSec = (int) ($payload['duration_sec'] ?? 0);
                $dur = $durSec > 0 ? $durSec : null;
                if (botDiplomacyUpsertNap($db, $prefix, $myAllyId, $fromAlly, $myUserId, $dur, $now)) {
                    $db->query("DELETE FROM `{$tbl}` WHERE `proposal_id` = {$pid} LIMIT 1");
                    botAllianceMergeMetrics($state, ['diplo_nap_accepted' => 1]);
                    $logs[] = sprintf('diplo: web nap accepted proposal=%d peer=%d', $pid, $fromAlly);
                } else {
                    $logs[] = sprintf('diplo: web nap accept failed proposal=%d peer=%d', $pid, $fromAlly);
                }

                continue;
            }

            if ($kind === 'peace') {
                if (!botDiplomacyIsAtWar($db, $prefix, $myAllyId, $fromAlly)) {
                    $db->query("DELETE FROM `{$tbl}` WHERE `proposal_id` = {$pid} LIMIT 1");
                    $logs[] = sprintf('diplo: web peace dropped (no war) proposal=%d peer=%d', $pid, $fromAlly);

                    continue;
                }
                $tblD = $prefix . 'alliance_diplomacy';
                $pair = botDiplomacyNormalisePair($myAllyId, $fromAlly);
                if ($pair === null) {
                    $db->query("DELETE FROM `{$tbl}` WHERE `proposal_id` = {$pid} LIMIT 1");

                    continue;
                }
                $resD = $db->query(
                    "SELECT `damage_a_to_b`, `damage_b_to_a` FROM `{$tblD}`
                     WHERE `status` = 'war'
                       AND `alliance_a` = {$pair[0]} AND `alliance_b` = {$pair[1]}
                     LIMIT 1"
                );
                if (!$resD) {
                    continue;
                }
                $dRow = $resD->fetch_assoc();
                $resD->free();
                if (!is_array($dRow)) {
                    $db->query("DELETE FROM `{$tbl}` WHERE `proposal_id` = {$pid} LIMIT 1");

                    continue;
                }
                $a2b = (int) ($dRow['damage_a_to_b'] ?? 0);
                $b2a = (int) ($dRow['damage_b_to_a'] ?? 0);
                $offered = (int) ($payload['offered'] ?? 0);
                $required = botDiplomacyPeaceBribeRequiredForReceiver($myAllyId, $fromAlly, $a2b, $b2a);
                if ($offered < $required || $required <= 0) {
                    botDiplomacyLogAction(
                        $db,
                        $prefix,
                        $myAllyId,
                        $fromAlly,
                        $myUserId,
                        'reject_peace',
                        ['offered' => $offered, 'required' => $required, 'source' => 'web_proposal_auto'],
                        $now
                    );
                    $db->query("DELETE FROM `{$tbl}` WHERE `proposal_id` = {$pid} LIMIT 1");
                    $logs[] = sprintf(
                        'diplo: web peace rejected proposal=%d peer=%d offered=%d required=%d',
                        $pid,
                        $fromAlly,
                        $offered,
                        $required
                    );

                    continue;
                }
                if (botDiplomacySignPeace(
                    $db,
                    $prefix,
                    $myAllyId,
                    $fromAlly,
                    $myUserId,
                    'accept_peace',
                    ['offered' => $offered, 'required' => $required, 'source' => 'web_proposal_auto'],
                    $now
                )) {
                    $db->query("DELETE FROM `{$tbl}` WHERE `proposal_id` = {$pid} LIMIT 1");
                    botAllianceMergeMetrics($state, [
                        'diplo_peace_accepted' => 1,
                        'diplo_peace_bribed' => 1,
                    ]);
                    $logs[] = sprintf('diplo: web peace accepted proposal=%d peer=%d', $pid, $fromAlly);
                }

                continue;
            }

            $db->query("DELETE FROM `{$tbl}` WHERE `proposal_id` = {$pid} LIMIT 1");
            $logs[] = sprintf('diplo: web proposal dropped (unknown kind) proposal=%d', $pid);
        }

        return $logs;
    }
}

if (!function_exists('botDiplomacyTick')) {
    /**
     * Owner-bot diplomacy tick. Returns log lines.
     *
     * @return list<string>
     */
    function botDiplomacyTick(
        mysqli $db,
        string $prefix,
        array &$state,
        array $user,
        ?int $now = null
    ): array {
        $logs = [];
        $now = $now ?? time();
        $myUserId = (int) ($user['user_id'] ?? 0);
        $myAllyId = (int) ($user['user_ally_id'] ?? 0);
        $myUserName = (string) ($user['user_name'] ?? 'bot');
        if ($myUserId <= 0 || $myAllyId <= 0) {
            return $logs;
        }
        $ownerOf = botDiplomacyAllianceOwner($db, $prefix, $myAllyId);
        if ($ownerOf !== $myUserId) {
            return $logs;
        }

        $logs = array_merge(
            $logs,
            botDiplomacyProcessIncomingWebProposals($db, $prefix, $state, $myUserId, $myAllyId, $now)
        );

        // Per-owner review cooldown stored under bot_quirks.diplomacy.
        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
        $diplo = is_array($quirks['diplomacy'] ?? null) ? $quirks['diplomacy'] : [];
        $cooldown = defined('BOT_DIPLO_LEADER_REVIEW_COOLDOWN')
            ? (int) BOT_DIPLO_LEADER_REVIEW_COOLDOWN
            : 30;
        $lastReview = (int) ($diplo['last_review_at'] ?? 0);
        if ($lastReview > 0 && ($now - $lastReview) < $cooldown) {
            return $logs;
        }

        $logs = array_merge(
            $logs,
            botDiplomacyProcessIncomingMessages($db, $prefix, $state, $myUserId, $myUserName, $myAllyId, $now)
        );
        $logs = array_merge(
            $logs,
            botDiplomacyEvaluatePressure($db, $prefix, $state, $myUserId, $myAllyId, $now)
        );
        $logs = array_merge(
            $logs,
            botDiplomacyEvaluateActiveWars($db, $prefix, $state, $myUserId, $myUserName, $myAllyId, $now)
        );

        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : $quirks;
        $diplo = is_array($quirks['diplomacy'] ?? null) ? $quirks['diplomacy'] : $diplo;
        $diplo['last_review_at'] = $now;
        $quirks['diplomacy'] = $diplo;
        $state['bot_quirks'] = $quirks;

        return $logs;
    }
}
