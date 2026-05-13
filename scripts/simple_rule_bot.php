<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/Core/objects_collection.php';

require_once __DIR__ . '/bot_lib/util.php';
require_once __DIR__ . '/bot_lib/safety.php';
require_once __DIR__ . '/bot_lib/state.php';
require_once __DIR__ . '/bot_lib/personality.php';
require_once __DIR__ . '/bot_lib/decisions.php';
require_once __DIR__ . '/bot_lib/strategy.php';
require_once __DIR__ . '/bot_lib/logging.php';
require_once __DIR__ . '/bot_lib/travel.php';
require_once __DIR__ . '/bot_lib/colonization.php';
require_once __DIR__ . '/bot_lib/transport.php';
require_once __DIR__ . '/bot_lib/combat.php';
require_once __DIR__ . '/bot_lib/attack.php';
require_once __DIR__ . '/bot_lib/alliance.php';
require_once __DIR__ . '/bot_lib/diplomacy.php';
require_once __DIR__ . '/bot_lib/diplomacy_actions.php';
require_once __DIR__ . '/bot_lib/acs_attack.php';
require_once __DIR__ . '/bot_lib/ally_logistics.php';
require_once __DIR__ . '/bot_lib/llm_inbox.php';

function randomFloat(float $min, float $max): float
{
    return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
}

function calculateLevelPointsDelta(int $objectId, int $fromLevel, int $toLevel): int
{
    if ($toLevel <= $fromLevel) {
        return 0;
    }

    $priceList = $GLOBALS['pricelist'] ?? [];
    if (!isset($priceList[$objectId]) || !is_array($priceList[$objectId])) {
        return 0;
    }

    $base = (float) (
        ((int) ($priceList[$objectId]['metal'] ?? 0))
        + ((int) ($priceList[$objectId]['crystal'] ?? 0))
        + ((int) ($priceList[$objectId]['deuterium'] ?? 0))
    );
    $factor = (float) ($priceList[$objectId]['factor'] ?? 1.0);
    if ($base <= 0 || $factor <= 0) {
        return 0;
    }

    $delta = 0.0;
    for ($level = $fromLevel + 1; $level <= $toLevel; $level++) {
        $delta += ($base * pow($factor, max(0, $level - 1))) / 1000.0;
    }

    return max(0, (int) round($delta));
}

function calculateUnitPointsDelta(int $objectId, int $amount): int
{
    if ($amount <= 0) {
        return 0;
    }

    $priceList = $GLOBALS['pricelist'] ?? [];
    if (!isset($priceList[$objectId]) || !is_array($priceList[$objectId])) {
        return 0;
    }

    $base = (float) (
        ((int) ($priceList[$objectId]['metal'] ?? 0))
        + ((int) ($priceList[$objectId]['crystal'] ?? 0))
        + ((int) ($priceList[$objectId]['deuterium'] ?? 0))
    );
    if ($base <= 0) {
        return 0;
    }

    return max(0, (int) round(($base / 1000.0) * $amount));
}

function addUserStatisticPoints(
    mysqli $db,
    string $prefix,
    int $userId,
    int $buildingDelta = 0,
    int $technologyDelta = 0,
    int $shipsDelta = 0,
    int $defensesDelta = 0
): void {
    $totalDelta = $buildingDelta + $technologyDelta + $shipsDelta + $defensesDelta;
    if ($userId <= 0 || $totalDelta <= 0) {
        return;
    }

    $parts = [];
    if ($buildingDelta > 0) {
        $parts[] = "`user_statistic_buildings_points` = `user_statistic_buildings_points` + {$buildingDelta}";
    }
    if ($technologyDelta > 0) {
        $parts[] = "`user_statistic_technology_points` = `user_statistic_technology_points` + {$technologyDelta}";
    }
    if ($shipsDelta > 0) {
        $parts[] = "`user_statistic_ships_points` = `user_statistic_ships_points` + {$shipsDelta}";
    }
    if ($defensesDelta > 0) {
        $parts[] = "`user_statistic_defenses_points` = `user_statistic_defenses_points` + {$defensesDelta}";
    }
    $parts[] = "`user_statistic_total_points` = `user_statistic_total_points` + {$totalDelta}";

    $db->query(
        "UPDATE `{$prefix}users_statistics`
         SET " . implode(",\n             ", $parts) . "
         WHERE `user_statistic_user_id` = {$userId}
         LIMIT 1"
    );
}

function loadProfiles(?string $configPath): array
{
    if ($configPath === null || $configPath === '') {
        return [];
    }

    if (!file_exists($configPath)) {
        exit("Config file not found: {$configPath}\n");
    }

    $raw = file_get_contents($configPath);
    if ($raw === false) {
        exit("Could not read config file: {$configPath}\n");
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        exit("Invalid JSON in config file: {$configPath}\n");
    }

    $namedProfiles = [];
    if (isset($data['profiles']) && is_array($data['profiles'])) {
        foreach ($data['profiles'] as $profileName => $profileData) {
            if (!is_string($profileName) || !is_array($profileData)) {
                continue;
            }

            $namedProfiles[$profileName] = extractProfileConfig($profileData);
        }
    }

    if (!isset($data['accounts']) || !is_array($data['accounts'])) {
        return [];
    }

    $profiles = [];
    foreach ($data['accounts'] as $account) {
        if (!is_array($account) || empty($account['user'])) {
            continue;
        }

        $baseProfile = [];
        if (!empty($account['profile']) && is_string($account['profile']) && isset($namedProfiles[$account['profile']])) {
            $baseProfile = $namedProfiles[$account['profile']];
        }

        // Keep backward compatibility: account can define all fields directly.
        $profiles[(string) $account['user']] = array_merge(
            $baseProfile,
            extractProfileConfig($account)
        );
    }

    return $profiles;
}

function loadAccountProfileNames(?string $configPath): array
{
    if ($configPath === null || $configPath === '' || !file_exists($configPath)) {
        return [];
    }

    $raw = file_get_contents($configPath);
    if ($raw === false) {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['accounts']) || !is_array($data['accounts'])) {
        return [];
    }

    $map = [];
    foreach ($data['accounts'] as $account) {
        if (!is_array($account) || empty($account['user'])) {
            continue;
        }

        $map[(string) $account['user']] = isset($account['profile']) && is_string($account['profile'])
            ? $account['profile']
            : 'inline';
    }

    return $map;
}

/**
 * Resolves `--config` so paths like `scripts/bot_accounts.json` work whether
 * the current working directory is the project root (e.g. Docker) or
 * another folder.
 */
function resolveBotConfigPath(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return $path;
    }

    $unixPath = str_replace('\\', '/', $path);
    $candidates = [$path];

    $isAbsolute = ($unixPath !== '' && $unixPath[0] === '/')
        || (strlen($unixPath) > 2
            && ctype_alpha($unixPath[0])
            && $unixPath[1] === ':'
            && ($unixPath[2] === '/' || $unixPath[2] === '\\'));

    if (!$isAbsolute) {
        $projectRoot = dirname(__DIR__);
        $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($unixPath, '/'));
        $stripped = preg_replace('#^scripts/#', '', $unixPath);
        if ($stripped !== $unixPath) {
            $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stripped);
        }
    }

    $seen = [];
    foreach ($candidates as $c) {
        if ($c === '') {
            continue;
        }
        $norm = str_replace('\\', DIRECTORY_SEPARATOR, $c);
        if (isset($seen[$norm])) {
            continue;
        }
        $seen[$norm] = true;
        if (is_readable($norm)) {
            $rp = realpath($norm);

            return $rp !== false ? $rp : $norm;
        }
    }

    return str_replace('\\', DIRECTORY_SEPARATOR, $path);
}

/**
 * User names under `accounts` in the bot config JSON (same file as
 * `--config`, usually scripts/bot_accounts.json). Gates alliance diplomacy
 * and ally logistics.
 *
 * @return array<string, true>
 */
function loadBotAllianceAccountSet(?string $configPath = null): array
{
    $paths = [];
    if ($configPath !== null && trim($configPath) !== '') {
        $paths[] = $configPath;
    }
    $paths[] = __DIR__ . DIRECTORY_SEPARATOR . 'bot_accounts.json';

    $seenPath = [];
    foreach ($paths as $path) {
        if ($path === '') {
            continue;
        }
        $norm = str_replace('\\', DIRECTORY_SEPARATOR, $path);
        if (isset($seenPath[$norm])) {
            continue;
        }
        $seenPath[$norm] = true;
        if (!is_readable($norm)) {
            continue;
        }

        $raw = file_get_contents($norm);
        if ($raw === false) {
            continue;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['accounts']) || !is_array($data['accounts'])) {
            continue;
        }

        $set = [];
        foreach ($data['accounts'] as $account) {
            if (!is_array($account) || empty($account['user'])) {
                continue;
            }
            $set[(string) $account['user']] = true;
        }
        if ($set !== []) {
            return $set;
        }
    }

    return [];
}

function extractProfileConfig(array $source): array
{
    $profile = [];
    $allowedKeys = [
        'bot_style',
        'aggressiveness',
        'schedule_mode',
        'schedule_windows',
        'activity_start',
        'activity_end',
        'actions_per_loop',
        'skip_chance',
        'action_chance_idle',
        'action_chance_one',
        'action_chance_two',
        'action_chance_three',
        'eco_focus',
        'defense_focus',
        'research_focus',
        // Optional per-profile overrides for attack profitability ratio.
        // Either a single 'attack_ratio' (fixed value) or an
        // 'attack_ratios' map keyed by aggressiveness bracket
        // (very_aggressive | aggressive | moderate | conservative).
        // Both are clamped to [BOT_ATTACK_RATIO_MIN, BOT_ATTACK_RATIO_MAX].
        'attack_ratio',
        'attack_ratios',
    ];

    foreach ($allowedKeys as $key) {
        if (array_key_exists($key, $source)) {
            $profile[$key] = $source[$key];
        }
    }

    return $profile;
}

