<?php

declare(strict_types=1);

/**
 * Worker LLM: toma filas en estado pedido, llama a Ollama y deja respondido o fallido.
 *
 *   php scripts/llm_worker.php --once
 *   php scripts/llm_worker.php --forever --sleep 3
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bot_lib/llm_jobs.php';

// Sin TTY (Docker) la salida puede quedar bufferizada; sin esto `docker compose logs` a veces no muestra dumps hasta mucho después.
while (ob_get_level() > 0) {
    ob_end_flush();
}
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');

function argValue(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $i => $arg) {
        if ($arg === $name && isset($argv[$i + 1])) {
            return (string) $argv[$i + 1];
        }
    }

    return $default;
}

function hasFlag(array $argv, string $name): bool
{
    return in_array($name, $argv, true);
}

if (!class_exists('mysqli')) {
    fwrite(STDERR, "mysqli extension required.\n");
    exit(1);
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    fwrite(STDERR, "DB connect failed: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8');
$prefix = DB_PREFIX;

$argv = $_SERVER['argv'] ?? [];

$forever = hasFlag($argv, '--forever');
$once = hasFlag($argv, '--once');
if (!$forever && !$once) {
    $once = true;
}
$sleepSec = max(1, (int) (argValue($argv, '--sleep', '3') ?? '3'));

echo "llm_worker: prefix={$prefix} forever=" . ($forever ? '1' : '0') . " sleep={$sleepSec}s\n";

while (true) {
    if (!botLlmIsEnabled()) {
        echo date('c') . " llm_worker: BOT_LLM_ENABLED off, idle\n";
        sleep($sleepSec);
        if (!$forever) {
            break;
        }

        continue;
    }
    if (!botLlmJobTableExists($db, $prefix)) {
        fwrite(STDERR, "Table {$prefix}bot_llm_job missing. Run: php scripts/migrate_create_bot_llm_job.php\n");
        sleep($sleepSec);
        if (!$forever) {
            break;
        }

        continue;
    }

    $now = time();
    $logs = botLlmWorkerProcessOne($db, $prefix, $now);
    foreach ($logs as $line) {
        echo date('c') . " {$line}\n";
    }
    if ($logs === []) {
        echo date('c') . " llm_worker: sin trabajos en estado pedido\n";
        if ($forever) {
            sleep($sleepSec);
        }
    }
    if (!$forever) {
        break;
    }
}

$db->close();
