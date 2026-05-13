<?php

declare(strict_types=1);

/**
 * Calor bilateral dirigido: cuánto "tensión" siente observer_user_id hacia target_user_id.
 * 1 = neutro; >1 enemistad; <1 amistad. Límites 0.1 … 10.
 *
 * Uso: php scripts/migrate_create_bot_pair_heat.php
 */

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

$table = DB_PREFIX . 'bot_pair_heat';

$exists = false;
$check = $mysqli->prepare(
    'SELECT COUNT(*) AS total FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
);
if ($check) {
    $dbName = DB_NAME;
    $check->bind_param('ss', $dbName, $table);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $exists = ((int) ($row['total'] ?? 0)) > 0;
    $check->close();
}

if ($exists) {
    echo "Table `{$table}` already exists. Nothing to do.\n";
    $mysqli->close();
    exit(0);
}

$sql = "CREATE TABLE `{$table}` (
    `observer_user_id` INT UNSIGNED NOT NULL,
    `target_user_id` INT UNSIGNED NOT NULL,
    `heat` DECIMAL(6,2) NOT NULL DEFAULT 1.00,
    `updated_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`observer_user_id`, `target_user_id`),
    KEY `idx_observer_updated` (`observer_user_id`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "CREATE TABLE failed: {$mysqli->error}\n");
    exit(1);
}

echo "Created table `{$table}`.\n";
$mysqli->close();
exit(0);