function normalizeProfile(array $profile): array
{
    $allowedStyles = ['bunker', 'raider', 'granja'];
    $allowedScheduleModes = ['always_on', 'windows', 'legacy'];

    $profile['bot_style'] = strtolower((string) ($profile['bot_style'] ?? 'granja'));
    if (!in_array($profile['bot_style'], $allowedStyles, true)) {
        $profile['bot_style'] = 'granja';
    }

    $profile['aggressiveness'] = max(1, min(5, (int) ($profile['aggressiveness'] ?? 3)));
    $profile['schedule_mode'] = strtolower((string) ($profile['schedule_mode'] ?? 'always_on'));
    if (!in_array($profile['schedule_mode'], $allowedScheduleModes, true)) {
        $profile['schedule_mode'] = 'always_on';
    }

    $profile['activity_start'] = max(0, min(23, (int) ($profile['activity_start'] ?? 7)));
    $profile['activity_end'] = max(0, min(23, (int) ($profile['activity_end'] ?? 23)));
    $profile['actions_per_loop'] = max(1, (int) ($profile['actions_per_loop'] ?? 2));
    $profile['skip_chance'] = max(0.0, min(0.9, (float) ($profile['skip_chance'] ?? 0.15)));
    $profile = normalizeActionChances($profile);
    $profile['eco_focus'] = max(0.1, min(1.0, (float) ($profile['eco_focus'] ?? 0.7)));
    $profile['defense_focus'] = max(0.0, min(1.0, (float) ($profile['defense_focus'] ?? 0.2)));
    $profile['research_focus'] = max(0.0, min(1.0, (float) ($profile['research_focus'] ?? 0.6)));
    $profile['schedule_windows'] = normalizeScheduleWindows($profile['schedule_windows'] ?? []);

    return $profile;
}

function normalizeActionChances(array $profile): array
{
    $hasExplicitWeights = array_key_exists('action_chance_idle', $profile)
        || array_key_exists('action_chance_one', $profile)
        || array_key_exists('action_chance_two', $profile)
        || array_key_exists('action_chance_three', $profile);

    if ($hasExplicitWeights) {
        $idle = max(0.0, (float) ($profile['action_chance_idle'] ?? 0.2));
        $one = max(0.0, (float) ($profile['action_chance_one'] ?? 0.5));
        $two = max(0.0, (float) ($profile['action_chance_two'] ?? 0.3));
        $three = max(0.0, (float) ($profile['action_chance_three'] ?? 0.0));
    } else {
        $idle = $profile['skip_chance'];
        $actionsPerLoop = (int) ($profile['actions_per_loop'] ?? 2);
        if ($actionsPerLoop <= 1) {
            $one = 1.0 - $idle;
            $two = 0.0;
            $three = 0.0;
        } elseif ($actionsPerLoop === 2) {
            $activeShare = 1.0 - $idle;
            $one = $activeShare * 0.55;
            $two = $activeShare * 0.45;
            $three = 0.0;
        } else {
            $activeShare = 1.0 - $idle;
            $one = $activeShare * 0.45;
            $two = $activeShare * 0.35;
            $three = $activeShare * 0.20;
        }
    }

    $sum = $idle + $one + $two + $three;
    if ($sum <= 0.0) {
        $idle = 0.2;
        $one = 0.5;
        $two = 0.3;
        $three = 0.0;
        $sum = 1.0;
    }

    $profile['action_chance_idle'] = $idle / $sum;
    $profile['action_chance_one'] = $one / $sum;
    $profile['action_chance_two'] = $two / $sum;
    $profile['action_chance_three'] = $three / $sum;

    return $profile;
}

function resolveActionsForTick(array $profile, int $planetCount): int
{
    if ($planetCount <= 0) {
        return 0;
    }

    $roll = randomFloat(0, 1);
    $idleThreshold = (float) ($profile['action_chance_idle'] ?? 0.2);
    $oneThreshold = $idleThreshold + (float) ($profile['action_chance_one'] ?? 0.5);
    $twoThreshold = $oneThreshold + (float) ($profile['action_chance_two'] ?? 0.3);

    if ($roll < $idleThreshold) {
        return 0;
    }

    if ($roll < $oneThreshold) {
        return 1;
    }

    if ($roll < $twoThreshold) {
        return 2;
    }

    return 3;
}

function isInActiveWindow(array $profile): bool
{
    if (($profile['schedule_mode'] ?? 'always_on') === 'always_on') {
        return true;
    }

    $hour = (int) date('G');

    if (($profile['schedule_mode'] ?? '') === 'windows') {
        foreach (($profile['schedule_windows'] ?? []) as $window) {
            $start = (int) ($window['start'] ?? 0);
            $end = (int) ($window['end'] ?? 23);

            if ($start <= $end) {
                if ($hour >= $start && $hour <= $end) {
                    return true;
                }
            } elseif ($hour >= $start || $hour <= $end) {
                return true;
            }
        }

        return false;
    }

    $start = $profile['activity_start'];
    $end = $profile['activity_end'];

    if ($start <= $end) {
        return $hour >= $start && $hour <= $end;
    }

    // overnight windows, e.g. 22 -> 3
    return $hour >= $start || $hour <= $end;
}

function normalizeScheduleWindows(array $windows): array
{
    $normalized = [];

    foreach ($windows as $window) {
        if (!is_array($window)) {
            continue;
        }

        $start = max(0, min(23, (int) ($window['start'] ?? 0)));
        $end = max(0, min(23, (int) ($window['end'] ?? 23)));
        $normalized[] = ['start' => $start, 'end' => $end];
    }

    return $normalized;
}

function canAfford(array $planet, array $cost): bool
{
    return $planet['planet_metal'] >= $cost['metal']
        && $planet['planet_crystal'] >= $cost['crystal']
        && $planet['planet_deuterium'] >= $cost['deuterium'];
}

function payResources(mysqli $db, string $prefix, int $planetId, array $cost): bool
{
    $sql = "UPDATE `{$prefix}planets`
            SET `planet_metal` = `planet_metal` - {$cost['metal']},
                `planet_crystal` = `planet_crystal` - {$cost['crystal']},
                `planet_deuterium` = `planet_deuterium` - {$cost['deuterium']}
            WHERE `planet_id` = {$planetId}
              AND `planet_metal` >= {$cost['metal']}
              AND `planet_crystal` >= {$cost['crystal']}
              AND `planet_deuterium` >= {$cost['deuterium']}
            LIMIT 1";

    $db->query($sql);

    return $db->affected_rows === 1;
}

function getUniverseSpeed(mysqli $db, string $prefix): float
{
    $result = $db->query(
        "SELECT `option_value` FROM `{$prefix}options` WHERE `option_name` = 'game_speed' LIMIT 1"
    );
    $row = $result ? $result->fetch_assoc() : null;
    $speed = (int) ($row['option_value'] ?? 2500);

    return max(1.0, $speed / 2500);
}

function getResourceMultiplier(mysqli $db, string $prefix): float
{
    $normalize = static function (mixed $raw): ?float {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        $s = str_replace(',', '.', preg_replace('/\s+/', '', (string) $raw));

        return is_numeric($s) ? (float) $s : null;
    };

    if (extension_loaded('mysqli') && class_exists(\App\Libraries\Functions::class)) {
        try {
            $parsed = $normalize(\App\Libraries\Functions::readConfig('resource_multiplier'));
            if ($parsed !== null) {
                return max(0.1, $parsed);
            }
        } catch (\Throwable $e) {
            // Fallback: consulta directa a options (mismo universo que el bot).
        }
    }

    $result = $db->query(
        "SELECT `option_value` FROM `{$prefix}options` WHERE `option_name` = 'resource_multiplier' LIMIT 1"
    );
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $parsed = $normalize($row['option_value'] ?? null);

    return max(0.1, $parsed ?? 1.0);
}

function refreshPlanetResources(mysqli $db, string $prefix, array &$planet, float $resourceMultiplier): void
{
    $now = time();
    $lastUpdate = (int) ($planet['planet_last_update'] ?? $now);
    if ($lastUpdate >= $now) {
        return;
    }

    $elapsed = $now - $lastUpdate;
    $planet['_bot_elapsed'] = $elapsed;
    $metalMax = maxStorageFromLevel((int) $planet['building_metal_store'], $resourceMultiplier);
    $crystalMax = maxStorageFromLevel((int) $planet['building_crystal_store'], $resourceMultiplier);
    $deuteriumMax = maxStorageFromLevel((int) $planet['building_deuterium_tank'], $resourceMultiplier);

    $newMetal = min($metalMax, (float) $planet['planet_metal'] + ($elapsed * ((float) $planet['planet_metal_perhour'] / 3600)));
    $newCrystal = min($crystalMax, (float) $planet['planet_crystal'] + ($elapsed * ((float) $planet['planet_crystal_perhour'] / 3600)));
    $newDeuterium = min($deuteriumMax, (float) $planet['planet_deuterium'] + ($elapsed * ((float) $planet['planet_deuterium_perhour'] / 3600)));
    $planetId = (int) $planet['planet_id'];

    $db->query(
        "UPDATE `{$prefix}planets`
         SET `planet_metal` = {$newMetal},
             `planet_crystal` = {$newCrystal},
             `planet_deuterium` = {$newDeuterium},
             `planet_last_update` = {$now}
         WHERE `planet_id` = {$planetId}
         LIMIT 1"
    );

    $planet['planet_metal'] = $newMetal;
    $planet['planet_crystal'] = $newCrystal;
    $planet['planet_deuterium'] = $newDeuterium;
    $planet['planet_last_update'] = $now;
}

