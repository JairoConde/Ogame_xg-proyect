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

$table = DB_PREFIX . 'bot_state';

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
    `bot_user_id` INT(11) UNSIGNED NOT NULL,
    `bot_seed` BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
    `bot_archetype` VARCHAR(32) NOT NULL DEFAULT 'balanced',
    `bot_personality` VARCHAR(32) NOT NULL DEFAULT 'flotero',
    `bot_personal_targets` TEXT NULL,
    `bot_defense_recipe` TEXT NULL,
    `bot_research_order` TEXT NULL,
    `bot_planet_roles` TEXT NULL,
    `bot_quirks` TEXT NULL,
    `bot_current_focus` VARCHAR(32) NOT NULL DEFAULT 'eco',
    `bot_focus_started_at` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_focus_until` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_long_term_goal` VARCHAR(64) NULL DEFAULT NULL,
    `bot_long_term_progress` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_long_term_target` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_last_attacked_at` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_attack_reactive_until` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_last_action_at` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_total_actions` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_next_session_at` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_session_actions_left` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_last_session_end` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_active_window_start` TINYINT(3) UNSIGNED NOT NULL DEFAULT '0',
    `bot_active_window_end` TINYINT(3) UNSIGNED NOT NULL DEFAULT '23',
    `bot_actions_per_loop` TINYINT(3) UNSIGNED NOT NULL DEFAULT '2',
    `bot_skip_chance_x100` TINYINT(3) UNSIGNED NOT NULL DEFAULT '15',
    `bot_created_at` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    `bot_updated_at` INT(11) UNSIGNED NOT NULL DEFAULT '0',
    PRIMARY KEY (`bot_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

if (!$mysqli->query($createSql)) {
    fwrite(STDERR, "Failed creating table `{$table}`: {$mysqli->error}\n");
    $mysqli->close();
    exit(1);
}

echo "Created table `{$table}` successfully.\n";
$mysqli->close();
