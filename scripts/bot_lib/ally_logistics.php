<?php

declare(strict_types=1);

/**
 * Ally logistics: bot→bot transports within the same alliance, templated
 * resource requests bot→human, and fulfilment of human→bot requests via
 * a fixed [ALLY_REQ] line (LLM can replace wording later; keep the tag).
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/safety.php';
require_once __DIR__ . '/transport.php';
require_once __DIR__ . '/alliance.php';
require_once __DIR__ . '/heat.php';

if (!function_exists('botAllyLogisticsEnsureQuirk')) {
    /**
     * @param array<string, mixed> $state
     * @return array<string, int>
     */
    function botAllyLogisticsEnsureQuirk(array &$state): array
    {
        botAllianceInitQuirks($state);
        $q = &$state['bot_quirks'];
        if (!isset($q['ally_logistics']) || !is_array($q['ally_logistics'])) {
            $q['ally_logistics'] = [
                'last_need_msg_at' => 0,
                'last_processed_message_id' => 0,
            ];
        }

        return $q['ally_logistics'];
    }
}

if (!function_exists('botAllyLogisticsParseHumanRequest')) {
    /**
     * Parses a human-written ally resource request body.
     *
     * Expected marker: line containing [ALLY_REQ] followed by key=value
     * pairs, e.g. "[ALLY_REQ] metal=100000 crystal=50000 deut=0".
     *
     * @return array{metal:int, crystal:int, deuterium:int}|null
     */
    function botAllyLogisticsParseHumanRequest(string $text): ?array
    {
        if (stripos($text, '[ALLY_REQ]') === false) {
            return null;
        }
        $cap = defined('BOT_ALLY_HUMAN_REQ_PER_RESOURCE_CAP')
            ? (int) BOT_ALLY_HUMAN_REQ_PER_RESOURCE_CAP
            : 50_000_000;
        $out = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];
        if (!preg_match_all('/\b(metal|crystal|deut(?:erium)?)\s*=\s*(\d+)/i', $text, $m, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($m as $row) {
            $key = strtolower((string) $row[1]);
            $val = min($cap, max(0, (int) ($row[2] ?? 0)));
            if ($key === 'metal') {
                $out['metal'] = $val;
            } elseif ($key === 'crystal') {
                $out['crystal'] = $val;
            } elseif ($key === 'deut' || $key === 'deuterium') {
                $out['deuterium'] = $val;
            }
        }
        if ($out['metal'] + $out['crystal'] + $out['deuterium'] < 1) {
            return null;
        }

        return $out;
    }
}

if (!function_exists('botAllyLogisticsLoadAllyBotPlanets')) {
    /**
     * Planets belonging to other bot accounts in the same alliance.
     *
     * @return list<array<string, mixed>>
     */
    function botAllyLogisticsLoadAllyBotPlanets(
        mysqli $db,
        string $prefix,
        int $myUserId,
        int $allyId
    ): array {
        if ($allyId <= 0 || $myUserId <= 0) {
            return [];
        }
        $p = $prefix . 'planets';
        $u = $prefix . 'users';
        $bs = $prefix . 'bot_state';
        $sql = "SELECT p.*, u.`user_id` AS `planet_user_id`
                FROM `{$p}` AS p
                INNER JOIN `{$u}` AS u ON u.`user_id` = p.`planet_user_id`
                INNER JOIN `{$bs}` AS bs ON bs.`bot_user_id` = u.`user_id`
                WHERE u.`user_ally_id` = {$allyId}
                  AND u.`user_id` != {$myUserId}
                  AND (p.`planet_destroyed` = 0 OR p.`planet_destroyed` = '0')";
        $res = $db->query($sql);
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        }

        return $rows;
    }
}

if (!function_exists('botAllyLogisticsLoadHumanAllyUserIds')) {
    /**
     * @return list<int>
     */
    function botAllyLogisticsLoadHumanAllyUserIds(
        mysqli $db,
        string $prefix,
        int $myUserId,
        int $allyId
    ): array {
        if ($allyId <= 0) {
            return [];
        }
        $u = $prefix . 'users';
        $bs = $prefix . 'bot_state';
        $sql = "SELECT u.`user_id` AS id
                FROM `{$u}` AS u
                LEFT JOIN `{$bs}` AS bs ON bs.`bot_user_id` = u.`user_id`
                WHERE u.`user_ally_id` = {$allyId}
                  AND u.`user_id` != {$myUserId}
                  AND bs.`bot_user_id` IS NULL";
        $res = $db->query($sql);
        $ids = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int) ($row['id'] ?? 0);
            }
            $res->free();
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }
}