function queueBuildingAction(
    mysqli $db,
    string $prefix,
    array $planet,
    int $buildingId,
    int $currentLevel,
    array $cost,
    float $universeSpeed
): bool {
    $robotFactory = (int) ($planet['building_robot_factory'] ?? 0);
    $naniteFactory = (int) ($planet['building_nano_factory'] ?? 0);
    $resourcesNeeded = (int) ($cost['metal'] + $cost['crystal']);
    $reduction = max(4 - (($currentLevel + 1) / 2), 1);
    if (in_array($buildingId, [15, 41, 42, 43], true)) {
        $reduction = 1;
    }

    $robotics = (1 + $robotFactory) * pow(1.1, $robotFactory);
    $nanite = pow(2, $naniteFactory);
    $buildTime = (int) max(1, floor(($resourcesNeeded / (2500 * $reduction * $robotics * $nanite * $universeSpeed)) * 3600));
    $queue = (string) ($planet['planet_b_building_id'] ?? '0');
    $now = time();
    $queueItems = [];

    if ($queue !== '' && $queue !== '0') {
        $rawItems = array_values(array_filter(explode(';', $queue), static fn (string $item): bool => $item !== ''));
        foreach ($rawItems as $item) {
            $parts = explode(',', $item);
            $endTs = isset($parts[3]) ? (int) $parts[3] : 0;
            if ($endTs > $now) {
                $queueItems[] = $item;
            }
        }
    }

    if (count($queueItems) >= 5) {
        return false;
    }

    foreach ($queueItems as $item) {
        $parts = explode(',', $item);
        $queuedBuildingId = isset($parts[0]) ? (int) $parts[0] : 0;
        if ($queuedBuildingId === $buildingId) {
            return false;
        }
    }

    if (empty($queueItems)) {
        $buildEnd = $now + $buildTime;
        $newEntry = $buildingId . ',' . ($currentLevel + 1) . ',' . $buildTime . ',' . $buildEnd . ',build';
        $newQueue = $newEntry;
        $newBuildEnd = $buildEnd;
    } else {
        $last = explode(',', end($queueItems));
        $lastEnd = isset($last[3]) ? (int) $last[3] : $now;
        $buildEnd = max($now, $lastEnd) + $buildTime;
        $newEntry = $buildingId . ',' . ($currentLevel + 1) . ',' . $buildTime . ',' . $buildEnd . ',build';
        $queueItems[] = $newEntry;
        $newQueue = implode(';', $queueItems);
        $first = explode(',', $queueItems[0]);
        $newBuildEnd = isset($first[3]) ? (int) $first[3] : $buildEnd;
    }

    $planetId = (int) $planet['planet_id'];
    $sql = "UPDATE `{$prefix}planets`
            SET `planet_b_building_id` = '" . $db->real_escape_string($newQueue) . "',
                `planet_b_building` = {$newBuildEnd}
            WHERE `planet_id` = {$planetId}
            LIMIT 1";

    return (bool) $db->query($sql);
}

function queueShipyardAction(mysqli $db, string $prefix, array $planet, int $itemId, int $amount): bool
{
    $queue = (string) ($planet['planet_b_hangar_id'] ?? '');
    $entry = $itemId . ',' . $amount . ';';
    $newQueue = $queue . $entry;
    $planetId = (int) $planet['planet_id'];

    $sql = "UPDATE `{$prefix}planets`
            SET `planet_b_hangar_id` = '" . $db->real_escape_string($newQueue) . "'
            WHERE `planet_id` = {$planetId}
            LIMIT 1";

    return (bool) $db->query($sql);
}

function processCompletedBuildingQueue(mysqli $db, string $prefix, array &$planet): void
{
    $queue = (string) ($planet['planet_b_building_id'] ?? '0');
    if ($queue === '' || $queue === '0') {
        return;
    }

    $now = time();
    $items = array_values(array_filter(explode(';', $queue), static fn (string $item): bool => $item !== ''));
    if (empty($items)) {
        $planet['planet_b_building_id'] = '0';
        $planet['planet_b_building'] = 0;

        return;
    }

    $resourceMap = $GLOBALS['resource'] ?? [];
    $remaining = [];
    $queueChanged = false;
    $addedBuildingPoints = 0;

    foreach ($items as $item) {
        $parts = explode(',', $item);
        $buildingId = isset($parts[0]) ? (int) $parts[0] : 0;
        $targetLevel = isset($parts[1]) ? (int) $parts[1] : 0;
        $endTs = isset($parts[3]) ? (int) $parts[3] : PHP_INT_MAX;
        $mode = isset($parts[4]) ? (string) $parts[4] : 'build';

        if ($endTs > $now) {
            $remaining[] = $item;

            continue;
        }

        $queueChanged = true;
        if ($mode !== 'build') {
            continue;
        }

        $column = $resourceMap[$buildingId] ?? null;
        if (!is_string($column) || strpos($column, 'building_') !== 0) {
            continue;
        }

        $planetId = (int) $planet['planet_id'];
        $safeColumn = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($safeColumn === '' || $safeColumn !== $column) {
            continue;
        }

        $currentLevel = (int) ($planet[$column] ?? 0);
        if ($targetLevel > $currentLevel) {
            $db->query(
                "UPDATE `{$prefix}buildings`
                 SET `{$safeColumn}` = {$targetLevel}
                 WHERE `building_planet_id` = {$planetId}
                 LIMIT 1"
            );

            $addedBuildingPoints += calculateLevelPointsDelta($buildingId, $currentLevel, $targetLevel);

            $planet[$column] = $targetLevel;
        }
    }

    if (!$queueChanged) {
        return;
    }

    $newQueue = empty($remaining) ? '0' : implode(';', $remaining);
    $nextEnd = 0;
    if (!empty($remaining)) {
        $first = explode(',', $remaining[0]);
        $nextEnd = isset($first[3]) ? (int) $first[3] : 0;
    }

    $planet['planet_b_building_id'] = $newQueue;
    $planet['planet_b_building'] = $nextEnd;
    $planetId = (int) $planet['planet_id'];

    $db->query(
        "UPDATE `{$prefix}planets`
         SET `planet_b_building_id` = '" . $db->real_escape_string($newQueue) . "',
             `planet_b_building` = {$nextEnd}
         WHERE `planet_id` = {$planetId}
         LIMIT 1"
    );

    addUserStatisticPoints($db, $prefix, (int) ($planet['planet_user_id'] ?? 0), $addedBuildingPoints, 0, 0, 0);
}

/**
 * Read game-wide basic income (metal/crystal/deuterium) once per loop. These
 * values are added on top of the planet's per-hour production by the engine.
 */
function getBasicIncomeConfig(mysqli $db, string $prefix): array
{
    $sql = "SELECT `option_name`, `option_value`
            FROM `{$prefix}options`
            WHERE `option_name` IN ('metal_basic_income','crystal_basic_income','deuterium_basic_income')";
    $result = $db->query($sql);
    $out = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];
    if (!$result) {
        return $out;
    }
    $map = ['metal_basic_income' => 'metal', 'crystal_basic_income' => 'crystal', 'deuterium_basic_income' => 'deuterium'];
    while ($row = $result->fetch_assoc()) {
        $key = $map[$row['option_name']] ?? null;
        if ($key !== null) {
            $out[$key] = (float) $row['option_value'];
        }
    }
    $result->free();

    return $out;
}

/**
 * Recompute the planet's energy balance and resource production rates from
 * scratch using the game's production grid. The bot reads these values to
 * decide whether to prioritize energy buildings, but the web app is the only
 * code path that updates them. CLI bots never hit the web layer, so without
 * this helper a freshly-completed crystal mine would never push the energy
 * balance into the negative as far as the bot is concerned, and the
 * `botEnergyOverride` priority rule would never fire.
 *
 * Mirrors the energy/perhour math from
 * App\Libraries\UpdatesLibrary::updatePlanetResources for the relevant
 * subset (production buildings + solar satellites). Officer boosts and
 * plasma technology are intentionally ignored: bots don't hold premium
 * status, so adding those joins to every loop is not worth the cost.
 *
 * Energy is stored with the same convention as the engine: `energy_max`
 * sums producers; `planet_energy_used` sums consumption (negative values
 * for mines). Deficit detection uses the same rule as the resources screen
 * (abs(used) > max when max > 0), not only the sign of (max + used).
 */
function botRecomputePlanetStats(
    mysqli $db,
    string $prefix,
    array &$planet,
    int $researchEnergyTech,
    array $basicIncome
): void {
    $resource = $GLOBALS['resource'] ?? [];
    $ProdGrid = $GLOBALS['ProdGrid'] ?? [];
    $resourceMultiplier = (float) ($GLOBALS['resourceMultiplier'] ?? 1.0);

    if (empty($resource) || empty($ProdGrid)) {
        return;
    }

    $BuildTemp = (int) ($planet['planet_temp_max'] ?? 0);
    $BuildEnergy = max(0, (int) $researchEnergyTech);

    $energyMax = 0;
    $energyUsed = 0;
    $rawProduction = [];

    foreach ($ProdGrid as $prodId => $formula) {
        $column = $resource[$prodId] ?? null;
        if ($column === null || !isset($formula['formule'])) {
            continue;
        }
        if (!array_key_exists($column, $planet)) {
            continue;
        }

        $BuildLevel = (int) $planet[$column];

        $percentColumn = 'planet_' . $column . '_percent';
        $BuildLevelFactor = array_key_exists($percentColumn, $planet)
            ? (int) $planet[$percentColumn]
            : 10;

        if ($BuildLevel <= 0) {
            $rawProduction[$prodId] = ['m' => 0.0, 'c' => 0.0, 'd' => 0.0];

            continue;
        }

        $metal_prod = (float) eval($formula['formule']['metal']);
        $crystal_prod = (float) eval($formula['formule']['crystal']);
        $deuterium_prod = (float) eval($formula['formule']['deuterium']);
        $energy_prod = (float) eval($formula['formule']['energy']);

        $rawProduction[$prodId] = [
            'm' => $metal_prod,
            'c' => $crystal_prod,
            'd' => $deuterium_prod,
        ];

        if ($prodId >= 4) {
            $energyMax += (int) ceil($energy_prod);
        } else {
            $energyUsed += (int) ceil($energy_prod);
        }
    }

    if ($energyMax === 0 && $energyUsed < 0) {
        $prodLevel = 0;
    } elseif ($energyMax > 0 && ($energyUsed + $energyMax) < 0) {
        $prodLevel = (int) floor(($energyMax / -$energyUsed) * 100);
    } else {
        $prodLevel = 100;
    }
    $prodLevel = max(0, min(100, $prodLevel));

    $metalPerHour = 0.0;
    $crystalPerHour = 0.0;
    $deuteriumPerHour = 0.0;

    foreach ($rawProduction as $vals) {
        $metalPerHour += floor($vals['m'] * $resourceMultiplier) * 0.01 * $prodLevel;
        $crystalPerHour += floor($vals['c'] * $resourceMultiplier) * 0.01 * $prodLevel;
        $deuteriumPerHour += floor($vals['d'] * $resourceMultiplier) * 0.01 * $prodLevel;
    }

    if ($energyMax === 0) {
        $metalPerHour = (float) $basicIncome['metal'];
        $crystalPerHour = (float) $basicIncome['crystal'];
        $deuteriumPerHour = (float) $basicIncome['deuterium'];
    } else {
        $metalPerHour += (float) $basicIncome['metal'];
        $crystalPerHour += (float) $basicIncome['crystal'];
        $deuteriumPerHour += (float) $basicIncome['deuterium'];
    }

    $planet['planet_energy_max'] = $energyMax;
    $planet['planet_energy_used'] = $energyUsed;
    $planet['planet_metal_perhour'] = (int) round($metalPerHour);
    $planet['planet_crystal_perhour'] = (int) round($crystalPerHour);
    $planet['planet_deuterium_perhour'] = (int) round($deuteriumPerHour);

    $planetId = (int) $planet['planet_id'];
    $db->query(
        "UPDATE `{$prefix}planets`
         SET `planet_energy_max` = {$planet['planet_energy_max']},
             `planet_energy_used` = {$planet['planet_energy_used']},
             `planet_metal_perhour` = {$planet['planet_metal_perhour']},
             `planet_crystal_perhour` = {$planet['planet_crystal_perhour']},
             `planet_deuterium_perhour` = {$planet['planet_deuterium_perhour']}
         WHERE `planet_id` = {$planetId}
         LIMIT 1"
    );
}

