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

$prefix = DB_PREFIX;
$dbName = DB_NAME;

/**
 * @return bool true if the column exists already.
 */
$columnExists = static function (mysqli $m, string $db, string $table, string $col): bool {
    $stmt = $m->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sss', $db, $table, $col);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int) ($row['total'] ?? 0)) > 0;
};

$tableExists = static function (mysqli $m, string $db, string $table): bool {
    $stmt = $m->prepare(
        'SELECT COUNT(*) AS total FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $db, $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int) ($row['total'] ?? 0)) > 0;
};

$diplomacyTable = $prefix . 'alliance_diplomacy';
if (!$tableExists($mysqli, $dbName, $diplomacyTable)) {
    fwrite(
        STDERR,
        "Table `{$diplomacyTable}` does not exist. Run scripts/migrate_create_alliance_diplomacy.php first.\n"
    );
    $mysqli->close();
    exit(1);
}

// ----- Columns on existing diplomacy table ----------------------------------
$alters = [
    'declared_by' => 'ADD COLUMN `declared_by` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `expires_at`',
    'damage_a_to_b' => 'ADD COLUMN `damage_a_to_b` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `declared_by`',
    'damage_b_to_a' => 'ADD COLUMN `damage_b_to_a` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `damage_a_to_b`',
    'last_action_at' => 'ADD COLUMN `last_action_at` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `damage_b_to_a`',
];
$toAdd = [];
foreach ($alters as $col => $clause) {
    if (!$columnExists($mysqli, $dbName, $diplomacyTable, $col)) {
        $toAdd[] = $clause;
    }
}
if (!empty($toAdd)) {
    $sql = "ALTER TABLE `{$diplomacyTable}` " . implode(', ', $toAdd);
    if (!$mysqli->query($sql)) {
        fwrite(STDERR, "ALTER on `{$diplomacyTable}` failed: {$mysqli->error}\n");
        $mysqli->close();
        exit(1);
    }
    echo 'Added columns: ' . implode(', ', array_keys(array_filter($alters, static function ($c, $name) use ($mysqli, $dbName, $diplomacyTable, $columnExists) {
        return $columnExists($mysqli, $dbName, $diplomacyTable, $name);
    }, ARRAY_FILTER_USE_BOTH))) . " on `{$diplomacyTable}`.\n";
} else {
    echo "Columns already present on `{$diplomacyTable}`.\n";
}

// ----- xgp_alliance_diplomacy_pressure --------------------------------------
$pressureTable = $prefix . 'alliance_diplomacy_pressure';
if (!$tableExists($mysqli, $dbName, $pressureTable)) {
    $sql = "CREATE TABLE `{$pressureTable}` (
        `pressure_id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `victim_alliance_id` INT(11) UNSIGNED NOT NULL,
        `attacker_alliance_id` INT(11) UNSIGNED NOT NULL,
        `pressure` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        `last_attack_at` INT(11) UNSIGNED NOT NULL DEFAULT 0,
        `last_decay_at` INT(11) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`pressure_id`),
        UNIQUE KEY `pair` (`victim_alliance_id`, `attacker_alliance_id`),
        KEY `victim` (`victim_alliance_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
    if (!$mysqli->query($sql)) {
        fwrite(STDERR, "Failed creating `{$pressureTable}`: {$mysqli->error}\n");
        $mysqli->close();
        exit(1);
    }
    echo "Created table `{$pressureTable}`.\n";
} else {
    echo "Table `{$pressureTable}` already exists.\n";
}

// ----- xgp_alliance_diplomacy_cooldown --------------------------------------
$cooldownTable = $prefix . 'alliance_diplomacy_cooldown';
if (!$tableExists($mysqli, $dbName, $cooldownTable)) {
    $sql = "CREATE TABLE `{$cooldownTable}` (
        `cooldown_id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `alliance_id` INT(11) UNSIGNED NOT NULL,
        `kind` ENUM('peace_block','nap_block','war_block') NOT NULL,
        `until_at` INT(11) UNSIGNED NOT NULL,
        `reason` VARCHAR(64) NULL,
        PRIMARY KEY (`cooldown_id`),
        UNIQUE KEY `pair` (`alliance_id`, `kind`),
        KEY `alliance` (`alliance_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
    if (!$mysqli->query($sql)) {
        fwrite(STDERR, "Failed creating `{$cooldownTable}`: {$mysqli->error}\n");
        $mysqli->close();
        exit(1);
    }
    echo "Created table `{$cooldownTable}`.\n";
} else {
    echo "Table `{$cooldownTable}` already exists.\n";
}

// ----- xgp_alliance_diplomacy_log -------------------------------------------
$logTable = $prefix . 'alliance_diplomacy_log';
if (!$tableExists($mysqli, $dbName, $logTable)) {
    $sql = "CREATE TABLE `{$logTable}` (
        `log_id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `from_alliance_id` INT(11) UNSIGNED NOT NULL,
        `to_alliance_id` INT(11) UNSIGNED NOT NULL,
        `actor_user_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
        `action` VARCHAR(32) NOT NULL,
        `payload` TEXT NULL,
        `created_at` INT(11) UNSIGNED NOT NULL,
        PRIMARY KEY (`log_id`),
        KEY `from` (`from_alliance_id`),
        KEY `to` (`to_alliance_id`),
        KEY `created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
    if (!$mysqli->query($sql)) {
        fwrite(STDERR, "Failed creating `{$logTable}`: {$mysqli->error}\n");
        $mysqli->close();
        exit(1);
    }
    echo "Created table `{$logTable}`.\n";
} else {
    echo "Table `{$logTable}` already exists.\n";
}

echo "Done.\n";
$mysqli->close();