if (!function_exists('botAllyLogisticsLoadPrimaryPlanetForUser')) {
    /**
     * First non-destroyed planet of a user (lowest planet_id).
     *
     * @return array<string, mixed>|null
     */
    function botAllyLogisticsLoadPrimaryPlanetForUser(
        mysqli $db,
        string $prefix,
        int $userId
    ): ?array {
        if ($userId <= 0) {
            return null;
        }
        $p = $prefix . 'planets';
        $sql = "SELECT * FROM `{$p}`
                WHERE `planet_user_id` = {$userId}
                  AND (`planet_destroyed` = 0 OR `planet_destroyed` = '0')
                ORDER BY `planet_id` ASC
                LIMIT 1";
        $res = $db->query($sql);
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        $res->free();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('botAllyLogisticsTryFulfilHumanRequest')) {
    /**
     * @return list<string>
     */
    function botAllyLogisticsTryFulfilHumanRequest(
        mysqli $db,
        string $prefix,
        array $user,
        array $planets,
        array $researchRow,
        array $pricelist,
        array &$state,
        float $universeSpeed,
        int $allyId
    ): array {
        $logs = [];
        $myId = (int) ($user['user_id'] ?? 0);
        if ($myId <= 0 || $allyId <= 0) {
            return $logs;
        }
        botAllyLogisticsEnsureQuirk($state);
        $al = &$state['bot_quirks']['ally_logistics'];
        $lastMid = (int) ($al['last_processed_message_id'] ?? 0);
        $msg = $prefix . 'messages';
        $sql = "SELECT `message_id`, `message_sender`, `message_text`
                FROM `{$msg}`
                WHERE `message_receiver` = {$myId}
                  AND `message_type` = 3
                  AND `message_read` = 0
                  AND INSTR(`message_text`, '[ALLY_REQ]') > 0
                  AND `message_id` > {$lastMid}
                ORDER BY `message_id` ASC
                LIMIT 5";
        $res = $db->query($sql);
        if (!$res) {
            return $logs;
        }
        $uTbl = $prefix . 'users';
        while ($row = $res->fetch_assoc()) {
            $mid = (int) ($row['message_id'] ?? 0);
            $sender = (int) ($row['message_sender'] ?? 0);
            $text = (string) ($row['message_text'] ?? '');
            if ($mid <= 0 || $sender <= 0) {
                continue;
            }
            $chk = $db->query(
                "SELECT `user_ally_id` FROM `{$uTbl}` WHERE `user_id` = {$sender} LIMIT 1"
            );
            if (!$chk) {
                continue;
            }
            $urow = $chk->fetch_assoc();
            $chk->free();
            if ((int) ($urow['user_ally_id'] ?? 0) !== $allyId) {
                continue;
            }
            $want = botAllyLogisticsParseHumanRequest($text);
            if ($want === null) {
                continue;
            }
            $dest = botAllyLogisticsLoadPrimaryPlanetForUser($db, $prefix, $sender);
            if ($dest === null) {
                $db->query("UPDATE `{$msg}` SET `message_read` = 1 WHERE `message_id` = {$mid} LIMIT 1");
                $al['last_processed_message_id'] = max($lastMid, $mid);

                continue;
            }
            $need = [
                'metal' => (int) $want['metal'],
                'crystal' => (int) $want['crystal'],
                'deuterium' => (int) $want['deuterium'],
                'sources' => ['ally_req'],
            ];
            $rolesMap = botTransportRolesMap($state, $planets);
            $personality = (string) ($state['bot_personality'] ?? 'minero');
            $bestScore = -INF;
            $bestPlan = null;
            foreach ($planets as $sourcePlanet) {
                $sid = (int) $sourcePlanet['planet_id'];
                $role = $rolesMap[$sid] ?? 'support';
                $surplus = botTransportPlanetSurplus($sourcePlanet, $pricelist, $personality, $role);
                if ($surplus['metal'] + $surplus['crystal'] + $surplus['deuterium'] < BOT_TRANSPORT_MIN_PAYLOAD) {
                    continue;
                }
                $fleetRow = botTransportFleetByPlanet($db, $prefix, [$sid]);
                $fleet = $fleetRow[$sid] ?? null;
                if ($fleet === null || ($fleet['small'] < 1 && $fleet['big'] < 1)) {
                    continue;
                }
                $deficit = $need['metal'] + $need['crystal'] + $need['deuterium'];
                $distance = botTravelDistance(
                    [
                        'galaxy' => (int) $sourcePlanet['planet_galaxy'],
                        'system' => (int) $sourcePlanet['planet_system'],
                        'planet' => (int) $sourcePlanet['planet_planet'],
                    ],
                    [
                        'galaxy' => (int) $dest['planet_galaxy'],
                        'system' => (int) $dest['planet_system'],
                        'planet' => (int) $dest['planet_planet'],
                    ]
                );
                $score = $deficit / max(1.0, log(max(2, $distance)));
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPlan = [
                        'source' => $sourcePlanet,
                        'dest' => $dest,
                        'need' => $need,
                        'surplus' => $surplus,
                        'fleet' => $fleet,
                        'score' => $score,
                        'reason' => 'ally_human_req',
                    ];
                }
            }
            if ($bestPlan !== null) {
                $bestLog = botTransportExecutePlan($db, $prefix, $user, $bestPlan, $researchRow, $universeSpeed);
            } else {
                $bestLog = null;
            }
            if ($bestLog !== null) {
                $logs[] = 'ally: ' . $bestLog . " human_sender={$sender}";
                botAllianceMergeMetrics($state, ['ally_human_request_fulfilled' => 1]);
                $db->query("UPDATE `{$msg}` SET `message_read` = 1 WHERE `message_id` = {$mid} LIMIT 1");
                $al['last_processed_message_id'] = max($lastMid, $mid);
                $cd = botTransportLoadCooldowns($state);
                $cd[(int) $bestPlan['source']['planet_id']] = time();
                botTransportSaveCooldowns($db, $prefix, $state, $cd);
                $res->free();

                return $logs;
            }
            $db->query("UPDATE `{$msg}` SET `message_read` = 1 WHERE `message_id` = {$mid} LIMIT 1");
            $al['last_processed_message_id'] = max($lastMid, $mid);
        }
        $res->free();

        return $logs;
    }
}