function getShipyardUnitTime(array $planet, int $itemId, float $universeSpeed): float
{
    $priceList = $GLOBALS['pricelist'] ?? [];

    return botShipyardUnitBuildSeconds($planet, $itemId, is_array($priceList) ? $priceList : [], $universeSpeed);
}

function processCompletedHangarQueue(mysqli $db, string $prefix, array &$planet, float $universeSpeed): void
{
    $queue = (string) ($planet['planet_b_hangar_id'] ?? '');
    if ($queue === '') {
        return;
    }

    $elapsed = (int) ($planet['_bot_elapsed'] ?? 0);
    if ($elapsed <= 0) {
        return;
    }

    $resourceMap = $GLOBALS['resource'] ?? [];
    if (!is_array($resourceMap)) {
        return;
    }

    $progress = (float) ((int) ($planet['planet_b_hangar'] ?? 0) + $elapsed);
    $items = array_values(array_filter(explode(';', $queue), static fn (string $item): bool => $item !== ''));
    if (empty($items)) {
        $planet['planet_b_hangar_id'] = '';
        $planet['planet_b_hangar'] = 0;

        return;
    }

    $remaining = [];
    $shipsDelta = 0;
    $defensesDelta = 0;
    $shipsBuilt = [];
    $defensesBuilt = [];

    for ($idx = 0; $idx < count($items); $idx++) {
        $parts = explode(',', $items[$idx]);
        $itemId = isset($parts[0]) ? (int) $parts[0] : 0;
        $count = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($itemId <= 0 || $count <= 0) {
            continue;
        }

        $unitTime = getShipyardUnitTime($planet, $itemId, $universeSpeed);
        $done = 0;
        $consumed = 0.0;

        if ($unitTime < 1) {
            $unitsPerSecond = max(1, min(1000000, (int) ceil(1 / $unitTime)));
            $wholeSeconds = (int) floor($progress);
            if ($wholeSeconds > 0) {
                $done = min($count, $wholeSeconds * $unitsPerSecond);
                $consumed = (float) ((int) ceil($done / $unitsPerSecond));
            }
        } else {
            if ($progress >= $unitTime) {
                $done = min($count, (int) floor($progress / $unitTime));
                $consumed = $done * $unitTime;
            }
        }

        if ($done > 0) {
            $column = $resourceMap[$itemId] ?? null;
            if (is_string($column) && $column !== '') {
                $planet[$column] = ((int) ($planet[$column] ?? 0)) + $done;
                if (strpos($column, 'ship_') === 0) {
                    $shipsBuilt[$column] = (int) ($shipsBuilt[$column] ?? 0) + $done;
                    $shipsDelta += calculateUnitPointsDelta($itemId, $done);
                } elseif (strpos($column, 'defense_') === 0) {
                    $defensesBuilt[$column] = (int) ($defensesBuilt[$column] ?? 0) + $done;
                    $defensesDelta += calculateUnitPointsDelta($itemId, $done);
                }
            }
        }

        $progress = max(0.0, $progress - $consumed);
        $left = $count - $done;
        if ($left > 0) {
            $remaining[] = $itemId . ',' . $left;
            for ($tail = $idx + 1; $tail < count($items); $tail++) {
                $remaining[] = $items[$tail];
            }

            break;
        }
    }

    $planetId = (int) $planet['planet_id'];
    if (!empty($shipsBuilt)) {
        $sets = [];
        foreach ($shipsBuilt as $column => $amount) {
            $safe = preg_replace('/[^a-z0-9_]/i', '', $column);
            if ($safe !== $column || $safe === '') {
                continue;
            }
            $sets[] = "`{$safe}` = `{$safe}` + {$amount}";
        }
        if (!empty($sets)) {
            $db->query(
                "UPDATE `{$prefix}ships`
                 SET " . implode(', ', $sets) . "
                 WHERE `ship_planet_id` = {$planetId}
                 LIMIT 1"
            );
        }
    }

    if (!empty($defensesBuilt)) {
        $sets = [];
        foreach ($defensesBuilt as $column => $amount) {
            $safe = preg_replace('/[^a-z0-9_]/i', '', $column);
            if ($safe !== $column || $safe === '') {
                continue;
            }
            $sets[] = "`{$safe}` = `{$safe}` + {$amount}";
        }
        if (!empty($sets)) {
            $db->query(
                "UPDATE `{$prefix}defenses`
                 SET " . implode(', ', $sets) . "
                 WHERE `defense_planet_id` = {$planetId}
                 LIMIT 1"
            );
        }
    }

    $newQueue = empty($remaining) ? '' : implode(';', $remaining) . ';';
    $planet['planet_b_hangar_id'] = $newQueue;
    $planet['planet_b_hangar'] = (int) floor($progress);
    $db->query(
        "UPDATE `{$prefix}planets`
         SET `planet_b_hangar_id` = '" . $db->real_escape_string($newQueue) . "',
             `planet_b_hangar` = " . (int) floor($progress) . "
         WHERE `planet_id` = {$planetId}
         LIMIT 1"
    );

    addUserStatisticPoints(
        $db,
        $prefix,
        (int) ($planet['planet_user_id'] ?? 0),
        0,
        0,
        $shipsDelta,
        $defensesDelta
    );
}

function getPlanetRows(mysqli $db, string $prefix, int $userId): array
{
    $sql = "SELECT
                p.*,
                b.*,
                s.*,
                d.*
            FROM `{$prefix}planets` p
            INNER JOIN `{$prefix}buildings` b ON b.`building_planet_id` = p.`planet_id`
            INNER JOIN `{$prefix}ships` s ON s.`ship_planet_id` = p.`planet_id`
            INNER JOIN `{$prefix}defenses` d ON d.`defense_planet_id` = p.`planet_id`
            WHERE p.`planet_user_id` = {$userId}
              AND p.`planet_type` = 1
            ORDER BY p.`planet_id` ASC";

    $result = $db->query($sql);
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();

    return $rows;
}

