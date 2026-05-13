<?php

declare(strict_types=1);

// Widens additional schema columns to BIGINT(20) so the database has
// virtually unlimited headroom for late-game / bot-driven values.
//
// What this covers (and why):
//
//   buildings.building_*        : building level columns. Capped at 70 by
//                                 the bot today, but int(11) is fragile if
//                                 you ever lift the cap or hand-edit values.
//   research.research_*         : research level columns (except the
//                                 "current research" id field). Same logic
//                                 as buildings.
//   premium.premium_dark_matter : accumulated dark matter. int(10) signed
//                                 maxes at ~2.15B; long-lived accounts can
//                                 plausibly exceed it.
//   premium.premium_officier_*  : officer expiry timestamps. Stored as
//                                 epoch seconds, so int(11) signed already
//                                 has the Year-2038 problem.
//
// What this does NOT cover:
//
//   ships.ship_* and defenses.defense_* : handled by the dedicated script
//       scripts/migrate_widen_units_to_bigint.php (older, run that one too).
//   *_time / timestamp columns elsewhere: out of scope (Year-2038 audit
//       would touch dozens of columns, not done here).
//
// Idempotent: skips columns already at BIGINT.

require_once __DIR__ . '/bootstrap.php';

if (!class_exists('mysqli')) {
    fwrite(STDERR, "The mysqli extension is not enabled in this PHP runtime.\n");
    exit(1);
}

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Database connection failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8');

// Plan: each entry is "table => [column => 'BIGINT(20) NOT NULL DEFAULT 0']".
// Column-level definitions allow MODIFY to keep nullability/default consistent
// regardless of any prior local tweaks.
$plan = [
    DB_PREFIX . 'buildings' => [
        'building_metal_mine' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_crystal_mine' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_deuterium_sintetizer' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_solar_plant' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_fusion_reactor' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_robot_factory' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_nano_factory' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_hangar' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_metal_store' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_crystal_store' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_deuterium_tank' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_laboratory' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_terraformer' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_ally_deposit' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_missile_silo' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_mondbasis' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_phalanx' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'building_jump_gate' => "BIGINT(20) NOT NULL DEFAULT '0'",
    ],
    DB_PREFIX . 'research' => [
        'research_espionage_technology' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_computer_technology' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_weapons_technology' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_shielding_technology' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_armour_technology' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_energy_technology' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_hyperspace_technology' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_combustion_drive' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_impulse_drive' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_hyperspace_drive' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_laser_technology' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_ionic_technology' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_plasma_technology' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_intergalactic_research_network' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_astrophysics' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_cargo_optimization' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'research_graviton_technology' => "BIGINT(20) NOT NULL DEFAULT '0'",
    ],
    DB_PREFIX . 'premium' => [
        'premium_dark_matter' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'premium_officier_commander' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'premium_officier_admiral' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'premium_officier_engineer' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'premium_officier_geologist' => "BIGINT(20) NOT NULL DEFAULT '0'",
        'premium_officier_technocrat' => "BIGINT(20) NOT NULL DEFAULT '0'",
    ],
];

$totalChanges = 0;
foreach ($plan as $table => $columns) {
    $existingMap = [];
    $checkSql = 'SELECT COLUMN_NAME, DATA_TYPE
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?';
    $stmt = $mysqli->prepare($checkSql);
    if (!$stmt) {
        fwrite(STDERR, "Failed preparing column check for {$table}: {$mysqli->error}\n");
        $mysqli->close();
        exit(1);
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $existingMap[(string) $row['COLUMN_NAME']] = strtolower((string) $row['DATA_TYPE']);
        }
    }
    $stmt->close();

    if (empty($existingMap)) {
        echo "{$table}: table missing (skipped — install/seed the database first).\n";

        continue;
    }

    $modifications = [];
    foreach ($columns as $col => $definition) {
        if (!isset($existingMap[$col])) {
            echo "  - {$table}.{$col}: missing (skipped)\n";

            continue;
        }
        if ($existingMap[$col] === 'bigint') {
            echo "  - {$table}.{$col}: already bigint (skipped)\n";

            continue;
        }
        $modifications[] = "MODIFY `{$col}` {$definition}";
    }

    if (empty($modifications)) {
        echo "{$table}: no changes required.\n";

        continue;
    }

    $alter = "ALTER TABLE `{$table}` " . implode(', ', $modifications);
    if (!$mysqli->query($alter)) {
        fwrite(STDERR, "Failed altering {$table}: {$mysqli->error}\n");
        $mysqli->close();
        exit(1);
    }

    echo "{$table}: applied " . count($modifications) . " modification(s).\n";
    $totalChanges += count($modifications);
}

if ($totalChanges === 0) {
    echo "All target columns already widened. Nothing to do.\n";
} else {
    echo "Total columns widened: {$totalChanges}.\n";
}

$mysqli->close();
