<?php

declare(strict_types=1);

/**
 * Bot ACS attack module.
 *
 * Two responsibilities:
 *   1. Leader path: the bot opens an ACS group against a planet whose
 *      owner belongs to an alliance currently at war with the bot's
 *      alliance (xgp_alliance_diplomacy). It INSERTs xgp_acs +
 *      xgp_acs_members, then launches its own attack fleet with
 *      fleet_mission=1 and fleet_group=acs_id.
 *
 *   2. Joiner path: the bot scans for OPEN ACS groups whose leader is a
 *      bot in the same alliance and whose target is enemy (at war). If
 *      it can reach the target before the leader's fleet_end_time it
 *      joins by INSERTing xgp_acs_members + an ACS fleet (mission 2)
 *      with the leader's fleet_end_time.
 *
 * Limits are in safety.php (BOT_ACS_*). All inserts go through
 * botFleetInsertTransactional so fleet/ships/deut writes are atomic.
 */

require_once __DIR__ . '/safety.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/travel.php';
require_once __DIR__ . '/diplomacy.php';
require_once __DIR__ . '/attack.php';
require_once __DIR__ . '/alliance.php';

if (!function_exists('botAcsRandomCode')) {
    function botAcsRandomCode(): string
    {
        return 'BG' . random_int(100000, 999_999_999);
    }
}

if (!function_exists('botAcsAllyId')) {
    function botAcsAllyId(array $user): int
    {
        return (int) ($user['user_ally_id'] ?? 0);
    }
}

if (!function_exists('botAcsCountOpenGroupsForUser')) {
    /**
     * How many ACS groups this bot already leads (rows in xgp_acs with
     * acs_owner = userId).
     */
    function botAcsCountOpenGroupsForUser(mysqli $db, string $prefix, int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $tbl = $prefix . 'acs';
        $res = $db->query("SELECT COUNT(*) AS c FROM `{$tbl}` WHERE `acs_owner` = {$userId}");
        if (!$res) {
            return 0;
        }
        $row = $res->fetch_assoc();
        $res->free();

        return (int) ($row['c'] ?? 0);
    }
}

if (!function_exists('botAcsListOpenGroupsLedByAllyBots')) {
    /**
     * Returns the open ACS groups whose owner belongs to an ally bot
     * (excluding the current user), together with the leader's earliest
     * fleet_end_time of the group and the current member count.
     *
     * @return list<array{
     *     acs_id:int, acs_name:string, acs_owner:int,
     *     acs_galaxy:int, acs_system:int, acs_planet:int, acs_planet_type:int,
     *     leader_end_time:int, member_count:int
     * }>
     */
    function botAcsListOpenGroupsLedByAllyBots(
        mysqli $db,
        string $prefix,
        int $myUserId,
        int $myAllyId
    ): array {
        if ($myAllyId <= 0 || $myUserId <= 0) {
            return [];
        }
        $acs = $prefix . 'acs';
        $u = $prefix . 'users';
        $bs = $prefix . 'bot_state';
        $am = $prefix . 'acs_members';
        $fl = $prefix . 'fleets';
        $now = time();
        $sql = "
            SELECT
                a.`acs_id`, a.`acs_name`, a.`acs_owner`,
                a.`acs_galaxy`, a.`acs_system`, a.`acs_planet`, a.`acs_planet_type`,
                (SELECT MIN(f.`fleet_end_time`) FROM `{$fl}` f
                 WHERE f.`fleet_group` = a.`acs_id`
                   AND f.`fleet_owner` = a.`acs_owner`
                   AND f.`fleet_end_time` > {$now}) AS `leader_end_time`,
                (SELECT COUNT(*) FROM `{$am}` m WHERE m.`acs_group_id` = a.`acs_id`) AS `member_count`
            FROM `{$acs}` a
            INNER JOIN `{$u}` ou ON ou.`user_id` = a.`acs_owner`
            INNER JOIN `{$bs}` ob ON ob.`bot_user_id` = a.`acs_owner`
            WHERE ou.`user_ally_id` = {$myAllyId}
              AND a.`acs_owner` <> {$myUserId}";
        $res = $db->query($sql);
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $leader = (int) ($row['leader_end_time'] ?? 0);
            if ($leader <= 0) {
                continue;
            }
            $out[] = [
                'acs_id' => (int) $row['acs_id'],
                'acs_name' => (string) $row['acs_name'],
                'acs_owner' => (int) $row['acs_owner'],
                'acs_galaxy' => (int) $row['acs_galaxy'],
                'acs_system' => (int) $row['acs_system'],
                'acs_planet' => (int) $row['acs_planet'],
                'acs_planet_type' => (int) $row['acs_planet_type'],
                'leader_end_time' => $leader,
                'member_count' => (int) ($row['member_count'] ?? 0),
            ];
        }
        $res->free();

        return $out;
    }
}