function decidePlanetAction(array $planet, array $profile): array
{
    $satEnergy = max(1, (int) floor(((int) $planet['planet_temp_max'] + 140) / 6));

    if (botPlanetEnergyIsDeficit($planet)) {
        $energyDeficit = botPlanetEnergyDeficitPoints($planet);
        $solarPlant = (int) $planet['building_solar_plant'];
        $plantFloor = (int) BOT_ENERGY_SOLAR_PLANT_LEVEL_BEFORE_SAT_PRIORITY;

        if ($solarPlant < $plantFloor && !botCapBuildingTargetReached($solarPlant)) {
            return ['type' => 'building', 'id' => 4, 'column' => 'building_solar_plant', 'amount' => 1];
        }

        $needed = (int) ceil($energyDeficit / $satEnergy);

        return ['type' => 'ship', 'id' => 212, 'column' => 'ship_solar_satellite', 'amount' => max(1, $needed)];
    }

    $mineLevels = [
        'building_metal_mine' => (int) $planet['building_metal_mine'],
        'building_crystal_mine' => (int) $planet['building_crystal_mine'],
        'building_deuterium_sintetizer' => (int) $planet['building_deuterium_sintetizer'],
    ];

    $averageMine = array_sum($mineLevels) / 3;
    $robotFactory = (int) $planet['building_robot_factory'];
    $shipyardLevel = (int) $planet['building_hangar'];
    $naniteFactory = (int) $planet['building_nano_factory'];
    $storageLevels = [
        'building_metal_store' => (int) $planet['building_metal_store'],
        'building_crystal_store' => (int) $planet['building_crystal_store'],
        'building_deuterium_tank' => (int) $planet['building_deuterium_tank'],
    ];

    // If any storage is near full, prioritize that storage immediately.
    $metalMax = max(1, maxStorageFromLevel((int) $planet['building_metal_store'], (float) ($GLOBALS['resourceMultiplier'] ?? 1.0)));
    $crystalMax = max(1, maxStorageFromLevel((int) $planet['building_crystal_store'], (float) ($GLOBALS['resourceMultiplier'] ?? 1.0)));
    $deuteriumMax = max(1, maxStorageFromLevel((int) $planet['building_deuterium_tank'], (float) ($GLOBALS['resourceMultiplier'] ?? 1.0)));
    $storageFill = [
        'building_metal_store' => (float) $planet['planet_metal'] / $metalMax,
        'building_crystal_store' => (float) $planet['planet_crystal'] / $crystalMax,
        'building_deuterium_tank' => (float) $planet['planet_deuterium'] / $deuteriumMax,
    ];
    arsort($storageFill);
    $topStorage = array_key_first($storageFill);
    if (($storageFill[$topStorage] ?? 0) >= 0.90) {
        $storageIdByColumn = [
            'building_metal_store' => 22,
            'building_crystal_store' => 23,
            'building_deuterium_tank' => 24,
        ];

        return ['type' => 'building', 'id' => $storageIdByColumn[$topStorage], 'column' => $topStorage, 'amount' => 1];
    }

    // Common baseline phase for all bot styles.
    if ((int) $planet['building_metal_mine'] < 10) {
        return ['type' => 'building', 'id' => 1, 'column' => 'building_metal_mine', 'amount' => 1];
    }
    if ((int) $planet['building_crystal_mine'] < 8) {
        return ['type' => 'building', 'id' => 2, 'column' => 'building_crystal_mine', 'amount' => 1];
    }
    if ((int) $planet['building_deuterium_sintetizer'] < 5) {
        return ['type' => 'building', 'id' => 3, 'column' => 'building_deuterium_sintetizer', 'amount' => 1];
    }
    if ($robotFactory < 8) {
        return ['type' => 'building', 'id' => 14, 'column' => 'building_robot_factory', 'amount' => 1];
    }
    if ($shipyardLevel < 8) {
        return ['type' => 'building', 'id' => 21, 'column' => 'building_hangar', 'amount' => 1];
    }

    $minStorage = min($storageLevels);
    if ($minStorage < (int) floor($averageMine * 0.7)) {
        asort($storageLevels);
        $targetStorage = array_key_first($storageLevels);
        $storageIdByColumn = [
            'building_metal_store' => 22,
            'building_crystal_store' => 23,
            'building_deuterium_tank' => 24,
        ];

        return ['type' => 'building', 'id' => $storageIdByColumn[$targetStorage], 'column' => $targetStorage, 'amount' => 1];
    }

    if ((int) $planet['building_laboratory'] < 12 && $averageMine >= 12) {
        return ['type' => 'building', 'id' => 31, 'column' => 'building_laboratory', 'amount' => 1];
    }

    if ($robotFactory < 15 && $averageMine >= 12) {
        return ['type' => 'building', 'id' => 14, 'column' => 'building_robot_factory', 'amount' => 1];
    }
    if ($shipyardLevel < 15 && $averageMine >= 12) {
        return ['type' => 'building', 'id' => 21, 'column' => 'building_hangar', 'amount' => 1];
    }

    if ($robotFactory >= 12 && $shipyardLevel >= 12 && $naniteFactory < 10) {
        $naniteCost = nextLevelCost($GLOBALS['pricelist'][15], $naniteFactory);
        $robotCost = nextLevelCost($GLOBALS['pricelist'][14], $robotFactory);
        $shipyardCost = nextLevelCost($GLOBALS['pricelist'][21], $shipyardLevel);
        $naniteTotal = $naniteCost['metal'] + $naniteCost['crystal'] + $naniteCost['deuterium'];
        $robotTotal = $robotCost['metal'] + $robotCost['crystal'] + $robotCost['deuterium'];
        $shipyardTotal = $shipyardCost['metal'] + $shipyardCost['crystal'] + $shipyardCost['deuterium'];
        $reference = max(1, min($robotTotal, $shipyardTotal));

        // Nanites are always priority unless they are much more expensive.
        if ($naniteTotal <= ($reference * 2.5)) {
            return ['type' => 'building', 'id' => 15, 'column' => 'building_nano_factory', 'amount' => 1];
        }
    }
    if ($naniteFactory < 6 && $robotFactory >= 10 && $shipyardLevel >= 10 && $averageMine >= 14) {
        $naniteCost = nextLevelCost($GLOBALS['pricelist'][15], $naniteFactory);
        $robotCost = nextLevelCost($GLOBALS['pricelist'][14], $robotFactory);
        $shipyardCost = nextLevelCost($GLOBALS['pricelist'][21], $shipyardLevel);
        $naniteTotal = $naniteCost['metal'] + $naniteCost['crystal'] + $naniteCost['deuterium'];
        $robotTotal = $robotCost['metal'] + $robotCost['crystal'] + $robotCost['deuterium'];
        $shipyardTotal = $shipyardCost['metal'] + $shipyardCost['crystal'] + $shipyardCost['deuterium'];
        $reference = max(1, min($robotTotal, $shipyardTotal));

        if ($naniteTotal <= ($reference * 2.5)) {
            return ['type' => 'building', 'id' => 15, 'column' => 'building_nano_factory', 'amount' => 1];
        }
    }

    if (randomFloat(0, 1) < 0.15 && (int) $planet['building_solar_plant'] < 50) {
        return ['type' => 'building', 'id' => 4, 'column' => 'building_solar_plant', 'amount' => 1];
    }

    $style = $profile['bot_style'] ?? 'granja';
    $rocketLaunchers = (int) $planet['defense_rocket_launcher'];

    if ($style === 'granja') {
        $headroom = botMiningDrillBuildableRemaining($planet);
        if ($headroom > 0 && $averageMine >= 12) {
            return ['type' => 'ship', 'id' => 216, 'column' => 'ship_mining_drill', 'amount' => min(5, $headroom)];
        }

        $desiredRocketLaunchers = max(30, (int) floor(array_sum($mineLevels) / 2));
        if ($rocketLaunchers < $desiredRocketLaunchers && randomFloat(0, 1) <= max(0.1, $profile['defense_focus'])) {
            return ['type' => 'defense', 'id' => 401, 'column' => 'defense_rocket_launcher', 'amount' => min(8, $desiredRocketLaunchers - $rocketLaunchers)];
        }

        $smallCargo = (int) $planet['ship_small_cargo_ship'];
        $desiredCargo = max(20, (int) floor(array_sum($mineLevels)));
        if ($smallCargo < $desiredCargo && randomFloat(0, 1) < 0.35) {
            return ['type' => 'ship', 'id' => 202, 'column' => 'ship_small_cargo_ship', 'amount' => min(5, $desiredCargo - $smallCargo)];
        }
    } elseif ($style === 'raider') {
        $lightFighters = (int) $planet['ship_light_fighter'];
        $desiredFighters = max(80, (int) floor(array_sum($mineLevels) * 3));
        if ($lightFighters < $desiredFighters) {
            return ['type' => 'ship', 'id' => 204, 'column' => 'ship_light_fighter', 'amount' => min(15, $desiredFighters - $lightFighters)];
        }

        $smallCargo = (int) $planet['ship_small_cargo_ship'];
        $desiredCargo = max(40, (int) floor(array_sum($mineLevels) * 1.5));
        if ($smallCargo < $desiredCargo) {
            return ['type' => 'ship', 'id' => 202, 'column' => 'ship_small_cargo_ship', 'amount' => min(10, $desiredCargo - $smallCargo)];
        }

        $desiredRocketLaunchers = max(40, (int) floor(array_sum($mineLevels) * 0.8));
        if ($rocketLaunchers < $desiredRocketLaunchers) {
            return ['type' => 'defense', 'id' => 401, 'column' => 'defense_rocket_launcher', 'amount' => min(12, $desiredRocketLaunchers - $rocketLaunchers)];
        }
    } else { // bunker
        $desiredRocketLaunchers = max(120, (int) floor(array_sum($mineLevels) * 2.2));
        if ($rocketLaunchers < $desiredRocketLaunchers) {
            return ['type' => 'defense', 'id' => 401, 'column' => 'defense_rocket_launcher', 'amount' => min(25, $desiredRocketLaunchers - $rocketLaunchers)];
        }

        $lightLasers = (int) $planet['defense_light_laser'];
        $desiredLightLasers = max(40, (int) floor(array_sum($mineLevels)));
        if ($lightLasers < $desiredLightLasers) {
            return ['type' => 'defense', 'id' => 402, 'column' => 'defense_light_laser', 'amount' => min(10, $desiredLightLasers - $lightLasers)];
        }

        $smallCargo = (int) $planet['ship_small_cargo_ship'];
        $desiredCargo = max(12, (int) floor(array_sum($mineLevels) * 0.5));
        if ($smallCargo < $desiredCargo && randomFloat(0, 1) < 0.4) {
            return ['type' => 'ship', 'id' => 202, 'column' => 'ship_small_cargo_ship', 'amount' => min(4, $desiredCargo - $smallCargo)];
        }
    }

    $idByColumn = [
        'building_metal_mine' => 1,
        'building_crystal_mine' => 2,
        'building_deuterium_sintetizer' => 3,
    ];

    // "Humanized" eco: mostly weakest mine, sometimes second weakest.
    asort($mineLevels);
    $sortedMineColumns = array_keys($mineLevels);
    $targetMine = $sortedMineColumns[0];
    if (count($sortedMineColumns) > 1 && randomFloat(0, 1) < (1 - $profile['eco_focus']) * 0.6) {
        $targetMine = $sortedMineColumns[1];
    }

    return ['type' => 'building', 'id' => $idByColumn[$targetMine], 'column' => $targetMine, 'amount' => 1];
}

// Colonization and transports were moved to dedicated modules:
//   scripts/bot_lib/colonization.php  (botColonizationTryColonize)
//   scripts/bot_lib/transport.php     (botTransportRunPurposeful)
//   scripts/bot_lib/travel.php        (real distance/duration/fuel helpers)
// They use the in-game FleetsLib formulas (real ETA + fuel) and run multiple
// purposeful transports per loop instead of one fixed home-bound flight.

function getQueuedBuildingLevel(array $planet, int $buildingId, int $fallbackLevel): int
{
    return max($fallbackLevel, botMaxQueuedBuildingTargetLevel($planet, $buildingId));
}