if (!function_exists('botAllyLogisticsRunBotToAllyBots')) {
    /**
     * @param array<int, array<string, mixed>> $planets
     * @return list<string>
     */
    function botAllyLogisticsRunBotToAllyBots(
        mysqli $db,
        string $prefix,
        array $user,
        array $planets,
        array $researchRow,
        array $pricelist,
        array &$state,
        float $universeSpeed,
        int $allyId
    ): array {
        $logs = [];
        $myId = (int) ($user['user_id'] ?? 0);
        if ($myId <= 0 || $allyId <= 0 || count($planets) < 1) {
            return $logs;
        }
        $allyPlanets = botAllyLogisticsLoadAllyBotPlanets($db, $prefix, $myId, $allyId);
        if ($allyPlanets === []) {
            return $logs;
        }
        $minNeed = defined('BOT_ALLY_TRANSPORT_MIN_ALLY_NEED')
            ? (int) BOT_ALLY_TRANSPORT_MIN_ALLY_NEED
            : 25_000;
        $maxPer = defined('BOT_ALLY_TRANSPORT_MAX_PER_LOOP')
            ? (int) BOT_ALLY_TRANSPORT_MAX_PER_LOOP
            : 2;

        $destCandidates = [];
        foreach ($allyPlanets as $ap) {
            $need = botTransportPlanetNeed($ap, $pricelist);
            if ($need === null) {
                continue;
            }
            $def = (int) $need['metal'] + (int) $need['crystal'] + (int) $need['deuterium'];
            if ($def < $minNeed) {
                continue;
            }
            $destCandidates[] = ['planet' => $ap, 'need' => $need, 'def' => $def];
        }
        if ($destCandidates === []) {
            return $logs;
        }
        usort($destCandidates, static fn (array $a, array $b): int => $b['def'] <=> $a['def']);

        $rolesMap = botTransportRolesMap($state, $planets);
        $personality = (string) ($state['bot_personality'] ?? 'minero');
        $cooldowns = botTransportLoadCooldowns($state);
        $coolSec = botTransportPersonalityCooldown($personality);
        $now = time();
        $planetIds = array_map(static fn (array $p) => (int) $p['planet_id'], $planets);
        $fleetByPlanet = botTransportFleetByPlanet($db, $prefix, $planetIds);

        $executed = 0;
        foreach ($destCandidates as $dc) {
            if ($executed >= $maxPer) {
                break;
            }
            $destPlanet = $dc['planet'];
            $need = $dc['need'];
            $bestScore = -INF;
            $bestPlan = null;
            foreach ($planets as $sourcePlanet) {
                $sid = (int) $sourcePlanet['planet_id'];
                if (isset($cooldowns[$sid]) && ($now - (int) $cooldowns[$sid]) < $coolSec) {
                    continue;
                }
                $role = $rolesMap[$sid] ?? 'support';
                $surplus = botTransportPlanetSurplus($sourcePlanet, $pricelist, $personality, $role);
                if ($surplus['metal'] + $surplus['crystal'] + $surplus['deuterium'] < BOT_TRANSPORT_MIN_PAYLOAD) {
                    continue;
                }
                $fleet = $fleetByPlanet[$sid] ?? null;
                if ($fleet === null || ($fleet['small'] < 1 && $fleet['big'] < 1)) {
                    continue;
                }
                $distance = botTravelDistance(
                    [
                        'galaxy' => (int) $sourcePlanet['planet_galaxy'],
                        'system' => (int) $sourcePlanet['planet_system'],
                        'planet' => (int) $sourcePlanet['planet_planet'],
                    ],
                    [
                        'galaxy' => (int) $destPlanet['planet_galaxy'],
                        'system' => (int) $destPlanet['planet_system'],
                        'planet' => (int) $destPlanet['planet_planet'],
                    ]
                );
                $deficit = (int) $need['metal'] + (int) $need['crystal'] + (int) $need['deuterium'];
                $score = $deficit / max(1.0, log(max(2, $distance)));
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPlan = [
                        'source' => $sourcePlanet,
                        'dest' => $destPlanet,
                        'need' => $need,
                        'surplus' => $surplus,
                        'fleet' => $fleet,
                        'score' => $score,
                        'reason' => 'ally_bot',
                    ];
                }
            }
            if ($bestPlan === null) {
                continue;
            }
            $log = botTransportExecutePlan($db, $prefix, $user, $bestPlan, $researchRow, $universeSpeed);
            if ($log !== null) {
                $logs[] = 'ally: ' . $log;
                $executed++;
                botAllianceMergeMetrics($state, ['ally_bot_transports_sent' => 1]);
                $dstUid = (int) ($bestPlan['dest']['planet_user_id'] ?? 0);
                if ($dstUid > 0) {
                    botHeatOnHelpReceived($db, $prefix, $dstUid, $myId, $now);
                }
                $cooldowns[(int) $bestPlan['source']['planet_id']] = $now;
            }
        }
        if ($executed > 0) {
            botTransportSaveCooldowns($db, $prefix, $state, $cooldowns);
        }

        return $logs;
    }
}

