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

$shipsTable = DB_PREFIX . 'ships';
$columnName = 'ship_mining_drill';

$checkSql = 'SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?';

$checkStmt = $mysqli->prepare($checkSql);

if (!$checkStmt) {
    fwrite(STDERR, "Failed preparing column check: {$mysqli->error}\n");
    $mysqli->close();
    exit(1);
}

$dbName = DB_NAME;
$checkStmt->bind_param('sss', $dbName, $shipsTable, $columnName);
$checkStmt->execute();
$result = $checkStmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$checkStmt->close();

if (!$row) {
    fwrite(STDERR, "Could not check current schema state.\n");
    $mysqli->close();
    exit(1);
}

if ((int) $row['total'] > 0) {
    echo "Column `{$columnName}` already exists in `{$shipsTable}`. Nothing to do.\n";
    $mysqli->close();
    exit(0);
}

$alterSql = "ALTER TABLE `{$shipsTable}`
             ADD COLUMN `{$columnName}` INT(11) NOT NULL DEFAULT '0'
             AFTER `ship_battlecruiser`";

if (!$mysqli->query($alterSql)) {
    fwrite(STDERR, "Failed applying migration: {$mysqli->error}\n");
    $mysqli->close();
    exit(1);
}

echo "Migration applied successfully: added `{$columnName}` to `{$shipsTable}`.\n";
$mysqli->close();