if (!function_exists('botAcsTargetEnemyUserId')) {
    /**
     * Returns the user_id of the planet at the given coords, or 0 if the
     * planet doesn't exist or is destroyed.
     */
    function botAcsTargetEnemyUserId(
        mysqli $db,
        string $prefix,
        int $g,
        int $s,
        int $p,
        int $t
    ): int {
        $tbl = $prefix . 'planets';
        $sql = "SELECT `planet_user_id`
                FROM `{$tbl}`
                WHERE `planet_galaxy` = {$g}
                  AND `planet_system` = {$s}
                  AND `planet_planet` = {$p}
                  AND `planet_type` = {$t}
                  AND (`planet_destroyed` = 0 OR `planet_destroyed` = '0')
                LIMIT 1";
        $res = $db->query($sql);
        if (!$res) {
            return 0;
        }
        $row = $res->fetch_assoc();
        $res->free();

        return (int) ($row['planet_user_id'] ?? 0);
    }
}

if (!function_exists('botAcsUserAllyId')) {
    function botAcsUserAllyId(mysqli $db, string $prefix, int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $tbl = $prefix . 'users';
        $res = $db->query("SELECT `user_ally_id` FROM `{$tbl}` WHERE `user_id` = {$userId} LIMIT 1");
        if (!$res) {
            return 0;
        }
        $row = $res->fetch_assoc();
        $res->free();

        return (int) ($row['user_ally_id'] ?? 0);
    }
}

if (!function_exists('botAcsPickEnemyTargetForLeader')) {
    /**
     * Picks an enemy planet for a leader to open ACS against. Strategy:
     * iterate the bot's intel snapshots (most recent first), keep only
     * targets whose owner belongs to an alliance at war with the bot's
     * own alliance, and return the first one with a known planet row.
     *
     * @return array{planet_user_id:int, planet_galaxy:int, planet_system:int, planet_planet:int, planet_type:int, planet_id:int, target_ally_id:int}|null
     */
    function botAcsPickEnemyTargetForLeader(
        mysqli $db,
        string $prefix,
        array $state,
        int $myAllyId
    ): ?array {
        if ($myAllyId <= 0) {
            return null;
        }
        $enemies = botDiplomacyListEnemyAlliances($db, $prefix, $myAllyId);
        if ($enemies === []) {
            return null;
        }
        $enemyIdx = array_flip($enemies);
        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
        $intel = is_array($quirks['intel'] ?? null) ? $quirks['intel'] : [];
        // Sort by recency: largest captured_at first.
        uasort($intel, static function ($a, $b): int {
            return (int) ($b['captured_at'] ?? 0) <=> (int) ($a['captured_at'] ?? 0);
        });

        foreach ($intel as $planetId => $entry) {
            $snap = $entry['snapshot'] ?? null;
            if (!is_array($snap)) {
                continue;
            }
            $coords = $snap['coords'] ?? null;
            if (!is_array($coords)) {
                continue;
            }
            $g = (int) ($coords['galaxy'] ?? 0);
            $s = (int) ($coords['system'] ?? 0);
            $p = (int) ($coords['planet'] ?? 0);
            $t = (int) ($coords['type'] ?? 1);
            $ownerId = botAcsTargetEnemyUserId($db, $prefix, $g, $s, $p, $t);
            if ($ownerId <= 0) {
                continue;
            }
            $allyId = botAcsUserAllyId($db, $prefix, $ownerId);
            if (!isset($enemyIdx[$allyId])) {
                continue;
            }

            return [
                'planet_user_id' => $ownerId,
                'planet_galaxy' => $g,
                'planet_system' => $s,
                'planet_planet' => $p,
                'planet_type' => $t,
                'planet_id' => (int) $planetId,
                'target_ally_id' => $allyId,
            ];
        }

        return null;
    }
}

