<?php

declare(strict_types=1);

/**
 * Cola asíncrona bot ↔ LLM (Ollama vía worker).
 *
 * Estados: pedido → procesando → respondido → done | fallido
 *
 * Uso: php scripts/migrate_create_bot_llm_job.php
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

$table = DB_PREFIX . 'bot_llm_job';

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
    `job_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bot_user_id` INT UNSIGNED NOT NULL,
    `skill` VARCHAR(64) NOT NULL,
    `dedupe_key` VARCHAR(160) NOT NULL,
    `status` ENUM('pedido','procesando','respondido','done','fallido') NOT NULL DEFAULT 'pedido',
    `request_payload` MEDIUMTEXT NOT NULL,
    `response_payload` MEDIUMTEXT NULL,
    `error_text` VARCHAR(1024) NULL,
    `claim_nonce` CHAR(32) NULL DEFAULT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    `updated_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`job_id`),
    KEY `idx_status_job` (`status`, `job_id`),
    KEY `idx_bot_status` (`bot_user_id`, `status`),
    KEY `idx_dedupe_lookup` (`bot_user_id`, `skill`, `dedupe_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!$mysqli->query($sql)) {
    fwrite(STDERR, "CREATE TABLE failed: {$mysqli->error}\n");
    exit(1);
}

echo "Created table `{$table}`.\n";
$mysqli->close();
exit(0);