if (!function_exists('botAllyLogisticsMaybeRequestHumans')) {
    /**
     * @param array<int, array<string, mixed>> $planets
     */
    function botAllyLogisticsMaybeRequestHumans(
        mysqli $db,
        string $prefix,
        array $user,
        array $planets,
        array $pricelist,
        array &$state,
        int $allyId
    ): ?string {
        $myId = (int) ($user['user_id'] ?? 0);
        if ($myId <= 0 || $allyId <= 0) {
            return null;
        }
        $humans = botAllyLogisticsLoadHumanAllyUserIds($db, $prefix, $myId, $allyId);
        if ($humans === []) {
            return null;
        }
        botAllyLogisticsEnsureQuirk($state);
        $al = &$state['bot_quirks']['ally_logistics'];
        $now = time();
        $cool = defined('BOT_ALLY_MSG_TO_HUMAN_COOLDOWN_SECONDS')
            ? (int) BOT_ALLY_MSG_TO_HUMAN_COOLDOWN_SECONDS
            : 43_200;
        if ($now - (int) ($al['last_need_msg_at'] ?? 0) < $cool) {
            return null;
        }
        $minDef = defined('BOT_ALLY_MSG_TO_HUMAN_MIN_DEFICIT')
            ? (int) BOT_ALLY_MSG_TO_HUMAN_MIN_DEFICIT
            : 150_000;
        $worst = null;
        $worstNeed = null;
        $worstSum = 0;
        foreach ($planets as $p) {
            $n = botTransportPlanetNeed($p, $pricelist);
            if ($n === null) {
                continue;
            }
            $s = (int) $n['metal'] + (int) $n['crystal'] + (int) $n['deuterium'];
            if ($s > $worstSum) {
                $worstSum = $s;
                $worst = $p;
                $worstNeed = $n;
            }
        }
        if ($worst === null || $worstNeed === null || $worstSum < $minDef) {
            return null;
        }
        $targetHuman = $humans[array_rand($humans)];
        $g = (int) $worst['planet_galaxy'];
        $s = (int) $worst['planet_system'];
        $pp = (int) $worst['planet_planet'];
        $t = (int) ($worst['planet_type'] ?? 1);
        $nm = (int) ($worstNeed['metal'] ?? 0);
        $nc = (int) ($worstNeed['crystal'] ?? 0);
        $nd = (int) ($worstNeed['deuterium'] ?? 0);
        $name = (string) ($user['user_name'] ?? 'bot');
        $body = "Solicitud automática de un aliado (bot) / Automatic ally (bot) request\r\n\r\n"
            . "Coordenadas destino / Destination: {$g}:{$s}:{$pp} (tipo/type {$t})\r\n"
            . "Necesito aprox. / Need approx.: Metal {$nm}, Cristal {$nc}, Deuterio {$nd}\r\n\r\n"
            . "[ALLY_BOT_NEEDS v=1 metal={$nm} crystal={$nc} deut={$nd} g={$g} s={$s} p={$pp} t={$t}]";
        botAllianceNotifyInbox(
            $db,
            $prefix,
            $targetHuman,
            $myId,
            $name,
            '[Alianza] Solicitud de recursos / Resource request',
            $body,
            $now
        );
        $al['last_need_msg_at'] = $now;
        botAllianceMergeMetrics($state, ['ally_human_need_requests_sent' => 1]);

        return "ally: need-msg to_human={$targetHuman} g={$g}:{$s}:{$pp} m={$nm} c={$nc} d={$nd}";
    }
}