if (!function_exists('botAcsBuildLeaderShipMix')) {
    /**
     * Greedy mix for the leader: prefers combat ships + a cargo, capped
     * at a fraction of fleet available on the source.
     *
     * @param array<int, int> $shipsAvailable shipId => count
     * @return array<int, int>|null
     */
    function botAcsBuildLeaderShipMix(array $shipsAvailable): ?array
    {
        if ($shipsAvailable === []) {
            return null;
        }
        $reserve = defined('BOT_ATTACK_FLEET_RESERVE_PCT')
            ? (float) BOT_ATTACK_FLEET_RESERVE_PCT
            : 0.20;
        $factor = max(0.10, min(0.95, 1.0 - $reserve));
        $combatIds = [204, 205, 206, 207, 211, 213, 215];
        $mix = [];
        foreach ($combatIds as $cid) {
            $avail = (int) ($shipsAvailable[$cid] ?? 0);
            if ($avail <= 0) {
                continue;
            }
            $take = (int) floor($avail * $factor);
            if ($take > 0) {
                $mix[$cid] = $take;
            }
        }
        // At least one cargo to land the punch.
        $smallCargo = (int) ($shipsAvailable[202] ?? 0);
        $bigCargo = (int) ($shipsAvailable[203] ?? 0);
        if ($bigCargo > 0) {
            $mix[203] = (int) floor($bigCargo * $factor);
            if ($mix[203] <= 0) {
                $mix[203] = min(1, $bigCargo);
            }
        } elseif ($smallCargo > 0) {
            $mix[202] = (int) floor($smallCargo * $factor);
            if ($mix[202] <= 0) {
                $mix[202] = min(1, $smallCargo);
            }
        }
        $total = 0;
        foreach ($mix as $c) {
            $total += (int) $c;
        }
        if ($total <= 0) {
            return null;
        }

        return $mix;
    }
}

if (!function_exists('botAcsValueOfShipMix')) {
    /**
     * Rough metal-equivalent of a ship mix (used for the leader minimum
     * combat value gate). Uses pricelist sum metal+crystal as proxy.
     *
     * @param array<int, int> $shipMix
     */
    function botAcsValueOfShipMix(array $shipMix, array $pricelist): int
    {
        $val = 0;
        foreach ($shipMix as $sid => $cnt) {
            $row = $pricelist[$sid] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $val += (int) ((((int) ($row['metal'] ?? 0)) + ((int) ($row['crystal'] ?? 0))) * (int) $cnt);
        }

        return $val;
    }
}

