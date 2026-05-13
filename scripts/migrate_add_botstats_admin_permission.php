<?php

declare(strict_types=1);

/**
 * Adds the `botstats` admin permission key for existing universes.
 *
 *   php scripts/migrate_add_botstats_admin_permission.php
 *
 * Safe to run multiple times.
 */

require_once __DIR__ . '/bootstrap.php';

if (!class_exists('mysqli')) {
    fwrite(STDERR, "mysqli extension required.\n");
    exit(1);
}

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Database connection failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8');

$table = DB_PREFIX . 'options';
$res = $mysqli->query(
    "SELECT `option_value` FROM `{$table}` WHERE `option_name` = 'admin_permissions' LIMIT 1"
);
if (!$res) {
    fwrite(STDERR, "Query failed: {$mysqli->error}\n");
    exit(1);
}
$row = $res->fetch_assoc();
$res->free();
if (!is_array($row)) {
    fwrite(STDERR, "admin_permissions option not found.\n");
    exit(1);
}

$perm = json_decode((string) $row['option_value'], true);
if (!is_array($perm)) {
    fwrite(STDERR, "admin_permissions is not valid JSON.\n");
    exit(1);
}

if (isset($perm['botstats'])) {
    echo "admin_permissions already contains botstats — nothing to do.\n";
    exit(0);
}

$perm['botstats'] = ['1' => 0, '2' => 0, '3' => 1];
$newJson = json_encode($perm, JSON_THROW_ON_ERROR);
$esc = $mysqli->real_escape_string($newJson);
$ok = $mysqli->query("UPDATE `{$table}` SET `option_value` = '{$esc}' WHERE `option_name` = 'admin_permissions' LIMIT 1");
if (!$ok) {
    fwrite(STDERR, "UPDATE failed: {$mysqli->error}\n");
    exit(1);
}

echo "Added botstats to admin_permissions (GO=0, SGO=0, ADMIN=1).\n";
echo "Open Admin → Permissions to adjust GO/SGO access if needed.\n";
