<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!class_exists('mysqli')) {
    fwrite(STDERR, "The mysqli extension is not enabled in this PHP runtime.\n");
    fwrite(STDERR, "Run this script with the same PHP binary used by your web server.\n");
    exit(1);
}

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($mysqli->connect_error) {
    fwrite(STDERR, "Database connection failed: {$mysqli->connect_error}\n");
    exit(1);
}

$mysqli->set_charset('utf8');

$planetsTable = DB_PREFIX . 'planets';

// Columns whose value can overflow INT(11) once mines / energy reach high levels
// with high resource_multiplier or game_speed values.
$columns = [
    'planet_metal_perhour',
    'planet_crystal_perhour',
    'planet_deuterium_perhour',
    'planet_energy_used',
];

$checkSql = "SELECT COLUMN_NAME, DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME IN ('" . implode("','", $columns) . "')";

$checkStmt = $mysqli->prepare($checkSql);

if (!$checkStmt) {
    fwrite(STDERR, "Failed preparing column check: {$mysqli->error}\n");
    $mysqli->close();
    exit(1);
}

$dbName = DB_NAME;
$checkStmt->bind_param('ss', $dbName, $planetsTable);
$checkStmt->execute();
$result = $checkStmt->get_result();

$currentTypes = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $currentTypes[(string) $row['COLUMN_NAME']] = strtolower((string) $row['DATA_TYPE']);
    }
}
$checkStmt->close();

if (empty($currentTypes)) {
    fwrite(STDERR, "Could not read column metadata for `{$planetsTable}`.\n");
    $mysqli->close();
    exit(1);
}

$pendingChanges = [];
foreach ($columns as $column) {
    $type = $currentTypes[$column] ?? null;
    if ($type === null) {
        echo "Column `{$column}` not found in `{$planetsTable}`, skipping.\n";

        continue;
    }

    if ($type === 'bigint') {
        echo "Column `{$column}` already BIGINT. Nothing to do.\n";

        continue;
    }

    $pendingChanges[] = "MODIFY `{$column}` BIGINT(20) NOT NULL DEFAULT '0'";
}

if (empty($pendingChanges)) {
    echo "All target columns already widened. Migration not needed.\n";
    $mysqli->close();
    exit(0);
}

$alterSql = "ALTER TABLE `{$planetsTable}` " . implode(', ', $pendingChanges);

if (!$mysqli->query($alterSql)) {
    fwrite(STDERR, "Failed applying migration: {$mysqli->error}\n");
    $mysqli->close();
    exit(1);
}

echo "Migration applied successfully:\n";
foreach ($pendingChanges as $change) {
    echo "  - {$change}\n";
}

$mysqli->close();
