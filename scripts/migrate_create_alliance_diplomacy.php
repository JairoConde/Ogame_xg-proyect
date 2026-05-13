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

$table = DB_PREFIX . 'alliance_diplomacy';

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

$createSql = "CREATE TABLE `{$table}` (
    `diplomacy_id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `alliance_a` INT(11) UNSIGNED NOT NULL,
    `alliance_b` INT(11) UNSIGNED NOT NULL,
    `status` ENUM('war','nap','neutral') NOT NULL DEFAULT 'neutral',
    `since` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `expires_at` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    PRIMARY KEY (`diplomacy_id`),
    UNIQUE KEY `pair` (`alliance_a`, `alliance_b`),
    KEY `alliance_a` (`alliance_a`),
    KEY `alliance_b` (`alliance_b`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

if (!$mysqli->query($createSql)) {
    fwrite(STDERR, "Failed creating table `{$table}`: {$mysqli->error}\n");
    $mysqli->close();
    exit(1);
}

echo "Created table `{$table}` successfully.\n";
$mysqli->close();
