<?php

declare(strict_types=1);

// OPTIONAL safety migration: widen all ship_* / defense_* unit count columns
// from int(11) to bigint(20).
//
// Why: BOT_CAP_UNIT_PER_TYPE is 1.000.000.000 (1B). int(11) signed maxes at
//      ~2.15B, so the cap fits BUT leaves only a 2x safety margin. If you
//      ever raise the cap, or a third-party tool grants a planet > 2.15B
//      units of one type, the column will overflow and the planet update
//      will crash (same class of error you saw with planet_metal_perhour).
//
// Running this migration costs nothing in storage / performance terms (BIGINT
// uses 8 bytes vs 4 of INT, but these tables are tiny) and removes the risk
// permanently.
//
// Idempotent: skips columns already widened.

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

$plan = [
    DB_PREFIX . 'ships' => [
        'ship_small_cargo_ship', 'ship_big_cargo_ship', 'ship_light_fighter',
        'ship_heavy_fighter', 'ship_cruiser', 'ship_battleship',
        'ship_colony_ship', 'ship_recycler', 'ship_espionage_probe',
        'ship_bomber', 'ship_solar_satellite', 'ship_destroyer',
        'ship_deathstar', 'ship_battlecruiser', 'ship_mining_drill',
    ],
    DB_PREFIX . 'defenses' => [
        'defense_rocket_launcher', 'defense_light_laser', 'defense_heavy_laser',
        'defense_ion_cannon', 'defense_gauss_cannon', 'defense_plasma_turret',
        'defense_small_shield_dome', 'defense_large_shield_dome',
        'defense_anti-ballistic_missile', 'defense_interplanetary_missile',
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

    $modifications = [];
    foreach ($columns as $col) {
        if (!isset($existingMap[$col])) {
            echo "  - {$table}.{$col}: missing (skipped)\n";

            continue;
        }
        if ($existingMap[$col] === 'bigint') {
            echo "  - {$table}.{$col}: already bigint (skipped)\n";

            continue;
        }
        $modifications[] = "MODIFY `{$col}` BIGINT(20) NOT NULL DEFAULT '0'";
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