if (!function_exists('botAllyLogisticsRun')) {
    /**
     * @param array<int, array<string, mixed>> $planets
     * @return list<string>
     */
    function botAllyLogisticsRun(
        mysqli $db,
        string $prefix,
        array $user,
        array $planets,
        array $researchRow,
        array $pricelist,
        array &$state,
        float $universeSpeed
    ): array {
        $logs = [];
        $allyId = (int) ($user['user_ally_id'] ?? 0);
        if ($allyId <= 0) {
            return $logs;
        }
        botAllyLogisticsEnsureQuirk($state);

        foreach (botAllyLogisticsTryFulfilHumanRequest(
            $db,
            $prefix,
            $user,
            $planets,
            $researchRow,
            $pricelist,
            $state,
            $universeSpeed,
            $allyId
        ) as $line) {
            $logs[] = $line;
        }

        foreach (botAllyLogisticsRunBotToAllyBots(
            $db,
            $prefix,
            $user,
            $planets,
            $researchRow,
            $pricelist,
            $state,
            $universeSpeed,
            $allyId
        ) as $line) {
            $logs[] = $line;
        }

        $needLine = botAllyLogisticsMaybeRequestHumans($db, $prefix, $user, $planets, $pricelist, $state, $allyId);
        if ($needLine !== null) {
            $logs[] = $needLine;
        }

        return $logs;
    }
}
