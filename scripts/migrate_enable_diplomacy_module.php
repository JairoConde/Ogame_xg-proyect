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

$tbl = DB_PREFIX . 'options';

$res = $mysqli->query("SELECT `option_value` FROM `{$tbl}` WHERE `option_name` = 'modules' LIMIT 1");
if (!$res) {
    fwrite(STDERR, "Failed to read modules option: {$mysqli->error}\n");
    $mysqli->close();
    exit(1);
}
$row = $res->fetch_assoc();
$res->free();

if (!is_array($row)) {
    fwrite(STDERR, "modules option missing in `{$tbl}`. Nothing to do.\n");
    $mysqli->close();
    exit(1);
}

$current = (string) $row['option_value'];
$parts = $current === '' ? [] : explode(';', $current);
$wanted = 26; // 0..25 (diplomacy is module 25)
if (count($parts) >= $wanted) {
    echo 'modules already has ' . count($parts) . " entries. Nothing to do.\n";
    $mysqli->close();
    exit(0);
}

while (count($parts) < $wanted) {
    $parts[] = '1';
}
$new = implode(';', $parts);
$ok = $mysqli->query("UPDATE `{$tbl}` SET `option_value` = '{$mysqli->real_escape_string($new)}' WHERE `option_name` = 'modules' LIMIT 1");
if (!$ok) {
    fwrite(STDERR, "Failed to update modules option: {$mysqli->error}\n");
    $mysqli->close();
    exit(1);
}

echo 'Modules option grown from ' . count(explode(';', $current)) . ' to ' . count($parts) . " entries (diplomacy enabled).\n";
$mysqli->close();