/**
 * Sums the amount of a given hangar item (ship or defense) currently pending
 * in this planet's hangar queue. Returns 0 if the queue is empty or doesn't
 * contain that item.
 *
 * Used so the bot, when deciding whether to enqueue more units in the same
 * loop, accounts for what it just enqueued moments ago. Without this the bot
 * would keep stacking identical hangar orders every action slot until the
 * planet runs out of resources.
 */
function applyPlanetAction(mysqli $db, string $prefix, array &$planet, array $action, array $pricelist, bool $dryRun, float $universeSpeed): string
{
    $planetId = (int) $planet['planet_id'];
    $actionId = (int) $action['id'];
    $column = $action['column'];
    $amount = (int) $action['amount'];
    $current = (int) $planet[$column];

    if (!isset($pricelist[$actionId])) {
        return "planet {$planetId}: no price for id {$actionId}";
    }

    if ($action['type'] === 'building') {
        $current = getQueuedBuildingLevel($planet, $actionId, $current);
        if (botCapBuildingTargetReached($current)) {
            return "planet {$planetId}: building cap reached for {$column} ({$current}/" . BOT_CAP_BUILDING_LEVEL . ')';
        }
        $cost = nextLevelCost($pricelist[$actionId], $current);
        if (botCapCostExceeded($cost)) {
            return "planet {$planetId}: cost cap exceeded for {$column}+1";
        }
        if (!canAfford($planet, $cost)) {
            return "planet {$planetId}: no resources for {$column}+1";
        }

        if ($dryRun) {
            return "planet {$planetId}: would upgrade {$column} to " . ($current + 1);
        }

        if (!queueBuildingAction($db, $prefix, $planet, $actionId, $current, $cost, $universeSpeed)) {
            return "planet {$planetId}: building queue full (5)";
        }

        $queueState = $db->query(
            "SELECT `planet_b_building_id`, `planet_b_building`
             FROM `{$prefix}planets`
             WHERE `planet_id` = {$planetId}
             LIMIT 1"
        );
        $queueRow = $queueState ? $queueState->fetch_assoc() : null;
        if ($queueState) {
            $queueState->free();
        }

        if (is_array($queueRow)) {
            $planet['planet_b_building_id'] = (string) ($queueRow['planet_b_building_id'] ?? '0');
            $planet['planet_b_building'] = (int) ($queueRow['planet_b_building'] ?? 0);
        }

        return "planet {$planetId}: queued {$column} to " . ($current + 1);
    }

    // Clamp unit amount to (a) the absolute per-type cap, and (b) the
    // per-queue-entry cap mirroring the in-game 9999/row limit. botCapClampUnitAmount
    // already returns the min of both; we just record which one bit when we
    // can't enqueue anything at all.
    $currentUnits = (int) ($planet[$column] ?? 0);
    if (($column === 'ship_mining_drill' || $actionId === 216) && function_exists('botEffectiveUnitCount')) {
        $currentUnits = botEffectiveUnitCount($planet, 216, 'ship_mining_drill');
        $drillHeadroom = botMiningDrillBuildableRemaining($planet);
        $amount = min($amount, max(0, $drillHeadroom));
    }
    $amount = botCapClampUnitAmount($currentUnits, $amount);
    if ($amount <= 0) {
        if (($column === 'ship_mining_drill' || $actionId === 216) && botMiningDrillBuildableRemaining($planet) <= 0) {
            return "planet {$planetId}: mining drill planet cap (" . BOT_MINING_DRILL_LIMIT_PER_PLANET . ', built+queue)';
        }
        if ($currentUnits >= BOT_CAP_UNIT_PER_TYPE) {
            return "planet {$planetId}: unit cap reached for {$column} ({$currentUnits}/" . BOT_CAP_UNIT_PER_TYPE . ')';
        }

        return "planet {$planetId}: queue batch cap reached for {$column} (max " . BOT_CAP_UNITS_PER_QUEUE_ENTRY . ' per entry)';
    }

    $unitCost = [
        'metal' => (int) ($pricelist[$actionId]['metal'] ?? 0),
        'crystal' => (int) ($pricelist[$actionId]['crystal'] ?? 0),
        'deuterium' => (int) ($pricelist[$actionId]['deuterium'] ?? 0),
    ];
    $cost = [
        'metal' => $unitCost['metal'] * $amount,
        'crystal' => $unitCost['crystal'] * $amount,
        'deuterium' => $unitCost['deuterium'] * $amount,
    ];

    if (botCapCostExceeded($cost)) {
        return "planet {$planetId}: cost cap exceeded for {$column}+{$amount}";
    }

    if (!canAfford($planet, $cost)) {
        return "planet {$planetId}: no resources for {$column}+{$amount}";
    }

    if (in_array($action['type'], ['ship', 'defense'], true) && !botPlanetCanQueueShipyardUnits($planet)) {
        return "planet {$planetId}: shipyard (hangar) required or robotics/nanite/hangar upgrade blocks production";
    }

    if ($dryRun) {
        return "planet {$planetId}: would build {$column} +{$amount}";
    }

    if (!payResources($db, $prefix, $planetId, $cost)) {
        return "planet {$planetId}: failed paying {$column}";
    }

    if (!queueShipyardAction($db, $prefix, $planet, $actionId, $amount)) {
        return "planet {$planetId}: failed queueing {$column}";
    }

    $planet['planet_metal'] = max(0, (float) $planet['planet_metal'] - $cost['metal']);
    $planet['planet_crystal'] = max(0, (float) $planet['planet_crystal'] - $cost['crystal']);
    $planet['planet_deuterium'] = max(0, (float) $planet['planet_deuterium'] - $cost['deuterium']);

    // Mirror the hangar queue locally so subsequent decisions in the same
    // loop see what we just enqueued. Without this, decision logic that
    // looks at $planet['planet_b_hangar_id'] (e.g. getQueuedHangarAmount)
    // would re-decide the exact same action repeatedly until resources run
    // out.
    $existingQueue = (string) ($planet['planet_b_hangar_id'] ?? '');
    $planet['planet_b_hangar_id'] = $existingQueue . $actionId . ',' . $amount . ';';

    return "planet {$planetId}: queued {$column} +{$amount}";
}

function getResearchRequirementBlocker(int $researchId, array $homePlanet, array $researchRow): ?string
{
    $requirements = $GLOBALS['requeriments'][$researchId] ?? null;
    $resourceMap = $GLOBALS['resource'] ?? [];
    if (!is_array($requirements) || empty($requirements) || !is_array($resourceMap)) {
        return null;
    }

    foreach ($requirements as $reqId => $reqLevel) {
        $column = $resourceMap[$reqId] ?? null;
        if (!is_string($column) || $column === '') {
            continue;
        }

        $currentLevel = 0;
        if (array_key_exists($column, $homePlanet)) {
            $currentLevel = (int) $homePlanet[$column];
        } elseif (array_key_exists($column, $researchRow)) {
            $currentLevel = (int) $researchRow[$column];
        }

        if ($currentLevel < (int) $reqLevel) {
            return "{$column} {$currentLevel}/{$reqLevel}";
        }
    }

    return null;
}

function getMissingResourceSummary(array $planet, array $cost): string
{
    $parts = [];
    foreach (['metal', 'crystal', 'deuterium'] as $res) {
        $need = max(0, (int) ($cost[$res] ?? 0) - (int) ($planet["planet_{$res}"] ?? 0));
        if ($need > 0) {
            $parts[] = "{$res}+{$need}";
        }
    }

    return empty($parts) ? 'unknown' : implode(', ', $parts);
}

/**
 * Picks the planet where a new tech queue should start: highest effective lab
 * among planets that meet requirements, can pay, and have no research timer.
 *
 * @param array<int, array<string, mixed>> $allPlanets
 * @param array<string, mixed> $researchRow
 */
function botPickPlanetForQueuedResearch(array $allPlanets, array $researchRow, int $techId, array $cost): ?array
{
    $best = null;
    $bestLab = -1;
    foreach ($allPlanets as $p) {
        if (!is_array($p)) {
            continue;
        }
        if ((int) ($p['planet_b_tech_id'] ?? 0) !== 0) {
            continue;
        }
        if (getResearchRequirementBlocker($techId, $p, $researchRow) !== null) {
            continue;
        }
        if (!canAfford($p, $cost)) {
            continue;
        }
        $lab = botEffectiveBuildingUpgradeLevel($p, 31, 'building_laboratory');
        $pid = (int) ($p['planet_id'] ?? 0);
        if ($lab > $bestLab || ($lab === $bestLab && ($best === null || $pid > (int) ($best['planet_id'] ?? 0)))) {
            $bestLab = $lab;
            $best = $p;
        }
    }

    return $best;
}

