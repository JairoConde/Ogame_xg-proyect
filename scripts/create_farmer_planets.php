<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function argValue(array $argv, string $key, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (strpos($arg, $key . '=') === 0) {
            return substr($arg, strlen($key) + 1);
        }
    }

    return $default;
}

$username = argValue($argv, '--user', 'granjero');
$targetTotal = (int) argValue($argv, '--total', '20');
$targetGalaxy = (int) argValue($argv, '--galaxy', '1');
$startSystem = (int) argValue($argv, '--start-system', '1');
$applyExisting = argValue($argv, '--apply-existing', '0') === '1';
$allowedPositions = [3, 4, 5, 11, 12, 13];

if ($targetTotal < 1) {
    exit("Invalid --total value.\n");
}

if ($targetGalaxy < 1 || $targetGalaxy > MAX_GALAXY_IN_WORLD) {
    exit('Invalid --galaxy value. Valid range: 1-' . MAX_GALAXY_IN_WORLD . "\n");
}

if ($startSystem < 1 || $startSystem > MAX_SYSTEM_IN_GALAXY) {
    exit('Invalid --start-system value. Valid range: 1-' . MAX_SYSTEM_IN_GALAXY . "\n");
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($db->connect_error) {
    exit("Database connection failed: {$db->connect_error}\n");
}

$db->set_charset('utf8');
$prefix = DB_PREFIX;

$usernameEscaped = $db->real_escape_string($username);
$userResult = $db->query("SELECT `user_id` FROM `{$prefix}users` WHERE `user_name` = '{$usernameEscaped}' LIMIT 1");
$userData = $userResult ? $userResult->fetch_assoc() : null;

if (!$userData) {
    exit("User '{$username}' not found.\n");
}

$userId = (int) $userData['user_id'];

$sourceResult = $db->query(
    "SELECT * FROM `{$prefix}planets`
    WHERE `planet_user_id` = {$userId} AND `planet_type` = 1
    ORDER BY `planet_id` ASC
    LIMIT 1"
);
$sourcePlanet = $sourceResult ? $sourceResult->fetch_assoc() : null;

if (!$sourcePlanet) {
    exit("User '{$username}' has no base planet to clone.\n");
}

$countResult = $db->query(
    "SELECT COUNT(*) AS `total`
    FROM `{$prefix}planets`
    WHERE `planet_user_id` = {$userId} AND `planet_type` = 1"
);
$countRow = $countResult ? $countResult->fetch_assoc() : ['total' => 0];
$currentTotal = (int) $countRow['total'];

$toCreate = $targetTotal - $currentTotal;
if ($toCreate <= 0 && !$applyExisting) {
    exit("Nothing to do. User '{$username}' already has {$currentTotal} planets. Use --apply-existing=1 to update building levels.\n");
}

$db->begin_transaction();

try {
    $updatedExisting = 0;

    if ($applyExisting) {
        $existingPlanetsResult = $db->query(
            "SELECT `planet_id`
            FROM `{$prefix}planets`
            WHERE `planet_user_id` = {$userId}
              AND `planet_type` = 1"
        );

        while ($existingPlanet = $existingPlanetsResult->fetch_assoc()) {
            $existingPlanetId = (int) $existingPlanet['planet_id'];

            $updateBuildingsSql = "UPDATE `{$prefix}buildings` SET
                `building_metal_mine` = 40,
                `building_crystal_mine` = 40,
                `building_deuterium_sintetizer` = 40,
                `building_solar_plant` = 50,
                `building_metal_store` = 30,
                `building_crystal_store` = 30,
                `building_deuterium_tank` = 30
                WHERE `building_planet_id` = {$existingPlanetId}
                LIMIT 1";

            if (!$db->query($updateBuildingsSql)) {
                throw new RuntimeException('Could not update existing planet buildings: ' . $db->error);
            }

            $updatedExisting++;
        }
    }

    $created = 0;
    $planetCounter = 1;

    for ($system = $startSystem; $system <= MAX_SYSTEM_IN_GALAXY && $created < $toCreate; $system++) {
        foreach ($allowedPositions as $position) {
            if ($created >= $toCreate) {
                break;
            }

            $existsResult = $db->query(
                "SELECT `planet_id`
                FROM `{$prefix}planets`
                WHERE `planet_galaxy` = {$targetGalaxy}
                  AND `planet_system` = {$system}
                  AND `planet_planet` = {$position}
                  AND `planet_type` = 1
                LIMIT 1"
            );
            $exists = $existsResult ? $existsResult->fetch_assoc() : null;

            if ($exists) {
                continue;
            }

            $planetData = $sourcePlanet;
            unset($planetData['planet_id']);

            $planetData['planet_name'] = $sourcePlanet['planet_name'] . ' ' . $planetCounter;
            $planetData['planet_user_id'] = $userId;
            $planetData['planet_galaxy'] = $targetGalaxy;
            $planetData['planet_system'] = $system;
            $planetData['planet_planet'] = $position;
            $planetData['planet_last_update'] = time();
            $planetData['planet_type'] = 1;
            $planetData['planet_destroyed'] = 0;
            $planetData['planet_b_building'] = 0;
            $planetData['planet_b_building_id'] = '0';
            $planetData['planet_b_tech'] = 0;
            $planetData['planet_b_tech_id'] = 0;
            $planetData['planet_b_hangar'] = 0;
            $planetData['planet_b_hangar_id'] = '';
            $planetData['planet_last_jump_time'] = 0;

            $columns = array_keys($planetData);
            $values = array_map(
                static fn ($value): string => "'" . $db->real_escape_string((string) $value) . "'",
                array_values($planetData)
            );

            $insertPlanetSql = "INSERT INTO `{$prefix}planets` (`" . implode('`,`', $columns) . '`) VALUES (' . implode(',', $values) . ')';

            if (!$db->query($insertPlanetSql)) {
                throw new RuntimeException('Could not insert planet: ' . $db->error);
            }

            $planetId = (int) $db->insert_id;

            $buildingsSql = "INSERT INTO `{$prefix}buildings` SET
                `building_planet_id` = {$planetId},
                `building_metal_mine` = 40,
                `building_crystal_mine` = 40,
                `building_deuterium_sintetizer` = 40,
                `building_solar_plant` = 50,
                `building_metal_store` = 30,
                `building_crystal_store` = 30,
                `building_deuterium_tank` = 30";

            if (!$db->query($buildingsSql)) {
                throw new RuntimeException('Could not insert buildings: ' . $db->error);
            }

            if (!$db->query("INSERT INTO `{$prefix}ships` SET `ship_planet_id` = {$planetId}")) {
                throw new RuntimeException('Could not insert ships row: ' . $db->error);
            }

            if (!$db->query("INSERT INTO `{$prefix}defenses` SET `defense_planet_id` = {$planetId}")) {
                throw new RuntimeException('Could not insert defenses row: ' . $db->error);
            }

            $created++;
            $planetCounter++;
        }
    }

    if ($created < $toCreate) {
        throw new RuntimeException(
            "Only {$created} planets were created. Not enough free coordinates in galaxy {$targetGalaxy}."
        );
    }

    $db->commit();
    echo "Done. Created {$created} planets for user '{$username}'.";
    if ($applyExisting) {
        echo " Updated {$updatedExisting} existing planets.";
    }
    echo "\n";
} catch (Throwable $e) {
    $db->rollback();
    exit("Error: {$e->getMessage()}\n");
}