if (!function_exists('botAcsInsertGroup')) {
    /**
     * Atomically creates the ACS group: INSERT xgp_acs row + leader fleet
     * (mission 1, fleet_group=acs_id) + leader xgp_acs_members row +
     * ships/deuterium decrement on the source planet.
     *
     * @param array<int, int> $shipMix
     * @return array{acs_id:int, fleet_id:int, end_time:int}|null
     */
    function botAcsInsertGroup(
        mysqli $db,
        string $prefix,
        array $user,
        array $source,
        array $target,
        array $shipMix,
        array $researchRow,
        float $universeSpeed
    ): ?array {
        if ($shipMix === []) {
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
        $extra = defined('BOT_ACS_LEADER_EXTRA_SECONDS')
            ? (int) BOT_ACS_LEADER_EXTRA_SECONDS
            : 600;
        $endTime = $now + $duration + $extra;

        $userId = (int) $user['user_id'];
        $sourcePlanetId = (int) $source['planet_id'];
        $targetOwnerId = (int) ($target['planet_user_id'] ?? 0);
        $fleetArray = serialize($shipMix);

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
            if ($col === null || (int) $count <= 0) {
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

        $acsName = botAcsRandomCode();
        $acsRows = [];

        $fleetId = botFleetInsertTransactional(
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
                $decrements,
                $acsName,
                &$acsRows
            ): ?int {
                $okAcs = $db->query(
                    "INSERT INTO `{$prefix}acs` SET
                     `acs_name` = '" . $db->real_escape_string($acsName) . "',
                     `acs_owner` = {$userId},
                     `acs_galaxy` = {$endGalaxy},
                     `acs_system` = {$endSystem},
                     `acs_planet` = {$endPlanet},
                     `acs_planet_type` = {$endType}"
                );
                if (!$okAcs) {
                    return null;
                }
                $acsId = (int) $db->insert_id;
                if ($acsId <= 0) {
                    return null;
                }
                $acsRows['acs_id'] = $acsId;

                $okMember = $db->query(
                    "INSERT INTO `{$prefix}acs_members` SET
                     `acs_group_id` = {$acsId},
                     `acs_user_id` = {$userId}"
                );
                if (!$okMember) {
                    return null;
                }

                $okFleet = $db->query(
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
                     `fleet_group` = {$acsId},
                     `fleet_mess` = 0,
                     `fleet_creation` = {$now}"
                );
                if (!$okFleet) {
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

        if ($fleetId === null) {
            return null;
        }

        return [
            'acs_id' => (int) ($acsRows['acs_id'] ?? 0),
            'fleet_id' => (int) $fleetId,
            'end_time' => $endTime,
        ];
    }
}

if (!function_exists('botAcsInsertJoiner')) {
    /**
     * Atomically joins an existing ACS group: INSERT xgp_acs_members + a
     * mission 2 fleet sharing the same fleet_group and fleet_end_time.
     *
     * @param array<int, int> $shipMix
     * @return int|null fleet_id
     */
    function botAcsInsertJoiner(
        mysqli $db,
        string $prefix,
        array $user,
        array $source,
        array $target,
        array $shipMix,
        array $researchRow,
        float $universeSpeed,
        int $acsId,
        int $leaderEndTime
    ): ?int {
        if ($shipMix === [] || $acsId <= 0) {
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

        $now = time();
        $slack = defined('BOT_ACS_JOINER_MIN_SLACK_SECONDS')
            ? (int) BOT_ACS_JOINER_MIN_SLACK_SECONDS
            : 60;
        if ($leaderEndTime - $now < $duration + $slack) {
            return null;
        }

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

        $userId = (int) $user['user_id'];
        $sourcePlanetId = (int) $source['planet_id'];
        $targetOwnerId = (int) ($target['planet_user_id'] ?? 0);
        $fleetArray = serialize($shipMix);
        $endTime = $leaderEndTime;

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
            if ($col === null || (int) $count <= 0) {
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
                $decrements,
                $acsId
            ): ?int {
                $okMember = $db->query(
                    "INSERT IGNORE INTO `{$prefix}acs_members` SET
                     `acs_group_id` = {$acsId},
                     `acs_user_id` = {$userId}"
                );
                if (!$okMember) {
                    return null;
                }
                $okFleet = $db->query(
                    "INSERT INTO `{$prefix}fleets` SET
                     `fleet_owner` = {$userId},
                     `fleet_mission` = 2,
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
                     `fleet_group` = {$acsId},
                     `fleet_mess` = 0,
                     `fleet_creation` = {$now}"
                );
                if (!$okFleet) {
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

if (!function_exists('botAcsRunLeader')) {
    /**
     * Tries to open ONE ACS group as leader if conditions are met.
     * Returns log lines emitted by the attempt.
     *
     * @param array<int, array<string, mixed>> $planets
     * @return list<string>
     */
    function botAcsRunLeader(
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
        $myAlly = botAcsAllyId($user);
        if ($myAlly <= 0) {
            return $logs;
        }
        $enemies = botDiplomacyListEnemyAlliances($db, $prefix, $myAlly);
        if ($enemies === []) {
            botAllianceMergeMetrics($state, ['acs_skipped_no_war' => 1]);

            return $logs;
        }
        $userId = (int) $user['user_id'];
        $maxOpen = defined('BOT_ACS_MAX_OPEN_GROUPS_PER_BOT')
            ? (int) BOT_ACS_MAX_OPEN_GROUPS_PER_BOT
            : 1;
        if (botAcsCountOpenGroupsForUser($db, $prefix, $userId) >= $maxOpen) {
            return $logs;
        }
        $target = botAcsPickEnemyTargetForLeader($db, $prefix, $state, $myAlly);
        if ($target === null) {
            botAllianceMergeMetrics($state, ['acs_skipped_no_target' => 1]);

            return $logs;
        }

        $planetIds = array_map(static fn (array $p) => (int) $p['planet_id'], $planets);
        $shipsByPlanet = botAttackShipsByPlanet($db, $prefix, $planetIds);
        $bestSource = null;
        $bestMix = null;
        $bestValue = 0;
        foreach ($planets as $sp) {
            $pid = (int) $sp['planet_id'];
            $ships = $shipsByPlanet[$pid] ?? [];
            $mix = botAcsBuildLeaderShipMix($ships);
            if ($mix === null) {
                continue;
            }
            $value = botAcsValueOfShipMix($mix, $pricelist);
            if ($value < (defined('BOT_ACS_LEADER_MIN_FLEET_VALUE')
                    ? (int) BOT_ACS_LEADER_MIN_FLEET_VALUE
                    : 100_000)) {
                continue;
            }
            if ($value > $bestValue) {
                $bestValue = $value;
                $bestSource = $sp;
                $bestMix = $mix;
            }
        }
        if ($bestSource === null || $bestMix === null) {
            botAllianceMergeMetrics($state, ['acs_skipped_no_fleet' => 1]);

            return $logs;
        }

        $result = botAcsInsertGroup(
            $db,
            $prefix,
            $user,
            $bestSource,
            $target,
            $bestMix,
            $researchRow,
            $universeSpeed
        );
        if ($result === null) {
            botAllianceMergeMetrics($state, ['acs_insert_failed' => 1]);

            return $logs;
        }
        botAllianceMergeMetrics($state, ['acs_groups_created' => 1]);
        $logs[] = sprintf(
            'acs: leader group=%d target=%d:%d:%d enemy_user=%d end=%d',
            (int) $result['acs_id'],
            (int) $target['planet_galaxy'],
            (int) $target['planet_system'],
            (int) $target['planet_planet'],
            (int) $target['planet_user_id'],
            (int) $result['end_time']
        );

        return $logs;
    }
}

if (!function_exists('botAcsRunJoiner')) {
    /**
     * Tries to join ONE open ACS group as a member.
     *
     * @param array<int, array<string, mixed>> $planets
     * @return list<string>
     */
    function botAcsRunJoiner(
        mysqli $db,
        string $prefix,
        array $user,
        array $planets,
        array $researchRow,
        array &$state,
        float $universeSpeed
    ): array {
        $logs = [];
        $myAlly = botAcsAllyId($user);
        if ($myAlly <= 0) {
            return $logs;
        }
        $userId = (int) $user['user_id'];
        $groups = botAcsListOpenGroupsLedByAllyBots($db, $prefix, $userId, $myAlly);
        if ($groups === []) {
            return $logs;
        }
        $maxMembers = defined('BOT_ACS_GROUP_MAX_MEMBERS')
            ? (int) BOT_ACS_GROUP_MAX_MEMBERS
            : 5;
        $planetIds = array_map(static fn (array $p) => (int) $p['planet_id'], $planets);
        $shipsByPlanet = botAttackShipsByPlanet($db, $prefix, $planetIds);

        foreach ($groups as $g) {
            if ((int) $g['member_count'] >= $maxMembers) {
                continue;
            }
            $targetOwner = botAcsTargetEnemyUserId(
                $db,
                $prefix,
                (int) $g['acs_galaxy'],
                (int) $g['acs_system'],
                (int) $g['acs_planet'],
                (int) $g['acs_planet_type']
            );
            if ($targetOwner <= 0) {
                continue;
            }
            $targetAlly = botAcsUserAllyId($db, $prefix, $targetOwner);
            if (!botDiplomacyIsAtWar($db, $prefix, $myAlly, $targetAlly)) {
                continue;
            }

            $target = [
                'planet_galaxy' => (int) $g['acs_galaxy'],
                'planet_system' => (int) $g['acs_system'],
                'planet_planet' => (int) $g['acs_planet'],
                'planet_type' => (int) $g['acs_planet_type'],
                'planet_user_id' => $targetOwner,
            ];

            $bestSource = null;
            $bestMix = null;
            $bestValue = 0;
            foreach ($planets as $sp) {
                $pid = (int) $sp['planet_id'];
                $ships = $shipsByPlanet[$pid] ?? [];
                $mix = botAcsBuildLeaderShipMix($ships);
                if ($mix === null) {
                    continue;
                }
                $value = array_sum($mix);
                if ($value > $bestValue) {
                    $bestValue = $value;
                    $bestSource = $sp;
                    $bestMix = $mix;
                }
            }
            if ($bestSource === null || $bestMix === null) {
                continue;
            }

            $fleetId = botAcsInsertJoiner(
                $db,
                $prefix,
                $user,
                $bestSource,
                $target,
                $bestMix,
                $researchRow,
                $universeSpeed,
                (int) $g['acs_id'],
                (int) $g['leader_end_time']
            );
            if ($fleetId === null) {
                botAllianceMergeMetrics($state, ['acs_skipped_late' => 1]);

                continue;
            }
            botAllianceMergeMetrics($state, ['acs_groups_joined' => 1]);
            $logs[] = sprintf(
                'acs: joiner group=%d target=%d:%d:%d enemy_user=%d end=%d',
                (int) $g['acs_id'],
                (int) $g['acs_galaxy'],
                (int) $g['acs_system'],
                (int) $g['acs_planet'],
                $targetOwner,
                (int) $g['leader_end_time']
            );

            return $logs;
        }

        return $logs;
    }
}

if (!function_exists('botAcsRunPurposeful')) {
    /**
     * Entry point called from the main loop after the normal attack pass.
     *
     * @param array<int, array<string, mixed>> $planets
     * @return list<string>
     */
    function botAcsRunPurposeful(
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
        if (botAcsAllyId($user) <= 0) {
            return $logs;
        }
        foreach (
            botAcsRunJoiner($db, $prefix, $user, $planets, $researchRow, $state, $universeSpeed) as $line
        ) {
            $logs[] = $line;
        }
        foreach (
            botAcsRunLeader($db, $prefix, $user, $planets, $researchRow, $pricelist, $state, $universeSpeed) as $line
        ) {
            $logs[] = $line;
        }

        return $logs;
    }
}