function tryResearch(
    mysqli $db,
    string $prefix,
    int $userId,
    array $allPlanets,
    array $researchRow,
    array $pricelist,
    bool $dryRun,
    float $universeSpeed,
    ?array $state = null
): string {
    if ((int) $researchRow['research_current_research'] !== 0) {
        return 'research: already in progress';
    }
    if ($allPlanets === []) {
        return 'research: no planets';
    }

    $state = $state ?? [];

    // Use the per-bot personalized research order if available; otherwise
    // fall back to the legacy hardcoded list. The personalized order respects
    // dependencies (foundational items first) and is bounded by the safety
    // research cap.
    $priority = is_array($state['bot_research_order'] ?? null) && !empty($state['bot_research_order'])
        ? $state['bot_research_order']
        : [
            ['id' => 113, 'column' => 'research_energy_technology', 'target' => 12],
            ['id' => 120, 'column' => 'research_laser_technology', 'target' => 12],
            ['id' => 122, 'column' => 'research_plasma_technology', 'target' => 8],
            ['id' => 108, 'column' => 'research_computer_technology', 'target' => 10],
            ['id' => 124, 'column' => 'research_astrophysics', 'target' => 8],
        ];

    $firstBlockedReason = null;
    $firstUnaffordableReason = null;

    foreach ($priority as $item) {
        $current = (int) ($researchRow[$item['column']] ?? 0);
        // Hard cap: never research above the safety ceiling.
        if (botCapResearchTargetReached($current)) {
            continue;
        }
        if ($current >= (int) $item['target']) {
            continue;
        }

        $techId = (int) $item['id'];
        if (!isset($pricelist[$techId]) || !is_array($pricelist[$techId])) {
            continue;
        }

        $cost = nextLevelCost($pricelist[$techId], $current);
        $working = botPickPlanetForQueuedResearch($allPlanets, $researchRow, $techId, $cost);
        if ($working === null) {
            $probe = botPlanetWithMaxLaboratory($allPlanets);
            if ($probe !== null) {
                if ($firstBlockedReason === null) {
                    $b = getResearchRequirementBlocker($techId, $probe, $researchRow);
                    if ($b !== null) {
                        $firstBlockedReason = "research: blocked {$item['column']} ({$b})";
                    } elseif (!canAfford($probe, $cost)) {
                        if ($firstUnaffordableReason === null) {
                            $missing = getMissingResourceSummary($probe, $cost);
                            $firstUnaffordableReason = "research: no resources for {$item['column']} ({$missing})";
                        }
                    } else {
                        if ($firstBlockedReason === null) {
                            $firstBlockedReason = "research: blocked {$item['column']} (research lab busy on eligible planets)";
                        }
                    }
                }
            }

            continue;
        }

        $irn = (int) ($researchRow['research_intergalactic_research_network'] ?? 0);
        if ($irn < 1) {
            $labForTime = botEffectiveBuildingUpgradeLevel($working, 31, 'building_laboratory');
        } else {
            $labForTime = botSumTopLaboratoriesLevel($allPlanets, max(1, $irn + 1));
        }
        $seconds = botResearchSecondsForNextTechLevel(
            $pricelist[$techId],
            $current,
            $labForTime,
            (int) ($researchRow['research_astrophysics'] ?? 0),
            $universeSpeed
        );
        $pid = (int) $working['planet_id'];
        $endTs = time() + $seconds;

        if ($dryRun) {
            return "research: would queue {$item['column']} on planet {$pid} ({$seconds}s)";
        }

        if (!payResources($db, $prefix, $pid, $cost)) {
            if ($firstUnaffordableReason === null) {
                $firstUnaffordableReason = "research: no resources for {$item['column']} (planet {$pid})";
            }

            continue;
        }

        $db->query(
            "UPDATE `{$prefix}planets` AS p, `{$prefix}research` AS r SET
                p.`planet_b_tech_id` = {$techId},
                p.`planet_b_tech` = {$endTs},
                r.`research_current_research` = {$pid}
             WHERE p.`planet_id` = {$pid}
               AND r.`research_user_id` = {$userId}"
        );

        return "research: queued {$item['column']} on planet {$pid} ({$seconds}s)";
    }

    if ($firstBlockedReason !== null) {
        return $firstBlockedReason;
    }

    if ($firstUnaffordableReason !== null) {
        return $firstUnaffordableReason;
    }

    return 'research: no target pending';
}

$username = argValue($argv, '--user', null);
$usersArg = argValue($argv, '--users', null);
$configArg = argValue($argv, '--config', null);
$defaultBotAccountsJson = __DIR__ . DIRECTORY_SEPARATOR . 'bot_accounts.json';
if ($configArg !== null && trim($configArg) !== '') {
    $configPath = resolveBotConfigPath(trim($configArg));
} elseif (is_readable($defaultBotAccountsJson)) {
    $rp = realpath($defaultBotAccountsJson);
    $configPath = $rp !== false ? $rp : $defaultBotAccountsJson;
} else {
    $configPath = null;
}
$loops = (int) argValue($argv, '--loops', '1');
$sleepSeconds = (int) argValue($argv, '--sleep', '30');
$dryRun = hasFlag($argv, '--dry-run');
$runForever = hasFlag($argv, '--forever');
$randomDelayMs = (int) argValue($argv, '--jitter-ms', '1200');

if ($loops < 1 && !$runForever) {
    $loops = 1;
}

if (!class_exists('mysqli')) {
    exit("The mysqli extension is not enabled in this PHP runtime.\n");
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    exit("Database connection failed: {$db->connect_error}\n");
}
$db->set_charset('utf8');
$prefix = DB_PREFIX;

// Validate the schema is ready before doing anything else: missing columns
// or wrong types lead to data loss / overflows further down. The helper
// prints a precise per-error remediation hint and exits if anything is off.
botSchemaAssertOrExit($db, $prefix);

$universeSpeed = getUniverseSpeed($db, $prefix);
$resourceMultiplier = getResourceMultiplier($db, $prefix);
$GLOBALS['resourceMultiplier'] = $resourceMultiplier;
$basicIncome = getBasicIncomeConfig($db, $prefix);
$profiles = loadProfiles($configPath);
$accountProfileNames = loadAccountProfileNames($configPath);
$botAllianceAccountSet = loadBotAllianceAccountSet($configPath);

if ($configArg === null || trim((string) $configArg) === '') {
    if ($configPath !== null) {
        echo "Config: (default) {$configPath}\n";
    }
} else {
    echo "Config: {$configPath}\n";
}

$requestedUsers = [];
if ($username !== null) {
    $requestedUsers[] = $username;
}
$requestedUsers = array_merge($requestedUsers, parseUsersList($usersArg), array_keys($profiles));
$requestedUsers = array_values(array_unique(array_filter($requestedUsers)));

if ($configPath !== null && trim($configPath) !== '' && empty($requestedUsers)) {
    exit("No users were loaded from config file: {$configPath}. Aborting to avoid processing all accounts.\n");
}

if (!empty($requestedUsers)) {
    echo 'Target users loaded: ' . count($requestedUsers) . "\n";
    echo 'Target user names: ' . implode(',', $requestedUsers) . "\n";
}

echo 'Alliance/diplomacy whitelist: ' . count($botAllianceAccountSet) . " account(s)\n";

