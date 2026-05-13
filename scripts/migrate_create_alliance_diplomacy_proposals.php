<?php

declare(strict_types=1);

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

$table = DB_PREFIX . 'alliance_diplomacy_proposal';

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
    `proposal_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `from_alliance_id` INT UNSIGNED NOT NULL,
    `to_alliance_id` INT UNSIGNED NOT NULL,
    `kind` ENUM('nap','peace') NOT NULL,
    `payload` TEXT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    `expires_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`proposal_id`),
    UNIQUE KEY `uniq_from_to_kind` (`from_alliance_id`,`to_alliance_id`,`kind`),
    KEY `idx_to_expires` (`to_alliance_id`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "CREATE TABLE failed: {$mysqli->error}\n");
    exit(1);
}

echo "Created table `{$table}`.\n";
$mysqli->close();
exit(0);