$i = 0;
while (true) {
    $i++;
    $usersSql = "SELECT `user_id`, `user_name`, `user_home_planet_id`,
                        `user_ally_id`, `user_ally_request`, `user_ally_register_time`,
                        `user_onlinetime`, `user_register_time`
                 FROM `{$prefix}users`
                 WHERE `user_authlevel` = 0";
    if (!empty($requestedUsers)) {
        $escapedUsers = array_map(
            static fn (string $user): string => "'" . $db->real_escape_string($user) . "'",
            $requestedUsers
        );
        $usersSql .= ' AND `user_name` IN (' . implode(',', $escapedUsers) . ')';
    }

    $usersResult = $db->query($usersSql);
    if (!$usersResult) {
        exit("Failed querying users: {$db->error}\n");
    }

    $loopLabel = $runForever ? '∞' : (string) $loops;
    echo "---- Bot loop {$i}/{$loopLabel} ----\n";

    while ($user = $usersResult->fetch_assoc()) {
        $userId = (int) $user['user_id'];
        $userName = $user['user_name'];
        $profile = normalizeProfile($profiles[$userName] ?? []);
        $profileName = $accountProfileNames[$userName] ?? 'default';

        // Mark the bot as "online" so any subsystem (alliance kick policy,
        // inactivity heuristics) treats it as an active player. We update
        // the user row in-place and the in-memory $user so subsequent
        // helpers see the fresh value.
        $nowTs = time();
        $db->query(
            "UPDATE `{$prefix}users` SET `user_onlinetime` = {$nowTs} WHERE `user_id` = {$userId} LIMIT 1"
        );
        $user['user_onlinetime'] = $nowTs;

        // Per-bot persistent state. Created lazily on first run with values
        // derived from a deterministic seed (user_id + user_name).
        $botState = ensureBotState($db, $prefix, $userId, $userName, (string) $profile['bot_style']);

        // Maintenance: focus rotation, attack reactivity, long-term goal.
        botUpdateFocusIfDue($db, $prefix, $botState);
        botDetectRecentAttack($db, $prefix, $botState);
        if (function_exists('botHeatDecayTick')) {
            botHeatDecayTick($db, $prefix, $userId, time());
        }
        botMaintainLongTermGoal($db, $prefix, $botState);

        $llmInboxLogs = [];
        if (!$dryRun) {
            $llmInboxLogs = botLlmInboxTick($db, $prefix, $user, $profileName, $profile);
            foreach ($llmInboxLogs as $inboxLine) {
                botLogWriteRaw($userName, $inboxLine);
            }
        }

        $isAllianceBotAccount = isset($botAllianceAccountSet[$userName]);

        $allianceLogs = [];
        if (!$dryRun && $isAllianceBotAccount) {
            $allianceLogs = botAllianceTick(
                $db,
                $prefix,
                $user,
                $botState,
                $profile,
                [],
                time(),
                $i
            );
            $diploLogs = botDiplomacyTick($db, $prefix, $botState, $user, time());
            foreach ($diploLogs as $dl) {
                $allianceLogs[] = $dl;
            }
            saveBotStateFields($db, $prefix, $userId, ['bot_quirks' => $botState['bot_quirks']]);
        }

        echo "[{$userName}] profile={$profileName} style={$profile['bot_style']}"
            . " arch={$botState['bot_archetype']}"
            . " pers={$botState['bot_personality']}"
            . " focus={$botState['bot_current_focus']}"
            . ($llmInboxLogs !== [] ? ' | ' . implode(' | ', $llmInboxLogs) : '')
            . ' ';

        if (!isInActiveWindow($profile)) {
            // Outside the bot's active window we still pick up intel from
            // spies that already arrived: otherwise the snapshot would be
            // captured the next time the bot wakes up and look "fresh"
            // even if the real data is hours old. We do NOT send new spies
            // or attacks here — a sleeping bot must stay invisible to
            // observers.
            $harvestLogs = botAttackHarvestOnly(
                $db,
                $prefix,
                $botState,
                $GLOBALS['pricelist'] ?? []
            );
            foreach ($allianceLogs as $allyLine) {
                botLogWriteRaw($userName, $allyLine);
            }
            $sleepParts = [];
            if (!empty($allianceLogs)) {
                $sleepParts = array_merge($sleepParts, $allianceLogs);
            }
            if (!empty($harvestLogs)) {
                $sleepParts = array_merge($sleepParts, $harvestLogs);
            }
            if ($sleepParts !== []) {
                echo 'sleep window | ' . implode(' | ', $sleepParts) . "\n";
            } else {
                echo "sleep window\n";
            }

            continue;
        }

        $planets = getPlanetRows($db, $prefix, $userId);
        if (empty($planets)) {
            echo "no planets\n";

            continue;
        }

        $homePlanetId = (int) $user['user_home_planet_id'];
        $homePlanet = null;
        foreach ($planets as $planet) {
            if ((int) $planet['planet_id'] === $homePlanetId) {
                $homePlanet = $planet;

                break;
            }
        }
        if ($homePlanet === null) {
            $homePlanet = $planets[0];
        }

        // Initialize personality (targets, defense recipe, research order,
        // planet roles, quirks) the first time we see this bot, or whenever
        // a planet is added.
        botInitializePersonalityIfMissing($db, $prefix, $botState, $planets, $homePlanetId);

        // Per-bot loop tunables override the legacy profile-wide ones so two
        // bots in the same profile take different numbers of actions.
        $perBotActions = (int) ($botState['bot_actions_per_loop'] ?? $profile['actions_per_loop']);
        $perBotSkipChance = (int) ($botState['bot_skip_chance_x100'] ?? (int) ($profile['skip_chance'] * 100));
        $maxActions = $perBotActions;
        if ($perBotSkipChance > 0 && randomFloat(0, 100) < $perBotSkipChance) {
            $maxActions = max(0, $maxActions - 1);
        }
        $extraPlanets = max(0, count($planets) - 1);
        $maxActions += $extraPlanets;
        if ($maxActions <= 0) {
            echo "idle tick\n";

            continue;
        }

        $logs = $allianceLogs;
        botLogHeader($userName, $botState, $i, $loopLabel);

        // Pre-load the user's research row up-front. botRecomputePlanetStats
        // evaluates production formulas that may reference energy technology.
        $researchResult = $db->query(
            "SELECT * FROM `{$prefix}research`
             WHERE `research_user_id` = {$userId}
             LIMIT 1"
        );
        $researchRow = $researchResult ? $researchResult->fetch_assoc() : null;
        $researchEnergyTech = $researchRow !== null
            ? (int) ($researchRow['research_energy_technology'] ?? 0)
            : 0;

        // Sort planets so the most-deserving (idle resources, no queue) comes
        // first; home planet stays near the head.
        $planets = botSortPlanetsForActions($planets, $homePlanetId);
        $planetCount = count($planets);
        $resourceBlockedPlanets = [];
        $buildingQueuedByPlanet = [];
        // Per-(planet, column) safety net: never enqueue the same target
        // twice within the same outer loop. Decision logic already accounts
        // for the queue (see botEffectiveUnitCount), but a duplicate slipping
        // through would still be a bug, so we deduplicate explicitly.
        $columnQueuedByPlanet = [];

        for ($actionSlot = 0; $actionSlot < $maxActions; $actionSlot++) {
            $planetIndex = $actionSlot % $planetCount;
            if (isset($resourceBlockedPlanets[$planetIndex])) {
                continue;
            }
            $planet = $planets[$planetIndex];
            $planetId = (int) $planet['planet_id'];
            refreshPlanetResources($db, $prefix, $planet, $resourceMultiplier);
            processCompletedBuildingQueue($db, $prefix, $planet);
            processCompletedHangarQueue($db, $prefix, $planet, $universeSpeed);

            // Recompute energy and per-hour production now that the queue
            // changes have been applied. The web app does this on every
            // page load; bots otherwise see stale values forever and
            // skip the energy override even when they're in the red.
            botRecomputePlanetStats($db, $prefix, $planet, $researchEnergyTech, $basicIncome);

            // Self-heal: if the planet has stalled for days, force a role
            // re-roll so the bot tries something different.
            botDetectStuckPlanet($db, $prefix, $botState, $planet);

            $GLOBALS['bot_universe_speed'] = $universeSpeed;
            $action = botDecideAction($planet, $profile, $botState, $researchRow, $planets, $homePlanetId);
            if ($action === null) {
                $logs[] = "planet {$planetId}: no candidate action";

                continue;
            }
            if ($action['type'] === 'building' && isset($buildingQueuedByPlanet[$planetId])) {
                continue;
            }
            $actionColumn = (string) ($action['column'] ?? '');
            if ($actionColumn !== '' && isset($columnQueuedByPlanet[$planetId][$actionColumn])) {
                $logs[] = "planet {$planetId}: skipped duplicate {$actionColumn} this loop";

                continue;
            }
            // Queue intelligence: avoid filling the building queue with
            // small items that block important upcoming upgrades.
            if (botShouldThrottleQueue($planet, $action)) {
                $logs[] = "planet {$planetId}: queue busy, throttled {$action['column']}";

                continue;
            }
            $actionLog = applyPlanetAction($db, $prefix, $planet, $action, $pricelist, $dryRun, $universeSpeed);
            $logs[] = $actionLog;
            if (strpos($actionLog, ': queued ') !== false) {
                if (strpos($actionLog, ': queued building_') !== false) {
                    $buildingQueuedByPlanet[$planetId] = true;
                }
                if ($actionColumn !== '') {
                    $columnQueuedByPlanet[$planetId][$actionColumn] = true;
                }
                // Track big-cargo enqueues that happened while the
                // cargo_pressure signal was active. Lets the admin see
                // whether the signal is actually translating into builds
                // (and not getting drowned by storage/energy overrides).
                if (
                    $actionColumn === 'ship_big_cargo_ship'
                    && function_exists('botCargoPressureActive')
                    && botCargoPressureActive($botState, time())
                ) {
                    botCargoPressureRecordQueued($botState, (int) ($action['amount'] ?? 0));
                }
            }
            if (strpos($actionLog, ': no resources for ') !== false) {
                $resourceBlockedPlanets[$planetIndex] = true;
            }
            $planets[$planetIndex] = $planet;

            if ($randomDelayMs > 0 && !$dryRun) {
                usleep((int) randomFloat(100000, max(100000, $randomDelayMs * 1000)));
            }
        }

        if (!$dryRun) {
            // Purposeful transports: only run when researchRow is loaded
            // (we need it to compute travel speed / fuel for cargo ships).
            if ($researchRow !== null) {
                $transportLogs = botTransportRunPurposeful(
                    $db,
                    $prefix,
                    $user,
                    $planets,
                    $homePlanet,
                    $researchRow,
                    $pricelist,
                    $botState,
                    $universeSpeed
                );
                foreach ($transportLogs as $tl) {
                    $logs[] = $tl;
                }
                if ($isAllianceBotAccount) {
                    $allyTransportLogs = botAllyLogisticsRun(
                        $db,
                        $prefix,
                        $user,
                        $planets,
                        $researchRow,
                        $pricelist,
                        $botState,
                        $universeSpeed
                    );
                    foreach ($allyTransportLogs as $al) {
                        $logs[] = $al;
                    }
                }
            }

            if ($researchRow !== null) {
                $colonizeLog = botColonizationTryColonize(
                    $db,
                    $prefix,
                    $user,
                    $planets,
                    $researchRow,
                    $botState,
                    $universeSpeed
                );
                if ($colonizeLog !== null) {
                    $logs[] = $colonizeLog;
                }
            }

            // Purposeful attacks (spy + battle simulation + raid). Runs
            // after colonization so newly-acquired planets aren't immediately
            // used as a launching pad before they have ships.
            if ($researchRow !== null) {
                $attackLogs = botAttackRunPurposeful(
                    $db,
                    $prefix,
                    $user,
                    $planets,
                    $researchRow,
                    $pricelist,
                    $botState,
                    $universeSpeed,
                    $profile,
                    $profileName
                );
                foreach ($attackLogs as $al) {
                    $logs[] = $al;
                }

                $acsLogs = botAcsRunPurposeful(
                    $db,
                    $prefix,
                    $user,
                    $planets,
                    $researchRow,
                    $pricelist,
                    $botState,
                    $universeSpeed
                );
                foreach ($acsLogs as $al) {
                    $logs[] = $al;
                }
            }
        }

        if ($researchRow && $homePlanet && randomFloat(0, 1) <= $profile['research_focus']) {
            $logs[] = tryResearch($db, $prefix, $userId, $planets, $researchRow, $pricelist, $dryRun, $universeSpeed, $botState);
        }

        // Persist a couple of liveness counters for the bot.
        if (empty($botState['__synthetic'])) {
            saveBotStateFields($db, $prefix, $userId, [
                'bot_last_action_at' => time(),
                'bot_total_actions' => (int) ($botState['bot_total_actions'] ?? 0) + count($logs),
                'bot_quirks' => $botState['bot_quirks'],
            ]);
        }

        botLogActions($userName, $logs);
        echo implode(' | ', $logs) . "\n";
    }

    $usersResult->free();

    if (!$dryRun && $i % 5 === 0) {
        if (class_exists('\App\Libraries\StatisticsLibrary')) {
            try {
                $stats = new \App\Libraries\StatisticsLibrary();
                $result = $stats->makeStats();
                $statsTime = isset($result['stats_time']) ? (int) $result['stats_time'] : time();
                $db->query(
                    "UPDATE `{$prefix}options`
                     SET `option_value` = {$statsTime}
                     WHERE `option_name` = 'stat_last_update'
                     LIMIT 1"
                );
                echo "stats: rebuilt ranking on loop {$i}\n";
            } catch (\Throwable $e) {
                echo "stats: rebuild failed ({$e->getMessage()})\n";
            }
        } else {
            echo "stats: rebuild skipped (StatisticsLibrary unavailable)\n";
        }
    }

    if (!$runForever && $i >= $loops) {
        break;
    }

    if ($sleepSeconds > 0) {
        sleep($sleepSeconds);
    }
}

echo "Done.\n";
